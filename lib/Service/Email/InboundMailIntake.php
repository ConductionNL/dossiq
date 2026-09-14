<?php

/**
 * Dossiq Inbound Mail Intake
 *
 * What happens to a message between the mailbox and a case: the pipeline, the
 * authentication verdict, the case type's policy, and the log entry that says
 * which of those decided.
 *
 * 🔴 NOTHING IS DROPPED, EVER (design D-5). `InboundEmailJob` used to
 * `continue` on an unmatched message with no log line at all, so an instance
 * losing every inbound aanvraag looked exactly like an instance receiving none.
 * Every message that walks through here leaves as one of six recorded things: a
 * case, a quarantine, an inbox entry, a refusal, a forward or a move. There is
 * no seventh branch and there is no early return that writes nothing.
 *
 * THE ORDER IS DELIBERATE and each step is here because the one before it
 * cannot answer its question:
 *
 *  1. The filters, which refuse the machine-generated mail nothing should have
 *     to reason about.
 *  2. The authentication verdict, which needs the raw source and therefore
 *     costs a fetch, so it runs only on what survived step 1.
 *  3. The case the subject names, GUARDED by the threading result, because a
 *     subject tag alone is what let a bezwaar be filed on somebody else's case.
 *  4. The case type's policy, which decides what a failing verdict means to
 *     this kind of case rather than to the instance.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\EmailArchivalService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides what one inbound message becomes, and records it.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — this is the one class that
 *  is supposed to know every step of the intake path, which is what makes every
 *  other class in it able to know only its own.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class InboundMailIntake {

	/**
	 * The subject-tag pattern carrying a case identifier.
	 */
	public const CASE_NUMBER_PATTERN = '/\[([A-Z]+-\d{4}-\d{4,6})\]/';

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface  $gateway     The mail gateway.
	 * @param FilterPipeline        $pipeline    The declared filter order.
	 * @param AuthenticationVerdict $verdicts    The four authentication results.
	 * @param ThreadingCheck        $threading   The threading claim checker.
	 * @param IntakePolicy          $policy      What a failing verdict means per case type.
	 * @param IntakeLog             $log         The intake log.
	 * @param CaseEmailRepository   $cases       Resolves a case identifier to its id.
	 * @param UnmatchedMailIntake   $unmatched   What a message nobody claims becomes.
	 * @param EmailArchivalService  $archival    Files the message on its case.
	 * @param LoggerInterface       $logger      Logger.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly FilterPipeline $pipeline,
		private readonly AuthenticationVerdict $verdicts,
		private readonly ThreadingCheck $threading,
		private readonly IntakePolicy $policy,
		private readonly IntakeLog $log,
		private readonly CaseEmailRepository $cases,
		private readonly UnmatchedMailIntake $unmatched,
		private readonly EmailArchivalService $archival,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether intake can run at all.
	 *
	 * @return boolean True when a mail app is installed and answering.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isAvailable(): bool {
		return $this->gateway->isAvailable();
	}//end isAvailable()

	/**
	 * Take one batch of messages through the whole path.
	 *
	 * @param array<int, array<string, mixed>> $rows      The message rows.
	 * @param integer                          $accountId The account they came from.
	 * @param string                           $mailbox   The folder they sit in.
	 *
	 * @return array<string, int> A count per outcome.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function processBatch(array $rows, int $accountId, string $mailbox): array {
		$counts = [];
		foreach ($rows as $row) {
			try {
				$outcome = $this->process(
					message: $this->hydrate(row: $row, accountId: $accountId, mailbox: $mailbox)
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: intake failed on one message and the rest of the batch continues',
					['error' => $e->getMessage()]
				);
				continue;
			}

			$counts[$outcome] = (($counts[$outcome] ?? 0) + 1);
		}//end foreach

		return $counts;
	}//end processBatch()

	/**
	 * Take one message through the whole path.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return string One of the {@see IntakeLog} outcome values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function process(InboundMessage $message): string {
		$verdict = $this->pipeline->run(message: $message);
		if ($verdict->outcome === FilterOutcome::REJECT) {
			$this->log->record(
				message: $message,
				verdict: $verdict,
				results: AuthenticationVerdict::unknown(),
				outcome: IntakeLog::OUTCOME_REFUSED,
				reason: $verdict->reason
			);
			return IntakeLog::OUTCOME_REFUSED;
		}

		$results = $this->verdicts->forMessage(message: $message);

		if ($verdict->outcome === FilterOutcome::QUARANTINE) {
			$this->log->record(
				message: $message,
				verdict: $verdict,
				results: $results,
				outcome: IntakeLog::OUTCOME_QUARANTINED,
				reason: $verdict->reason
			);
			return IntakeLog::OUTCOME_QUARANTINED;
		}

		if ($verdict->outcome === FilterOutcome::FORWARD) {
			// A filter that wants a message sent on says so, and the act itself
			// belongs to BounceAction. Recorded as an inbox entry rather than a
			// forward until somebody performs it, because a forward nobody sent
			// is not a forward.
			$this->log->record(
				message: $message,
				verdict: $verdict,
				results: $results,
				outcome: IntakeLog::OUTCOME_INBOX,
				reason: 'A filter asked for this to be sent on to ' . $verdict->forwardTo
					. '. Reason: ' . $verdict->reason
			);
			return IntakeLog::OUTCOME_INBOX;
		}

		return $this->place(message: $message, verdict: $verdict, results: $results);
	}//end process()

	/**
	 * Put an accepted message where it belongs.
	 *
	 * @param InboundMessage        $message The message.
	 * @param FilterVerdict         $verdict The pipeline's verdict.
	 * @param array<string, string> $results The four authentication results.
	 *
	 * @return string One of the {@see IntakeLog} outcome values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function place(InboundMessage $message, FilterVerdict $verdict, array $results): string {
		$caseId = $this->matchedCaseId(message: $message, results: $results);
		$caseTypeId = $this->unmatched->fallbackCaseTypeId();
		if ($caseId !== '') {
			$caseTypeId = $this->caseTypeOf(caseId: $caseId);
		}

		$policy = $this->policy->outcomeFor(
			policy: $this->policy->forCaseType(caseTypeId: $caseTypeId),
			results: $results
		);

		if ($policy === IntakePolicy::REFUSE) {
			$this->log->record(
				message: $message,
				verdict: $verdict,
				results: $results,
				outcome: IntakeLog::OUTCOME_REFUSED,
				reason: 'The case type refuses a message whose sender could not be authenticated.',
				caseId: $caseId
			);
			return IntakeLog::OUTCOME_REFUSED;
		}

		if ($policy === IntakePolicy::QUARANTINE) {
			$this->log->record(
				message: $message,
				verdict: $verdict,
				results: $results,
				outcome: IntakeLog::OUTCOME_QUARANTINED,
				reason: 'The case type holds a message whose sender could not be authenticated '
					. 'until the intake role releases it.',
				caseId: $caseId
			);
			return IntakeLog::OUTCOME_QUARANTINED;
		}

		return $this->fileOnCase(
			message: $message,
			verdict: $verdict,
			results: $results,
			caseId: $caseId
		);
	}//end place()

	/**
	 * File an accepted message on a case, or in the intake inbox.
	 *
	 * @param InboundMessage        $message The message.
	 * @param FilterVerdict         $verdict The pipeline's verdict.
	 * @param array<string, string> $results The four authentication results.
	 * @param string                $caseId  The case the subject named, or ''.
	 *
	 * @return string One of the {@see IntakeLog} outcome values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function fileOnCase(
		InboundMessage $message,
		FilterVerdict $verdict,
		array $results,
		string $caseId,
	): string {
		$onCase = $caseId;
		$reason = 'Linked to the case its subject names.';
		if ($onCase === '') {
			$filed = $this->unmatched->caseFor(message: $message->toArray());
			if ($filed !== null && $filed !== '') {
				$onCase = $filed;
				$reason = 'Filed as a new case of the fallback case type.';
			}
		}

		if ($onCase === '') {
			// 🔴 THE ONE BRANCH THAT USED TO BE A SILENT `continue`. A message
			// matching no case and no case type lands in the intake inbox with
			// its verdict on it, and somebody has to empty that inbox. A
			// message that vanished is not a problem anyone can see.
			$this->log->record(
				message: $message,
				verdict: $verdict,
				results: $results,
				outcome: IntakeLog::OUTCOME_INBOX,
				reason: 'No case and no case type claimed this message, so it is waiting in the intake inbox.'
			);
			return IntakeLog::OUTCOME_INBOX;
		}

		try {
			$this->archival->archiveLinkedEmail(caseId: $onCase, metadata: $message->toArray());
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: filing an accepted message on its case failed',
				['case' => $onCase, 'error' => $e->getMessage()]
			);
		}

		$this->log->record(
			message: $message,
			verdict: $verdict,
			results: $results,
			outcome: IntakeLog::OUTCOME_CASE,
			reason: $reason,
			caseId: $onCase
		);

		return IntakeLog::OUTCOME_CASE;
	}//end fileOnCase()

	/**
	 * The case the subject names, when the threading result allows it.
	 *
	 * 🔴 THIS IS THE GUARD THE FORGERY WALKED THROUGH. A `fail` threading
	 * result means the message claims to reply to something this account never
	 * sent, and a case tag in the subject of such a message is a claim about
	 * somebody else's case.
	 *
	 * @param InboundMessage        $message The message.
	 * @param array<string, string> $results The four authentication results.
	 *
	 * @return string The case id, or '' when there is none or it is not allowed.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function matchedCaseId(InboundMessage $message, array $results): string {
		$identifier = $this->identifierFromSubject(subject: $message->subject);
		if ($identifier === '') {
			return '';
		}

		$threadingResult = ($results['threading'] ?? AuthenticationResult::UNAVAILABLE);
		if ($this->threading->allowsSubjectTagLink(threadingResult: $threadingResult) === false) {
			$this->logger->warning(
				'Dossiq: a message naming case {identifier} claims a reply this account never sent, '
					. 'so the subject tag was not honoured',
				['identifier' => $identifier, 'sender' => $message->senderAddress()]
			);
			return '';
		}

		$caseId = $this->cases->findCaseIdByIdentifier($identifier);
		if ($caseId === null) {
			return '';
		}

		return $caseId;
	}//end matchedCaseId()

	/**
	 * The bare case identifier inside a subject tag.
	 *
	 * The tag carries a prefix the case does not: the identifier OpenRegister
	 * materialises is the bare `2026-000142`.
	 *
	 * @param string $subject The subject header.
	 *
	 * @return string The identifier, or '' when there is no tag.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function identifierFromSubject(string $subject): string {
		if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) !== 1) {
			return '';
		}

		if (preg_match('/(\d{4}-\d{4,6})$/', $matches[1], $bare) === 1) {
			return $bare[1];
		}

		return $matches[1];
	}//end identifierFromSubject()

	/**
	 * Build one message, with its raw source read in.
	 *
	 * @param array<string, mixed> $row       The gateway's row.
	 * @param integer              $accountId The account.
	 * @param string               $mailbox   The folder.
	 *
	 * @return InboundMessage The message.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function hydrate(array $row, int $accountId, string $mailbox): InboundMessage {
		$message = InboundMessage::fromRow(row: $row, accountId: $accountId, mailbox: $mailbox);
		$source = $this->gateway->source($message->accountId, $message->mailbox, $message->uid);
		if ($source === '') {
			return $message;
		}

		return $message->withSource(source: $source);
	}//end hydrate()

	/**
	 * The case type one case is of.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string The case type id, or ''.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function caseTypeOf(string $caseId): string {
		try {
			$record = $this->cases->loadCaseRecord($caseId);
		} catch (Throwable $e) {
			// A case that cannot be read gets the default policy, which is
			// quarantine. Intake runs on a cron, and a case deleted between the
			// match and this read must not stop the mailbox being emptied.
			$this->logger->debug(
				'Dossiq: the case behind an intake policy could not be read',
				['case' => $caseId, 'error' => $e->getMessage()]
			);
			return '';
		}

		$caseType = ($record['caseType'] ?? '');
		if (is_array($caseType) === true) {
			return (string)($caseType['id'] ?? '');
		}

		return (string)$caseType;
	}//end caseTypeOf()
}//end class
