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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chasing while a case waits on somebody else (REQ-TERM-011).
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
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
	 * @param PauseChaseSources $chase The pause reasons, the chase schedule and the notification service.
	 * @param CaseTimeline                   $timeline       The one log a handler reads.
	 * @param SettingsService                $settings       The OpenRegister seam.
	 * @param LoggerInterface                $logger         Logger.
	 * @param AanvullingsverzoekService|null $aanvullingen   The open request, which is where the
	 *        address the first letter went to is written down. Optional, so an instance on an
	 *        older configuration simply finds no recipient and is not chased.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly PauseChaseSources $chase,
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
	 * @spec openspec/specs/termijn-pause-extension/spec.md
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
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function chaseIfDue(array $instance, ?DateTimeImmutable $now = null): bool {
		$moment = ($now ?? new DateTimeImmutable());
		$context = $this->contextFor(instance: $instance);
		if ($context === null) {
			return false;
		}

		[$fresh, $reason] = $context;
		if ($this->chase->schedule->chaseDue(instance: $fresh, reason: $reason, now: $moment) === false) {
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
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function escalateIfDue(array $instance, ?DateTimeImmutable $now = null): bool {
		$moment = ($now ?? new DateTimeImmutable());
		$context = $this->contextFor(instance: $instance);
		if ($context === null) {
			return false;
		}

		[$fresh, $reason] = $context;
		if ($this->chase->schedule->escalationDue(instance: $fresh, reason: $reason, now: $moment) === false) {
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

		$key = trim((string)($fresh['pauseReason'] ?? ''));
		$caseId = trim((string)($fresh['case'] ?? ''));
		if ($key === '' || $caseId === '') {
			return null;
		}

		// ONE READ of the case type answers both questions: which reasons it
		// declares, and whether it sends this message at all. Reading it twice
		// would let the two answers come from different rows.
		$caseType = $this->chase->reasons->caseTypeForCase(caseId: $caseId);
		if ((new CaseTypeHandling())->sends(caseType: $caseType, message: self::TEMPLATE) === false) {
			// The case type turned the reminder off. That is a switch an
			// administrator set, so it is honoured here rather than overridden
			// by a reason that happens to declare a schedule.
			return null;
		}

		foreach ($this->chase->reasons->reasonsIn(caseTypeRow: $caseType) as $reason) {
			if ($reason['key'] === $key) {
				return [$fresh, $reason];
			}
		}//end foreach

		return null;
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
		$step = $this->stepFor(instance: $instance, reason: $reason);
		$step->moment = $moment;
		$step->recipient = $this->recipientFor(caseId: $step->caseId);
		if ($step->recipient === '') {
			$this->logger->warning(
				'Dossiq pause: a reminder was due but the case carries no address to send it to',
				['app' => AppIdentity::APP_ID, 'instance' => $step->instanceId, 'case' => $step->caseId]
			);
			return false;
		}

		$step->number = ($this->chase->schedule->sent(instance: $instance) + 1);
		if ($this->sendReminder(step: $step) === false) {
			return false;
		}

		$this->termService->updateTermijnInstance(
			$step->instanceId,
			[
				'chasesSent' => $step->number,
				'lastChasedAt' => $moment->format('c'),
			]
		);

		$step->event = self::EVENT_CHASED;
		$step->sentence = $this->chasedSentence(reason: $reason, number: $step->number);
		$this->recordStep(step: $step);
		$this->projectOntoCase(step: $step);
		$this->logChased(step: $step);

		return true;
	}//end chase()

	/**
	 * The step for one paused row and its reason.
	 *
	 * @param array<string, mixed> $instance The fresh, still-paused row.
	 * @param array<string, mixed> $reason   The normalised reason in force.
	 *
	 * @return PauseStep
	 */
	private function stepFor(array $instance, array $reason): PauseStep {
		$step = new PauseStep();
		$step->instance = $instance;
		$step->reason = $reason;
		$step->instanceId = (string)($instance['id'] ?? '');
		$step->caseId = trim((string)($instance['case'] ?? ''));

		return $step;
	}//end stepFor()

	/**
	 * Send the reminder; one that did not leave is logged and counts nothing.
	 *
	 * @param PauseStep $step The reminder, with its recipient and number.
	 *
	 * @return bool Whether the reminder left.
	 */
	private function sendReminder(PauseStep $step): bool {
		try {
			$this->chase->notifications->sendTermijnNotification(
				self::TEMPLATE,
				$step->instanceId,
				$step->recipient,
				[
					'case' => $step->caseId,
					'chaseText' => (string)$step->reason['chaseText'],
					'pauseDeadline' => (string)($step->instance['pauseDeadline'] ?? ''),
					// One name per reminder: the engine rung and the daily sweep
					// asking for reminder N on this term ask for the same notice,
					// and the sender sends it once.
					'dedupeKey' => $step->instanceId . ':chase:' . $step->number,
				],
			);
		} catch (Throwable $e) {
			// A reminder that did not leave is not a reminder. Nothing is
			// counted, so the next run tries again rather than spending the
			// budget on a letter nobody received.
			$this->logger->warning(
				'Dossiq pause: a reminder could not be sent, so nothing was counted',
				['app' => AppIdentity::APP_ID, 'instance' => $step->instanceId, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end sendReminder()

	/**
	 * Log that a reminder went out.
	 *
	 * @param PauseStep $step The reminder.
	 *
	 * @return void
	 */
	private function logChased(PauseStep $step): void {
		$this->logger->info(
			'Dossiq pause: a reminder went out on a suspended term',
			[
				'app' => AppIdentity::APP_ID,
				'instance' => $step->instanceId,
				'case' => $step->caseId,
				'reason' => (string)$step->reason['key'],
				'chase' => $step->number,
			]
		);
	}//end logChased()

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
		$step = $this->stepFor(instance: $instance, reason: $reason);
		$step->moment = $moment;
		$step->event = self::EVENT_ESCALATED;
		$step->sentence = $this->escalatedSentence(reason: $reason, sent: $this->chase->schedule->sent(instance: $instance));

		$this->termService->updateTermijnInstance(
			$step->instanceId,
			['chaseEscalatedAt' => $moment->format('c')]
		);

		$this->recordStep(step: $step);
		$this->logger->info(
			'Dossiq pause: the reminders ran out without an answer',
			[
				'app' => AppIdentity::APP_ID,
				'instance' => $step->instanceId,
				'case' => $step->caseId,
				'reason' => (string)$reason['key'],
				'escalateTo' => (string)$reason['escalateTo'],
			]
		);

		return true;
	}//end escalate()

	/**
	 * Record the step as an event on the term and an entry on the case timeline.
	 *
	 * @param PauseStep $step The reminder or escalation, with its event and sentence.
	 *
	 * @return void
	 */
	private function recordStep(PauseStep $step): void {
		$this->termService->recordEvent(
			termInstanceId: $step->instanceId,
			type: $step->event,
			basis: $this->basisOf(reason: $step->reason),
			rationale: $step->sentence,
			daysImpact: 0,
			moment: $step->moment,
		);

		$this->timeline->record(
			caseId: $step->caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: $step->sentence,
			fields: [
				'event' => $step->event,
				'term' => (string)$step->reason['name'],
				'dueAt' => (string)($step->instance['pauseDeadline'] ?? ''),
				'termijnId' => $step->instanceId,
			],
		);
	}//end recordStep()

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
				['app' => AppIdentity::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
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
	 * @param PauseStep $step The reminder that went out: its case, reason, instance and number.
	 *
	 * @return void
	 */
	private function projectOntoCase(PauseStep $step): void {
		$caseId = $step->caseId;
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('case_schema');
		if ($objectService === null || $caseId === '' || $register === '' || $schema === '') {
			return;
		}

		$changes = [
			'pauseWaitingOn' => (string)$step->reason['waitingOn'],
			'chasesSent' => $step->number,
		];
		$since = $this->chase->schedule->pauseStartMoment(date: (string)($step->instance['pauzeStartDatum'] ?? ''));
		if ($since !== null) {
			$changes['waitingSince'] = $since;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq pause: the waiting sentence on the case could not be written; the term is correct',
				['app' => AppIdentity::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
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

		// NO CATCH HERE, DELIBERATELY. "No case is suspended" and "we could not
		// ask" are opposite answers, and the first one is a silent sweep that
		// reports nothing wrong every night. A failure reaches `PauseChaseJob`,
		// which logs it as an error and lets tomorrow's sweep try again.
		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['status' => 'paused', '_limit' => self::SWEEP_LIMIT]
		);
	}//end pausedInstances()
}//end class
