<?php

/**
 * Dossiq PauseChaseService.
 *
 * Reminding the person a case is waiting on, and telling the handler when
 * reminding stopped working.
 *
 * A pause used to be a clock that stopped and a sentence that said why. Nobody
 * wrote to the applicant on day 7, nobody again on day 12, and the handler
 * found out on the last day that nothing had come. This is the part that does
 * the reminding: it reads the schedule the case type declared for the reason in
 * force, sends the reminder down the same route the request itself went, writes
 * what it sent on the term and on the case timeline, and escalates once the
 * budget is spent.
 *
 * 🔴 ONE DECISION, TWO TRIGGERS, AND THE COUNT IS WHAT KEEPS THEM APART.
 *
 * The engine rung armed with the pause calls this, and so does the daily sweep
 * that runs when there is no engine to arm one. Both go through
 * {@see chaseIfDue()}, which re-reads the instance, asks {@see ChaseSchedule}
 * whether a reminder is due against the count already stored, and writes the
 * new count in the same save as the send. A second trigger arriving a minute
 * later reads the new count and finds nothing due. Without that, an instance
 * with both an engine and a sweep would be chased twice a day.
 *
 * WHAT "ESCALATE" MEANS HERE. It records a `chase-escalated` event on the term
 * and an entry on the case timeline naming who the case type said to tell, and
 * the personal queue then reads "no reply after 2 reminders" beside the case.
 * It does not push a Nextcloud notification: the notification dialect is
 * ADR-031's and a leaf app dispatching its own is exactly what that ADR stops.
 *
 * A FAILED SEND IS NOT A CHASE. Nothing is counted, nothing is recorded as
 * sent, and the next run tries again. The alternative is a count that says two
 * reminders went out when the citizen received none, and that count is what an
 * escalation and, eventually, a refusal for incompleteness rest on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pause
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
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chasing while a case waits on somebody else (REQ-TERM-011).
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The act joins five things that
 * already exist: the term store, the outbound route, the reason vocabulary, the
 * schedule and the case timeline. Splitting it would put the count that keeps
 * two triggers apart in one class and the send that increments it in another.
 */
class PauseChaseService {
	use SearchesObjects;

	/**
	 * The template the reminder is rendered from.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'hersteltermijn-reminder';

	/**
	 * The event type carrying a reminder that went out.
	 *
	 * @var string
	 */
	public const EVENT_CHASED = 'chased';

	/**
	 * The event type carrying the silence after the last reminder.
	 *
	 * @var string
	 */
	public const EVENT_ESCALATED = 'chase-escalated';

	/**
	 * How many paused instances one sweep looks at.
	 *
	 * @var int
	 */
	private const SWEEP_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param TermijnService                 $termService    The instances, their patches and their events.
	 * @param PauseReasonReader              $reasons        What the case type declared.
	 * @param ChaseSchedule                  $schedule       When the next reminder is due.
	 * @param TermijnNotificationService     $notifications  The one route a citizen letter leaves by.
	 * @param CaseTimeline                   $timeline       The one log a handler reads.
	 * @param SettingsService                $settings       The OpenRegister seam.
	 * @param LoggerInterface                $logger         Logger.
	 * @param AanvullingsverzoekService|null $aanvullingen   The open request, which is where the
	 *        address the first letter went to is written down. Optional, so an instance on an
	 *        older configuration simply finds no recipient and is not chased.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly PauseReasonReader $reasons,
		private readonly ChaseSchedule $schedule,
		private readonly TermijnNotificationService $notifications,
		private readonly CaseTimeline $timeline,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
		private readonly ?AanvullingsverzoekService $aanvullingen = null,
	) {
	}//end __construct()

	/**
	 * Look at every paused instance once, and chase or escalate the ones due.
	 *
	 * @param DateTimeImmutable|null $now The moment to judge against.
	 *
	 * @return array{chased: int, escalated: int} What the sweep did.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function sweep(?DateTimeImmutable $now = null): array {
		$done = ['chased' => 0, 'escalated' => 0];

		foreach ($this->pausedInstances() as $instance) {
			if ($this->chaseIfDue(instance: $instance, now: $now) === true) {
				$done['chased']++;
				continue;
			}

			if ($this->escalateIfDue(instance: $instance, now: $now) === true) {
				$done['escalated']++;
			}
		}//end foreach

		return $done;
	}//end sweep()

	/**
	 * Chase one instance, if one is due on it right now.
	 *
	 * @param array<string, mixed>   $instance The TermijnInstance row.
	 * @param DateTimeImmutable|null $now      The moment to judge against.
	 *
	 * @return bool True when a reminder actually went out.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function chaseIfDue(array $instance, ?DateTimeImmutable $now = null): bool {
		$moment = ($now ?? new DateTimeImmutable());
		$context = $this->contextFor(instance: $instance);
		if ($context === null) {
			return false;
		}

		[$fresh, $reason] = $context;
		if ($this->schedule->chaseDue(instance: $fresh, reason: $reason, now: $moment) === false) {
			return false;
		}

		return $this->chase(instance: $fresh, reason: $reason, moment: $moment);
	}//end chaseIfDue()

	/**
	 * Escalate one instance, if the silence after the last reminder is due to
	 * be escalated.
	 *
	 * @param array<string, mixed>   $instance The TermijnInstance row.
	 * @param DateTimeImmutable|null $now      The moment to judge against.
	 *
	 * @return bool True when the escalation was recorded.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function escalateIfDue(array $instance, ?DateTimeImmutable $now = null): bool {
		$moment = ($now ?? new DateTimeImmutable());
		$context = $this->contextFor(instance: $instance);
		if ($context === null) {
			return false;
		}

		[$fresh, $reason] = $context;
		if ($this->schedule->escalationDue(instance: $fresh, reason: $reason, now: $moment) === false) {
			return false;
		}

		return $this->escalate(instance: $fresh, reason: $reason, moment: $moment);
	}//end escalateIfDue()

	/**
	 * The instance as it stands now, with the reason in force, or null when
	 * this instance is not one that chases.
	 *
	 * RE-READ, ALWAYS. The caller may be a rung fired minutes ago against a
	 * row that has since been resumed or chased. The still-paused check and
	 * the count both have to be made against the row as it is, not as the
	 * trigger remembers it.
	 *
	 * @param array<string, mixed> $instance The row the trigger carried.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null The fresh row and its reason.
	 */
	private function contextFor(array $instance): ?array {
		$instanceId = trim((string)($instance['id'] ?? ($instance['uuid'] ?? '')));
		if ($instanceId === '') {
			return null;
		}

		$fresh = $this->termService->getTermijnInstance($instanceId);
		if ($fresh === null || (string)($fresh['status'] ?? '') !== 'paused') {
			return null;
		}

		$reason = $this->reasons->forInstance(instance: $fresh);
		if ($reason === null) {
			return null;
		}

		return [$fresh, $reason];
	}//end contextFor()

	/**
	 * Send one reminder and write down that it went.
	 *
	 * @param array<string, mixed> $instance The fresh, still-paused row.
	 * @param array<string, mixed> $reason   The normalised reason in force.
	 * @param DateTimeImmutable    $moment   When this is happening.
	 *
	 * @return bool True when the reminder went out.
	 */
	private function chase(array $instance, array $reason, DateTimeImmutable $moment): bool {
		$instanceId = (string)($instance['id'] ?? '');
		$caseId = trim((string)($instance['case'] ?? ''));
		$recipient = $this->recipientFor(caseId: $caseId);
		if ($recipient === '') {
			$this->logger->warning(
				'Dossiq pause: a reminder was due but the case carries no address to send it to',
				['app' => Application::APP_ID, 'instance' => $instanceId, 'case' => $caseId]
			);
			return false;
		}

		try {
			$this->notifications->sendTermijnNotification(
				self::TEMPLATE,
				$instanceId,
				$recipient,
				[
					'case' => $caseId,
					'chaseText' => (string)$reason['chaseText'],
					'pauseDeadline' => (string)($instance['pauseDeadline'] ?? ''),
				],
			);
		} catch (Throwable $e) {
			// A reminder that did not leave is not a reminder. Nothing is
			// counted, so the next run tries again rather than spending the
			// budget on a letter nobody received.
			$this->logger->warning(
				'Dossiq pause: a reminder could not be sent, so nothing was counted',
				['app' => Application::APP_ID, 'instance' => $instanceId, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		$number = ($this->schedule->sent(instance: $instance) + 1);

		$this->termService->updateTermijnInstance(
			$instanceId,
			[
				'chasesSent' => $number,
				'lastChasedAt' => $moment->format('c'),
			]
		);

		$this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: self::EVENT_CHASED,
			basis: $this->basisOf(reason: $reason),
			rationale: $this->chasedSentence(reason: $reason, number: $number),
			daysImpact: 0,
			moment: $moment,
		);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: $this->chasedSentence(reason: $reason, number: $number),
			fields: [
				'event' => self::EVENT_CHASED,
				'term' => (string)$reason['name'],
				'dueAt' => (string)($instance['pauseDeadline'] ?? ''),
				'termijnId' => $instanceId,
			],
		);

		$this->projectOntoCase(caseId: $caseId, reason: $reason, instance: $instance, chases: $number);

		$this->logger->info(
			'Dossiq pause: a reminder went out on a suspended term',
			[
				'app' => Application::APP_ID,
				'instance' => $instanceId,
				'case' => $caseId,
				'reason' => (string)$reason['key'],
				'chase' => $number,
			]
		);

		return true;
	}//end chase()

	/**
	 * Record that the reminders ran out without an answer.
	 *
	 * @param array<string, mixed> $instance The fresh, still-paused row.
	 * @param array<string, mixed> $reason   The normalised reason in force.
	 * @param DateTimeImmutable    $moment   When this is happening.
	 *
	 * @return bool True when the escalation was recorded.
	 */
	private function escalate(array $instance, array $reason, DateTimeImmutable $moment): bool {
		$instanceId = (string)($instance['id'] ?? '');
		$caseId = trim((string)($instance['case'] ?? ''));
		$sentence = $this->escalatedSentence(reason: $reason, sent: $this->schedule->sent(instance: $instance));

		$this->termService->updateTermijnInstance(
			$instanceId,
			['chaseEscalatedAt' => $moment->format('c')]
		);

		$this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: self::EVENT_ESCALATED,
			basis: $this->basisOf(reason: $reason),
			rationale: $sentence,
			daysImpact: 0,
			moment: $moment,
		);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: $sentence,
			fields: [
				'event' => self::EVENT_ESCALATED,
				'term' => (string)$reason['name'],
				'dueAt' => (string)($instance['pauseDeadline'] ?? ''),
				'termijnId' => $instanceId,
			],
		);

		$this->logger->info(
			'Dossiq pause: the reminders ran out without an answer',
			[
				'app' => Application::APP_ID,
				'instance' => $instanceId,
				'case' => $caseId,
				'reason' => (string)$reason['key'],
				'escalateTo' => (string)$reason['escalateTo'],
			]
		);

		return true;
	}//end escalate()

	/**
	 * What one reminder reads as, on the term and on the timeline.
	 *
	 * @param array<string, mixed> $reason The normalised reason.
	 * @param int                  $number Which reminder this is.
	 *
	 * @return string The sentence.
	 */
	private function chasedSentence(array $reason, int $number): string {
		$name = trim((string)$reason['name']);
		if ($name === '') {
			$name = (string)$reason['key'];
		}

		return 'Reminder ' . $number . ' of ' . (int)$reason['chaseBudget'] . ' sent: ' . $name;
	}//end chasedSentence()

	/**
	 * What the escalation reads as.
	 *
	 * @param array<string, mixed> $reason The normalised reason.
	 * @param int                  $sent   How many reminders went out.
	 *
	 * @return string The sentence.
	 */
	private function escalatedSentence(array $reason, int $sent): string {
		$sentence = 'No reply after ' . $sent . ' reminders.';
		$target = trim((string)$reason['escalateTo']);
		if ($target !== '') {
			$sentence .= ' Escalated to ' . $target . '.';
		}

		return $sentence;
	}//end escalatedSentence()

	/**
	 * The legal basis a reason's events are recorded under.
	 *
	 * @param array<string, mixed> $reason The normalised reason.
	 *
	 * @return string The basis, Awb 4:5 when the reason declares none.
	 */
	private function basisOf(array $reason): string {
		$basis = trim((string)$reason['legalBasis']);
		if ($basis === '') {
			return 'Awb 4:5';
		}

		return $basis;
	}//end basisOf()

	/**
	 * Where the reminder goes.
	 *
	 * The open request is the record of where the first letter went, so the
	 * reminder follows it rather than resolving an address a second way. Two
	 * resolutions would mean the reminder can reach an address the request
	 * never did.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The recipient, empty when none is recorded.
	 */
	private function recipientFor(string $caseId): string {
		if ($this->aanvullingen === null || $caseId === '') {
			return '';
		}

		try {
			$open = $this->aanvullingen->openFor(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq pause: the open request behind a suspended term could not be read',
				['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
			);
			return '';
		}

		return trim((string)($open['recipient'] ?? ''));
	}//end recipientFor()

	/**
	 * Write the waiting facts onto the case.
	 *
	 * A READ CONVENIENCE, NEVER A SECOND TRUTH, the same rule
	 * `AanvullingsverzoekService::markCaseWaiting()` follows. The personal
	 * queue has to say "waiting on the applicant for 9 days, chased twice"
	 * without reading every term instance of every case, and a failure here
	 * leaves a stale sentence beside a term that is still correct.
	 *
	 * @param string               $caseId   The case UUID.
	 * @param array<string, mixed> $reason   The normalised reason in force.
	 * @param array<string, mixed> $instance The instance the pause is on.
	 * @param int                  $chases   How many reminders have gone out.
	 *
	 * @return void
	 */
	private function projectOntoCase(string $caseId, array $reason, array $instance, int $chases): void {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('case_schema');
		if ($objectService === null || $caseId === '' || $register === '' || $schema === '') {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: [
					'pauseWaitingOn' => (string)$reason['waitingOn'],
					'waitingSince' => (string)($instance['pauzeStartDatum'] ?? ''),
					'chasesSent' => $chases,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq pause: the waiting sentence on the case could not be written; the term is correct',
				['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end projectOntoCase()

	/**
	 * Every suspended term instance.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when they cannot be read.
	 */
	private function pausedInstances(): array {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('termijn_instance_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'paused', '_limit' => self::SWEEP_LIMIT]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq pause: the suspended terms could not be read, so nothing was chased',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end pausedInstances()
}//end class
