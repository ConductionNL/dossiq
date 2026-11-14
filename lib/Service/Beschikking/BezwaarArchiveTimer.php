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
use OCA\Dossiq\Service\Term\DayCount;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use Psr\Log\LoggerInterface;
use Throwable;

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

		$archive = $this->midnight(value: $trigger['archiveDate'] ?? null);
		if ($archive === null) {
			return self::SKIPPED;
		}

		$cancelled = $this->cancel(triggerId: $triggerId, reason: 'Superseded by the current bekendmaking');
		if ($cancelled !== null) {
			return $cancelled;
		}

		$today = $this->dates->today();
		if ($archive <= $today) {
			return self::DUE;
		}

		// Anchored on the bekendmaking, as AWB 6:7 counts from it. A trigger
		// without a usable one, or with one after its archive date, is
		// anchored today, so the timer still breaches on the archive date.
		$anchor = $this->midnight(value: $trigger['announcementDate'] ?? null);
		if ($anchor === null || $anchor >= $archive || $anchor > $today) {
			$anchor = $today;
		}

		return $this->arm(trigger: $trigger, triggerId: $triggerId, anchor: $anchor, slaDays: $this->days->between(from: $anchor, to: $archive));
	}//end sync()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed> $trigger   The trigger.
	 * @param string               $triggerId Its id.
	 * @param DateTimeImmutable    $anchor    Midnight of the bekendmaking (or today).
	 * @param int                  $slaDays   Days from the anchor to the archive date.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $trigger, string $triggerId, DateTimeImmutable $anchor, int $slaDays): string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		$config = $this->timerConfig(trigger: $trigger, triggerId: $triggerId, anchor: $anchor, slaDays: $slaDays);

		return $this->armOn(engine: $engine, config: $config, triggerId: $triggerId);
	}//end arm()

	/**
	 * The timer configuration for the engine.
	 *
	 * @param array<string, mixed> $trigger   The trigger.
	 * @param string               $triggerId Its id.
	 * @param DateTimeImmutable    $anchor    Midnight of the bekendmaking (or today).
	 * @param int                  $slaDays   Days from the anchor to the archive date.
	 *
	 * @return array<string, mixed> The timer configuration.
	 */
	private function timerConfig(array $trigger, string $triggerId, DateTimeImmutable $anchor, int $slaDays): array {
		return [
			'subjectType'     => 'object',
			'subjectUuid'     => $triggerId,
			'appId'           => 'dossiq',
			'title'           => 'Bezwaartermijn '.(string) ($trigger['decisionId'] ?? $triggerId),
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => ['value' => $slaDays, 'unit' => 'calendarDays'],
			'escalationRules' => $this->escalationRules(),
			'anchorEvent'     => 'bekendmaking',
			'anchorEventAt'   => $anchor,
			'metadata'        => $this->timerMetadata(trigger: $trigger, triggerId: $triggerId),
		];
	}//end timerConfig()

	/**
	 * The escalation rules of the timer: a breach on the archive date.
	 *
	 * @return array<int, array<string, mixed>> The escalation rules.
	 */
	private function escalationRules(): array {
		return [
			[
				'trigger'        => 'slaBreached',
				'offset'         => 0,
				'offsetUnit'     => 'calendarDays',
				'notifyRole'     => [],
				'escalateToRole' => [],
				'priority'       => 'normal',
				'message'        => 'bezwaartermijn-verstreken',
				'openIncident'   => false,
			],
		];
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
	 * Hand the configuration to the engine.
	 *
	 * @param object               $engine    The timer engine.
	 * @param array<string, mixed> $config    The timer configuration.
	 * @param string               $triggerId The trigger id, for the log.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function armOn(object $engine, array $config, string $triggerId): string {
		try {
			$engine->arm(config: $config, actor: null);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar: the engine refused the bezwaartermijn timer',
				['trigger' => $triggerId, 'error' => $e->getMessage()]
			);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end armOn()

	/**
	 * Cancel the trigger's open timers.
	 *
	 * @param string $triggerId The trigger id.
	 * @param string $reason    Why, recorded on each timer.
	 *
	 * @return string|null UNAVAILABLE when the engine is absent or refused, null when done.
	 */
	private function cancel(string $triggerId, string $reason): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->cancelForSubject(
				subject: new TimerSubjectRef(type: 'object', uuid: $triggerId),
				change: new TimerChange(reason: $reason, actor: null)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar: the engine refused to cancel the bezwaartermijn timer',
				['trigger' => $triggerId, 'error' => $e->getMessage()]
			);
			return self::UNAVAILABLE;
		}

		return null;
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
	 * The engine, or null when OpenRegister does not ship it.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()
}//end class
