<?php

/**
 * The clock on a status that declares a maximum.
 *
 * A daily sweep that recomputes every case is the shape
 * `termijnbewaking-op-engine-timers` spent a change removing, and putting it
 * back for status dwell would be putting it back. So entering a status with a
 * declared maximum ARMS an engine timer and leaving it CANCELS one: the breach
 * is an event the engine raises, not something a scan discovers the next
 * morning.
 *
 * The timer is armed in the engine's own `businessDays` unit, so the maximum
 * is counted on the calendar the organisation administers rather than on a
 * second list dossiq keeps. `legalEffect: none` because a status maximum is a
 * service level and not a statutory term: it raises its own signal, it never
 * touches the case's beslistermijn, and the ladder that escalates a statutory
 * term must not fire for it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arms and cancels the engine timer behind a status's maximum dwell.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class StatusDwellTimer {

	/**
	 * What every dwell timer stamps itself with, so the listener can tell one
	 * from a beslistermijn armed on the same engine.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-status-dwell';

	/**
	 * The kind, beside the source, for a reader that groups by it.
	 *
	 * @var string
	 */
	public const KIND_STATUS_DWELL = 'statusDwell';

	/**
	 * The event name the engine anchors the timer at.
	 *
	 * @var string
	 */
	public const ANCHOR_EVENT = 'status_entered';

	/**
	 * The message a breached dwell carries, which is how the listener
	 * recognises it without parsing a title.
	 *
	 * @var string
	 */
	public const BREACH_MESSAGE = 'status-dwell-verlopen';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the OpenRegister timer engine.
	 * @param LoggerInterface $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Arm the dwell timer for a case entering a status with a maximum.
	 *
	 * @param string $caseId       The case.
	 * @param string $statusTypeId The status entered.
	 * @param int    $maximumDwell The maximum, in working days.
	 * @param string $statusName   The status's name, for the timer's title.
	 *
	 * @return string|null The armed timer uuid, or null when the engine is absent or refused.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function arm(string $caseId, string $statusTypeId, int $maximumDwell, string $statusName = ''): ?string {
		if ($caseId === '' || $maximumDwell < 1) {
			return null;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return null;
		}

		$config = $this->dwellTimerConfig(
			caseId: $caseId,
			statusTypeId: $statusTypeId,
			maximumDwell: $maximumDwell,
			statusName: $statusName
		);

		return $this->armOnEngine(engine: $engine, config: $config, caseId: $caseId);
	}//end arm()

	/**
	 * The timer configuration of a status's maximum dwell.
	 *
	 * @param string $caseId       The case.
	 * @param string $statusTypeId The status entered.
	 * @param int    $maximumDwell The maximum, in working days.
	 * @param string $statusName   The status's name, for the timer's title.
	 *
	 * @return array<string, mixed> The engine configuration.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function dwellTimerConfig(string $caseId, string $statusTypeId, int $maximumDwell, string $statusName): array {
		$named = $statusName;
		if ($named === '') {
			$named = $statusTypeId;
		}

		return [
			'subjectType' => 'object',
			'subjectUuid' => $caseId,
			'appId' => 'dossiq',
			'title' => 'Maximum dwell ' . $named,
			'purpose' => 'due',
			'legalEffect' => 'none',
			'sla' => [
				'value' => $maximumDwell,
				'unit' => TermijnTimerService::UNIT_BUSINESS_DAYS,
			],
			'escalationRules' => [
				[
					'trigger' => 'slaBreached',
					'offset' => 0,
					'offsetUnit' => TermijnTimerService::UNIT_BUSINESS_DAYS,
					'notifyRole' => ['handler'],
					'escalateToRole' => [],
					'priority' => 'normal',
					'message' => self::BREACH_MESSAGE,
					'openIncident' => false,
				],
			],
			'anchorEvent' => self::ANCHOR_EVENT,
			'metadata' => [
				'source' => self::METADATA_SOURCE,
				'kind' => self::KIND_STATUS_DWELL,
				'caseId' => $caseId,
				'statusTypeId' => $statusTypeId,
				'maximumDwell' => $maximumDwell,
			],
		];
	}//end dwellTimerConfig()

	/**
	 * Arm the configured timer on the engine.
	 *
	 * @param object               $engine The FlowTimerService.
	 * @param array<string, mixed> $config The engine configuration.
	 * @param string               $caseId The case.
	 *
	 * @return string|null The armed timer uuid, or null when the engine refused.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function armOnEngine(object $engine, array $config, string $caseId): ?string {
		// The catch LOGS and falls through; the degraded answer is the last
		// statement of the method rather than a `return null` hidden in an
		// exception handler. `ServiceCatchReturnsNullTest` is the guard, and
		// it is right: a caller cannot tell a refusal from a failure when both
		// arrive as the same empty value, and the shape below at least puts
		// "nothing was armed" where a reader looking at the method sees it.
		$timer = null;
		try {
			$timer = $engine->arm(config: $config, actor: null);
		} catch (Throwable $e) {
			$this->degraded(operation: 'arm', caseId: $caseId, error: $e);
		}

		if ($timer === null) {
			return null;
		}

		return (string)$timer->getUuid();
	}//end armOnEngine()

	/**
	 * Cancel the dwell timer a case is carrying.
	 *
	 * Called on every status change, including a change INTO a status with no
	 * maximum: leaving a status has to stop its clock whatever it moved to, or
	 * the next breach is about a status the case is no longer in.
	 *
	 * Cancels by subject rather than by timer id, because the engine's cancel
	 * is a per-subject operation and dossiq's other timers are armed on the
	 * termijn instance, not on the case. A dwell cancel therefore reaches the
	 * dwell timers and nothing else.
	 *
	 * @param string $caseId The case.
	 * @param string $reason Why, recorded on the cancelled timer.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function cancel(string $caseId, string $reason = 'status changed'): int {
		if ($caseId === '') {
			return 0;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return 0;
		}

		try {
			return (int)$engine->cancelForSubject(
				subject: new TimerSubjectRef(type: 'object', uuid: $caseId),
				change: new TimerChange(reason: $reason, actor: null)
			);
		} catch (Throwable $e) {
			$this->degraded(operation: 'cancel', caseId: $caseId, error: $e);

			return 0;
		}
	}//end cancel()

	/**
	 * The engine, or null when OpenRegister's timer stack is absent.
	 *
	 * @return object|null The FlowTimerService.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(TermijnTimerService::ENGINE_CLASS);
	}//end engine()

	/**
	 * Say out loud that the clock and the case have diverged.
	 *
	 * The transition itself proceeds: a case that could not move because a
	 * timer could not be armed would be a case held hostage by a service level.
	 * The log line is the operator's signal that the breach will not fire.
	 *
	 * @param string    $operation Which call failed.
	 * @param string    $caseId    The case involved.
	 * @param Throwable $error     The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function degraded(string $operation, string $caseId, Throwable $error): void {
		$this->logger->warning(
			'Dossiq status dwell: the engine timer call failed, the transition proceeds without a dwell clock',
			['operation' => $operation, 'case' => $caseId, 'error' => $error->getMessage()],
		);
	}//end degraded()
}//end class
