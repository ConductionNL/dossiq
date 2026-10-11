<?php

/**
 * Dossiq take-back window on an armed engine timer.
 *
 * A routing rule may declare `takeBackAfter` ({value, unit}): the time within
 * which the person a case was routed to must accept it. Accepting is the
 * assignee's first status move or first edit of the case (decision 164); there
 * is no accept button. When the case is routed, this arms one OpenRegister
 * FlowTimer for it. Accepting cancels it. When it breaches,
 * {@see \OCA\Dossiq\Listener\TakeBackTimerFiredListener} hands the case back
 * to the pool through {@see CaseRouter::takeBack()}.
 *
 * It is a timer and not a sweep (D-4): the window is declared on the rule, so
 * the person it may take work from can read when it fires before it does, and
 * dossiq runs no scheduler of its own beside the engine's (ADR-022).
 *
 * The subject is `<case id>:take-back`, apart from the case's other timers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arms and cancels the take-back timer of one routed case.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class TakeBackWindow {

	/**
	 * The engine class, resolved lazily so dossiq runs without OpenRegister.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\FlowTimerService';

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-take-back';

	/**
	 * The suffix that keeps the timer's subject apart from the case's own timers.
	 *
	 * @var string
	 */
	public const SUBJECT_SUFFIX = ':take-back';

	/**
	 * The units a window may be declared in: the engine's own SLA units.
	 *
	 * @var string[]
	 */
	public const UNITS = ['hours', 'businessDays', 'calendarDays'];

	/**
	 * The largest window value accepted, the same bound a step SLA has.
	 *
	 * @var int
	 */
	public const MAX_VALUE = 10000;

	/**
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the timer is cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Outcome: the rule declares no window, or the case has no id.
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Outcome: the engine is absent or refused.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Build the service.
	 *
	 * @param SettingsService $settingsService Resolves the engine.
	 * @param LoggerInterface $logger          Logs engine refusals.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The window a rule declares, or null when it declares none (or a broken one).
	 *
	 * @param array<string, mixed> $rule The routing rule.
	 *
	 * @return array{value: int, unit: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function windowOf(array $rule): ?array {
		$window = ($rule['takeBackAfter'] ?? null);
		if (is_array($window) === false) {
			return null;
		}

		$value = $window['value'] ?? null;
		$unit  = (string) ($window['unit'] ?? '');
		if (is_numeric($value) === false || (int) $value < 1 || (int) $value > self::MAX_VALUE || in_array($unit, self::UNITS, true) === false) {
			return null;
		}

		return ['value' => (int) $value, 'unit' => $unit];
	}//end windowOf()

	/**
	 * Arm the window for a case just routed to someone.
	 *
	 * Any earlier window of the case is cancelled first, so a case routed twice
	 * carries one window: the one for the person who holds it now.
	 *
	 * @param string            $caseId   The case id.
	 * @param array<string, mixed> $rule  The routing rule that routed it.
	 * @param string            $routedTo The person it was routed to.
	 * @param DateTimeImmutable $routedAt When.
	 *
	 * @return string ARMED, SKIPPED or UNAVAILABLE.
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function arm(string $caseId, array $rule, string $routedTo, DateTimeImmutable $routedAt): string {
		$window = $this->windowOf(rule: $rule);
		if ($caseId === '' || $routedTo === '' || $window === null) {
			return self::SKIPPED;
		}

		$cancelled = $this->cancel(caseId: $caseId, reason: 'Superseded: the case was routed again');
		if ($cancelled === self::UNAVAILABLE) {
			return self::UNAVAILABLE;
		}

		$config = [
			'subjectType'     => 'object',
			'subjectUuid'     => $caseId.self::SUBJECT_SUFFIX,
			'appId'           => 'dossiq',
			'title'           => 'Terugnemen als niet opgepakt',
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => $window,
			'escalationRules' => [
				[
					'trigger'        => 'slaBreached',
					'offset'         => 0,
					'offsetUnit'     => 'hours',
					'notifyRole'     => [],
					'escalateToRole' => [],
					'priority'       => 'normal',
					'message'        => 'zaak-teruggenomen',
					'openIncident'   => false,
				],
			],
			'anchorEvent'     => 'case_routed',
			'anchorEventAt'   => $routedAt,
			'metadata'        => [
				'source'   => self::METADATA_SOURCE,
				'caseId'   => $caseId,
				'routedTo' => $routedTo,
				'routedAt' => $routedAt->format(DATE_ATOM),
			],
		];

		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->arm(config: $config, actor: null);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq routing: the engine refused the take-back timer', ['case' => $caseId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end arm()

	/**
	 * Cancel the case's take-back timer (and only that one).
	 *
	 * @param string $caseId The case id.
	 * @param string $reason Why: accepted, superseded, taken back.
	 *
	 * @return string CANCELLED, SKIPPED or UNAVAILABLE.
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function cancel(string $caseId, string $reason): string {
		if ($caseId === '') {
			return self::SKIPPED;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->cancelForSubject(subjectType: 'object', subjectUuid: $caseId.self::SUBJECT_SUFFIX, reason: $reason, actor: null);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq routing: the engine refused to cancel the take-back timer', ['case' => $caseId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return self::CANCELLED;
	}//end cancel()

	/**
	 * The engine, or null when OpenRegister does not ship it.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()
}//end class
