<?php

/**
 * Dossiq bezwaartermijn on an armed engine timer.
 *
 * An active `bezwaarTrigger` gets one OpenRegister FlowTimer, anchored on
 * the bekendmaking (AWB 6:7: the objection term runs from the day after the
 * beschikking is announced) and breaching at the start of its `archiveDate`,
 * the day BezwaarTermijnJob would first have acted on it
 * (`archiveDate <= today`). {@see \OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener}
 * then hands the trigger to {@see BezwaarArchiveTrigger}.
 *
 * Arming SUPERSEDES: the trigger's open timers are cancelled first, so a
 * moved bekendmaking or archive date never leaves the old timer running, and
 * the repair step can run on every upgrade. The task's `supersede()` is the
 * engine's in-place variant of the same thing; cancel-then-arm is used here
 * because it is also what the advice timer does, and one shape is easier to
 * read than two.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\BezwaarArchiveTimerInterface;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\FlowTimerEngine;
use OCA\Dossiq\Service\Term\DayCount;
use Psr\Log\LoggerInterface;

/**
 * Keeps one engine timer per active bezwaar trigger in step with the trigger.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class BezwaarArchiveTimer implements BezwaarArchiveTimerInterface {

	/**
	 * The engine class, resolved lazily so dossiq runs without OpenRegister.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\FlowTimerService';

	/**
	 * Outcome: the trigger is switched off and its timers are cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Outcome: nothing to time (no id, no archive date).
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Build the service.
	 *
	 * @param SettingsService    $settingsService Resolves the engine.
	 * @param CaseDateNormaliser $dates           Today and the dates, in the administered zone.
	 * @param LoggerInterface    $logger          Logs engine refusals.
	 * @param DayCount           $days            Counts the days between two moments.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Bring the trigger's timer in step with the trigger.
	 *
	 * @param array<string, mixed> $trigger The bezwaarTrigger object.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sync(array $trigger): string {
		$triggerId = (string) ($trigger['id'] ?? ($trigger['uuid'] ?? ($trigger['@self']['id'] ?? '')));
		if ($triggerId === '') {
			return self::SKIPPED;
		}

		if (($trigger['archiveTriggerActive'] ?? false) !== true) {
			return $this->cancel(triggerId: $triggerId, reason: 'Bezwaar trigger switched off') ?? self::CANCELLED;
		}

		return $this->syncActive(trigger: $trigger, triggerId: $triggerId);
	}//end sync()

	/**
	 * Bring the timer of an active trigger in step with its archive date.
	 *
	 * @param array<string, mixed> $trigger   The bezwaarTrigger object.
	 * @param string               $triggerId The trigger's id.
	 *
	 * @return string One of the outcome constants.
	 */
	private function syncActive(array $trigger, string $triggerId): string {
		$archive = $this->midnight(value: $trigger['archiveDate'] ?? null);
		if ($archive === null) {
			return self::SKIPPED;
		}

		$cancelled = $this->cancel(triggerId: $triggerId, reason: 'Superseded by the current bekendmaking');
		if ($cancelled !== null) {
			return $cancelled;
		}

		return $this->armUntil(trigger: $trigger, timer: ['triggerId' => $triggerId, 'archive' => $archive]);
	}//end syncActive()

	/**
	 * Arm the timer up to the archive date, or report it due when that date has passed.
	 *
	 * @param array<string, mixed>                                $trigger The bezwaarTrigger object.
	 * @param array{triggerId: string, archive: DateTimeImmutable} $timer   The trigger's id, and the archive date at midnight.
	 *
	 * @return string One of the outcome constants.
	 */
	private function armUntil(array $trigger, array $timer): string {
		[$triggerId, $archive] = [$timer['triggerId'], $timer['archive']];
		$today = $this->dates->today();
		if ($archive <= $today) {
			return self::DUE;
		}

		$anchor = $this->anchorFor(trigger: $trigger, archive: $archive, today: $today);

		return $this->arm(
			start: new ArchiveTimerStart(
				trigger: $trigger,
				triggerId: $triggerId,
				anchor: $anchor,
				slaDays: $this->days->between(from: $anchor, to: $archive),
			)
		);
	}//end armUntil()

	/**
	 * The timer's anchor: the bekendmaking, as AWB 6:7 counts from it.
	 *
	 * @param array<string, mixed> $trigger The bezwaarTrigger object.
	 * @param DateTimeImmutable    $archive The archive date at midnight.
	 * @param DateTimeImmutable    $today   Today at midnight.
	 *
	 * @return DateTimeImmutable The anchor.
	 */
	private function anchorFor(array $trigger, DateTimeImmutable $archive, DateTimeImmutable $today): DateTimeImmutable {
		// Anchored on the bekendmaking, as AWB 6:7 counts from it. A trigger
		// without a usable one, or with one after its archive date, is
		// anchored today, so the timer still breaches on the archive date.
		$anchor = $this->midnight(value: $trigger['announcementDate'] ?? null);
		if ($anchor === null || $anchor >= $archive || $anchor > $today) {
			$anchor = $today;
		}

		return $anchor;
	}//end anchorFor()

	/**
	 * Arm the timer.
	 *
	 * @param ArchiveTimerStart $start The trigger, its id, the anchor and the days to the archive date.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(ArchiveTimerStart $start): string {
		return $this->timers()->arm(config: fn (): array => $this->timerConfig(start: $start), logContext: ['trigger' => $start->triggerId]);
	}//end arm()

	/**
	 * The timer configuration for the engine.
	 *
	 * @param ArchiveTimerStart $start The trigger, its id, the anchor and the days to the archive date.
	 *
	 * @return array<string, mixed> The timer configuration.
	 */
	private function timerConfig(ArchiveTimerStart $start): array {
		$trigger = $start->trigger;
		$triggerId = $start->triggerId;
		return [
			'subjectType'     => 'object',
			'subjectUuid'     => $triggerId,
			'appId'           => 'dossiq',
			'title'           => 'Bezwaartermijn '.(string) ($trigger['decisionId'] ?? $triggerId),
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => ['value' => $start->slaDays, 'unit' => 'calendarDays'],
			'escalationRules' => $this->escalationRules(),
			'anchorEvent'     => 'bekendmaking',
			'anchorEventAt'   => $start->anchor,
			'metadata'        => $this->timerMetadata(trigger: $trigger, triggerId: $triggerId),
		];
	}//end timerConfig()

	/**
	 * The escalation rules of the timer: a breach on the archive date.
	 *
	 * @return array<int, array<string, mixed>> The escalation rules.
	 */
	private function escalationRules(): array {
		return [$this->timers()->rule(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'bezwaartermijn-verstreken')];
	}//end escalationRules()

	/**
	 * The metadata stored on the timer.
	 *
	 * @param array<string, mixed> $trigger   The trigger.
	 * @param string               $triggerId Its id.
	 *
	 * @return array<string, string> The metadata.
	 */
	private function timerMetadata(array $trigger, string $triggerId): array {
		return [
			'source'     => self::METADATA_SOURCE,
			'triggerId'  => $triggerId,
			'decisionId' => (string) ($trigger['decisionId'] ?? ''),
			'basis'      => 'Awb 6:7',
		];
	}//end timerMetadata()

	/**
	 * Cancel the trigger's open timers.
	 *
	 * @param string $triggerId The trigger id.
	 * @param string $reason    Why, recorded on each timer.
	 *
	 * @return string|null UNAVAILABLE when the engine is absent or refused, null when done.
	 */
	private function cancel(string $triggerId, string $reason): ?string {
		return $this->timers()->cancel(subjectUuid: $triggerId, reason: $reason, logContext: ['trigger' => $triggerId]);
	}//end cancel()

	/**
	 * A stored date as midnight in the administered zone.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null Midnight, or null when it does not parse.
	 */
	private function midnight(mixed $value): ?DateTimeImmutable {
		$parsed = $this->dates->tryParse($value);
		if ($parsed === null) {
			return null;
		}

		return $parsed->setTimezone($this->dates->timeZone())->setTime(0, 0, 0);
	}//end midnight()

	/**
	 * OpenRegister's flow timer engine for this timer.
	 *
	 * @return FlowTimerEngine
	 */
	private function timers(): FlowTimerEngine {
		return new FlowTimerEngine(settingsService: $this->settingsService, logger: $this->logger, owner: 'Dossiq bezwaar', timer: 'bezwaartermijn timer');
	}//end timers()
}//end class
