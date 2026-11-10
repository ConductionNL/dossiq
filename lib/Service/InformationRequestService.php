<?php

/**
 * Dossiq InformationRequestService.
 *
 * Asking the applicant for something and stopping the clock are one act.
 *
 * Awb 4:5 says the clock stops when you ask, and joining the ask to the stop is
 * what makes it correct. Sending the letter and registering the pause were two
 * calls, so a handler could send the letter and forget the pause, or pause and
 * never write. Both cases end with a date nobody can defend.
 *
 * 🔴 IF THE LETTER DOES NOT GO OUT, THE TERM IS NOT SUSPENDED.
 * A suspended clock with no letter is a case where the citizen was never asked,
 * and that is worse than an unsuspended one: the applicant waits for a request
 * that never came while the gemeente's own deadline quietly stands still. So the
 * send happens first and the suspension only follows a send that worked. A
 * failed send is recorded on the term, so the case shows what happened instead
 * of showing nothing.
 *
 * Receiving the aanvulling is the mirror: one act that resumes the clock and
 * records what came in.
 *
 * WHERE THIS CONTINUES. `aanvullingsverzoek-as-a-record` gives the request its
 * own object, with the typed reason, the chases and the list filter for cases
 * waiting on an applicant. This change builds the ACT and records it as one
 * TermijnGebeurtenis carrying what was asked, when, and the suspension. That
 * change turns the record into an object; it does not need to rebuild the act.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
use Psr\Log\LoggerInterface;

/**
 * One act that asks, records and suspends, and one that resumes (REQ-TERM-067).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see TermKind} is a vocabulary: four
 * constants and four pure predicates over an array, with no state, no I/O and
 * nothing to inject. Making it an instance would add a constructor dependency
 * to every class that names a kind, to hide a `::` behind a `->`.
 */
class InformationRequestService {
	/**
	 * The template the request is rendered from.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'hersteltermijn-request';

	/**
	 * The event type carrying a request that went out.
	 *
	 * @var string
	 */
	public const EVENT_REQUESTED = 'information-requested';

	/**
	 * The event type carrying the aanvulling that came back.
	 *
	 * @var string
	 */
	public const EVENT_RECEIVED = 'information-received';

	/**
	 * The event type carrying a request that could not be sent.
	 *
	 * @var string
	 */
	public const EVENT_FAILED = 'information-request-failed';

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService The term instances on a case.
	 * @param DeadlinePauseService $pause Opschorten and hervatten (Awb 4:5, 4:15).
	 * @param TermijnNotificationService $notifications The letter, rendered and routed.
	 * @param CaseTermsService $terms The one place that turns a day count into a date
	 *        on the administered calendar.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly DeadlinePauseService $pause,
		private readonly TermijnNotificationService $notifications,
		private readonly CaseTermsService $terms,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask the applicant for what is missing, and stop the clock.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int, string> $items What is missing, one line each.
	 * @param string $recipient Who to send it to.
	 * @param int $durationDays How long the applicant is given.
	 * @param string $rationale Why the case cannot be decided yet.
	 * @param string $pauseReason The declared reason the suspension is registered
	 *        under. It is what carries the reminder schedule, so an ask that names
	 *        one gets chased and an ask that names none behaves as it always did.
	 *
	 * @return array{sent: bool, suspended: bool, instance: array<string, mixed>,
	 *               record: array<string, mixed>|null, error: string}
	 *
	 * @throws RefusedException When the case carries no running term to suspend,
	 *         the case type refuses a suspension this long, or it does not declare
	 *         the reason named.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function ask(
		string $caseId,
		array $items,
		string $recipient,
		int $durationDays,
		string $rationale = '',
		string $pauseReason = '',
	): array {
		$run = new InformationRequestRun(caseId: $caseId, instance: $this->runningTermFor(caseId: $caseId));
		$run->items = $this->cleanItems(items: $items);
		$this->refuseEmptyRequest(run: $run);
		$run->recipient = $recipient;
		$run->days = max(1, $durationDays);
		$run->rationale = $rationale;
		$run->pauseReason = $pauseReason;

		$failure = $this->sendRequestLetter(run: $run);
		if ($failure !== null) {
			return $failure;
		}

		return $this->suspendTerm(run: $run);
	}//end ask()

	/**
	 * Refuse a request that, once cleaned, names nothing.
	 *
	 * @param InformationRequestRun $run The request being made.
	 *
	 * @return void
	 *
	 * @throws RefusedException When nothing is asked for.
	 */
	private function refuseEmptyRequest(InformationRequestRun $run): void {
		if (count($run->items) === 0) {
			throw new RefusedException(
				rule: 'information-request-names-nothing',
				sentence: 'Name at least one thing the applicant has to send, so the request can be answered.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end refuseEmptyRequest()

	/**
	 * Send the letter; when it does not go out, record that and return the
	 * not-sent result, otherwise return null.
	 *
	 * @param InformationRequestRun $run The request being made.
	 *
	 * @return array<string, mixed>|null The not-sent result, or null once the letter went out.
	 */
	private function sendRequestLetter(InformationRequestRun $run): ?array {
		$run->moment = new DateTimeImmutable();

		// The date in the letter is the date the applicant is held to, so it
		// lands on the administered calendar like every other term end. It is
		// computed through CaseTermsService rather than here, so this file does
		// no date arithmetic of its own and cannot drift from the pause the
		// next step registers.
		$due = $this->terms->endAfter(start: $run->moment, days: $run->days);

		try {
			$this->notifications->sendTermijnNotification(
				self::TEMPLATE,
				$run->instanceId,
				$run->recipient,
				['items' => $run->items, 'pauseDeadline' => $due, 'case' => $run->caseId],
			);
		} catch (\Throwable $e) {
			// Nothing went out, so nothing is suspended (Awb 4:15 lid 1 sub a,
			// REQ-WRN-004). The sender's not-sent result travels to the handler.
			return $this->recordFailedSend(run: $run, failure: $e);
		}

		return null;
	}//end sendRequestLetter()

	/**
	 * The letter went out, so the clock stops and the act is recorded. This
	 * order is the requirement: a suspension that happens first survives a
	 * send that fails.
	 *
	 * @param InformationRequestRun $run The request whose letter went out.
	 *
	 * @return array{sent: bool, suspended: bool, instance: array<string, mixed>,
	 *               record: array<string, mixed>|null, error: string}
	 */
	private function suspendTerm(InformationRequestRun $run): array {
		$why = $this->suspensionReason(rationale: $run->rationale);
		$suspended = $this->pause->registerPauze(
			termInstanceId: $run->instanceId,
			durationDays: $run->days,
			rationale: ($why . ' (Awb 4:5)'),
			pauseReason: $run->pauseReason,
		);
		$record = $this->recordRequested(run: $run, why: $why);

		return [
			'sent' => true,
			'suspended' => true,
			'instance' => $suspended,
			'record' => $record,
			'error' => '',
		];
	}//end suspendTerm()

	/**
	 * The reason a suspension is registered with: the handler's own, or the
	 * standard one when they gave none.
	 *
	 * @param string $rationale The handler's rationale, possibly empty.
	 *
	 * @return string
	 */
	private function suspensionReason(string $rationale): string {
		if ($rationale !== '') {
			return $rationale;
		}

		return 'Aanvulling gevraagd';
	}//end suspensionReason()

	/**
	 * Record the request that went out with the suspension it caused, and log it.
	 *
	 * @param InformationRequestRun $run The request whose letter went out.
	 * @param string                $why The reason the suspension carries.
	 *
	 * @return array<string, mixed>|null The recorded event.
	 */
	private function recordRequested(InformationRequestRun $run, string $why): ?array {
		$record = $this->termService->recordEvent(
			termInstanceId: $run->instanceId,
			type: self::EVENT_REQUESTED,
			basis: 'Awb 4:5',
			rationale: $why,
			daysImpact: $run->days,
			moment: $run->moment,
			items: $run->items,
		);

		$this->logger->info(
			'Dossiq term: the applicant was asked and the term was suspended in one act',
			['case' => $run->caseId, 'instance' => $run->instanceId, 'items' => count($run->items)]
		);

		return $record;
	}//end recordRequested()

	/**
	 * The aanvulling arrived: resume the clock and record what came in.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int, string> $items What came in, one line each.
	 * @param DateTimeImmutable|null $when When it arrived (default now).
	 *
	 * @return array{resumed: bool, instance: array<string, mixed>, record: array<string, mixed>|null}
	 *
	 * @throws RefusedException When the case carries no suspended term to resume.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	public function receive(string $caseId, array $items, ?DateTimeImmutable $when = null): array {
		$moment = ($when ?? new DateTimeImmutable());
		$instance = $this->suspendedTermFor(caseId: $caseId);
		$instanceId = (string)($instance['id'] ?? '');

		$resumed = $this->pause->resumeAfterPauze(termInstanceId: $instanceId, aanvullingDatum: $moment);

		$record = $this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: self::EVENT_RECEIVED,
			basis: 'Awb 4:15',
			rationale: 'Aanvulling ontvangen',
			daysImpact: 0,
			moment: $moment,
			items: $this->cleanItems(items: $items),
		);

		return ['resumed' => true, 'instance' => $resumed, 'record' => $record];
	}//end receive()

	/**
	 * Record a send that failed, leaving the clock running.
	 *
	 * @param InformationRequestRun $run     The request whose letter did not go out.
	 * @param \Throwable            $failure What went wrong.
	 *
	 * @return array{sent: bool, suspended: bool, instance: array<string, mixed>,
	 *               record: array<string, mixed>|null, error: string, reasonCode: string,
	 *               notice: array<string, mixed>}
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-term-is-suspended-only-by-a-request-for-information-that-went-out-req-wrn-004
	 */
	private function recordFailedSend(InformationRequestRun $run, \Throwable $failure): array {
		$notice = $this->noticeOf(failure: $failure);
		$error = $failure->getMessage();
		$record = $this->recordNotSent(run: $run, error: $error);

		return [
			'sent' => false,
			'suspended' => false,
			'instance' => $run->instance,
			'record' => $record,
			'error' => $error,
			'reasonCode' => (string)($notice['reasonCode'] ?? 'send-failed'),
			'notice' => $notice,
		];
	}//end recordFailedSend()

	/**
	 * The sender's not-sent result a failure carries, or [] when it kept none.
	 *
	 * @param \Throwable $failure What went wrong.
	 *
	 * @return array<string, mixed>
	 */
	private function noticeOf(\Throwable $failure): array {
		if ($failure instanceof NoticeNotSentException) {
			return $failure->getDelivery();
		}

		return [];
	}//end noticeOf()

	/**
	 * Record on the term that the request could not be sent, and log it.
	 *
	 * @param InformationRequestRun $run   The request whose letter did not go out.
	 * @param string                $error What went wrong.
	 *
	 * @return array<string, mixed>|null The recorded event.
	 */
	private function recordNotSent(InformationRequestRun $run, string $error): ?array {
		$record = $this->termService->recordEvent(
			termInstanceId: $run->instanceId,
			type: self::EVENT_FAILED,
			basis: 'Awb 4:5',
			rationale: 'De aanvraag om aanvulling kon niet worden verzonden, dus de termijn loopt door.',
			daysImpact: 0,
			moment: $run->moment,
			items: $run->items,
		);

		$this->logger->warning(
			'Dossiq term: a request for information could not be sent, so the term was NOT suspended',
			['instance' => $run->instanceId, 'error' => $error]
		);

		return $record;
	}//end recordNotSent()

	/**
	 * The running statutory term on a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The instance.
	 *
	 * @throws RefusedException When there is no running term to suspend.
	 */
	private function runningTermFor(string $caseId): array {
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $row) {
			if (TermKind::ofInstance($row) !== TermKind::STATUTORY) {
				continue;
			}

			if (in_array((string)($row['status'] ?? ''), ['lopend', 'verlengd'], true) === true) {
				return $row;
			}
		}//end foreach

		throw new RefusedException(
			rule: 'no-running-term-to-suspend',
			sentence: 'This case has no running statutory term, so there is nothing to suspend.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end runningTermFor()

	/**
	 * The suspended statutory term on a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The instance.
	 *
	 * @throws RefusedException When there is no suspended term to resume.
	 */
	private function suspendedTermFor(string $caseId): array {
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $row) {
			if (TermKind::ofInstance($row) === TermKind::STATUTORY && (string)($row['status'] ?? '') === 'paused') {
				return $row;
			}
		}//end foreach

		throw new RefusedException(
			rule: 'no-suspended-term-to-resume',
			sentence: 'This case has no suspended term, so there is nothing to resume.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end suspendedTermFor()

	/**
	 * The items, trimmed, without the empty ones.
	 *
	 * @param array<int, mixed> $items The raw list.
	 *
	 * @return array<int, string> The usable lines.
	 */
	private function cleanItems(array $items): array {
		$clean = [];
		foreach ($items as $item) {
			$line = trim((string)$item);
			if ($line !== '') {
				$clean[] = $line;
			}
		}//end foreach

		return $clean;
	}//end cleanItems()
}//end class
