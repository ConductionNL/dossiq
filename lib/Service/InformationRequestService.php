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
use OCA\Dossiq\Exception\RefusedException;
use Psr\Log\LoggerInterface;

/**
 * One act that asks, records and suspends, and one that resumes (REQ-TERM-067).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
	 *
	 * @return array{sent: bool, suspended: bool, instance: array<string, mixed>,
	 *               record: array<string, mixed>|null, error: string}
	 *
	 * @throws RefusedException When the case carries no running term to suspend,
	 *         or the case type refuses a suspension this long.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	public function ask(
		string $caseId,
		array $items,
		string $recipient,
		int $durationDays,
		string $rationale = '',
	): array {
		$instance = $this->runningTermFor(caseId: $caseId);
		$instanceId = (string)($instance['id'] ?? '');
		$asked = $this->cleanItems(items: $items);

		if (count($asked) === 0) {
			throw new RefusedException(
				rule: 'information-request-names-nothing',
				sentence: 'Name at least one thing the applicant has to send, so the request can be answered.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$now = new DateTimeImmutable();

		// The date in the letter is the date the applicant is held to, so it
		// lands on the administered calendar like every other term end. It is
		// computed through CaseTermsService rather than here, so this file does
		// no date arithmetic of its own and cannot drift from the pause the
		// next line registers.
		$due = $this->terms->endAfter(start: $now, days: max(1, $durationDays));

		try {
			$this->notifications->sendTermijnNotification(
				self::TEMPLATE,
				$instanceId,
				$recipient,
				['items' => $asked, 'pauseDeadline' => $due, 'case' => $caseId],
			);
		} catch (\Throwable $e) {
			return $this->recordFailedSend(
				instanceId: $instanceId,
				instance: $instance,
				items: $asked,
				moment: $now,
				error: $e->getMessage(),
			);
		}

		$why = 'Aanvulling gevraagd';
		if ($rationale !== '') {
			$why = $rationale;
		}

		// The send worked, so the clock stops. This order is the requirement:
		// a suspension that happens first survives a send that fails.
		$suspended = $this->pause->registerPauze(
			termInstanceId: $instanceId,
			durationDays: max(1, $durationDays),
			rationale: ($why . ' (Awb 4:5)'),
		);

		$record = $this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: self::EVENT_REQUESTED,
			basis: 'Awb 4:5',
			rationale: $why,
			daysImpact: max(1, $durationDays),
			moment: $now,
			items: $asked,
		);

		$this->logger->info(
			'Dossiq term: the applicant was asked and the term was suspended in one act',
			['case' => $caseId, 'instance' => $instanceId, 'items' => count($asked)]
		);

		return [
			'sent' => true,
			'suspended' => true,
			'instance' => $suspended,
			'record' => $record,
			'error' => '',
		];
	}//end ask()

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
	 * @param string $instanceId The term instance.
	 * @param array<string, mixed> $instance The instance as it stands, unsuspended.
	 * @param array<int, string> $items What was going to be asked for.
	 * @param DateTimeImmutable $moment When the attempt was made.
	 * @param string $error What went wrong.
	 *
	 * @return array{sent: bool, suspended: bool, instance: array<string, mixed>,
	 *               record: array<string, mixed>|null, error: string}
	 */
	private function recordFailedSend(
		string $instanceId,
		array $instance,
		array $items,
		DateTimeImmutable $moment,
		string $error,
	): array {
		$record = $this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: self::EVENT_FAILED,
			basis: 'Awb 4:5',
			rationale: 'De aanvraag om aanvulling kon niet worden verzonden, dus de termijn loopt door.',
			daysImpact: 0,
			moment: $moment,
			items: $items,
		);

		$this->logger->warning(
			'Dossiq term: a request for information could not be sent, so the term was NOT suspended',
			['instance' => $instanceId, 'error' => $error]
		);

		return [
			'sent' => false,
			'suspended' => false,
			'instance' => $instance,
			'record' => $record,
			'error' => $error,
		];
	}//end recordFailedSend()

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
