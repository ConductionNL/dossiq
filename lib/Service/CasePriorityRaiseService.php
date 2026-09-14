<?php

/**
 * Dossiq case priority raise service.
 *
 * REQ-PRI-04: as a case's statutory term approaches, a declared rule raises its
 * priority, and never lowers it.
 *
 * WHAT THIS IS NOT. It is not a rules engine, and it deliberately cannot
 * become one. It evaluates no condition and owns no clock: OpenRegister's flow
 * timers decide when a rung fires, this is handed the threshold that fired, and
 * the only judgement it makes is a lookup in
 * `lib/Settings/priority_raise_rule.json`. The declaration names OpenRegister
 * as the engine and says so in its own text.
 *
 * THE RAISE IS A FLOOR, NOT A VALUE. Writing the raised priority straight into
 * `case.priority` would last exactly until the next save, when the matrix
 * derived the old answer again and the case quietly sank back down the queue.
 * So the rule writes `priorityFloor`, the derivation lifts the derived answer
 * to it on every save afterwards, and an extended term leaves the floor exactly
 * where it was. That is what makes "raise and never lower" a property of the
 * stored data rather than a promise about one code path.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the declared term rule's floor to a case, and reads back its priority.
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
class CasePriorityRaiseService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register, schema and the object service.
	 * @param CasePriorityService $priorityService The declaration and the ordering.
	 * @param LoggerInterface     $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CasePriorityService $priorityService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise a case to the floor the declared rule sets for this threshold.
	 *
	 * @param string  $caseId    The case the termijn instance belongs to.
	 * @param integer $threshold The threshold bucket that fired (14 / 7 / 2 / 0).
	 *
	 * @return string The case's priority after the rule ran, or the empty
	 *                string when the case cannot be read.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function raiseForThreshold(string $caseId, int $threshold): string {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return '';
		}

		$current = (string)($case['priority'] ?? '');
		$floor = $this->priorityService->termRaiseFloor(daysToTerm: $threshold);
		if ($floor === '') {
			return $current;
		}

		$raised = $this->priorityService->raise(
			current: (string)($case['priorityFloor'] ?? ''),
			floor: $floor
		);

		if ($raised === (string)($case['priorityFloor'] ?? '')) {
			// The case already stands at or above this floor. Writing it again
			// would be an audit row saying nothing happened.
			return $current;
		}

		$rule = (string)($this->priorityService->termRaiseRule()['id'] ?? 'case-priority-term-raise');

		return $this->writeFloor(caseId: $caseId, floor: $raised, rule: $rule, fallback: $current);
	}//end raiseForThreshold()

	/**
	 * The priority a case currently carries.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string The stored priority, or the empty string when unreadable.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function priorityOf(string $caseId): string {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return '';
		}

		return (string)($case['priority'] ?? '');
	}//end priorityOf()

	/**
	 * Write the floor, and report what the case reads afterwards.
	 *
	 * @param string $caseId   The case.
	 * @param string $floor    The floor to store.
	 * @param string $rule     The declared rule that asked for it.
	 * @param string $fallback What to report when the write cannot be read back.
	 *
	 * @return string The case's priority after the write.
	 */
	private function writeFloor(string $caseId, string $floor, string $rule, string $fallback): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return $fallback;
		}

		try {
			$stored = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					id: $caseId,
					changes: ['priorityFloor' => $floor, 'priorityRaisedBy' => $rule],
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the term rule could not raise a case priority',
				['case' => $caseId, 'floor' => $floor, 'error' => $e->getMessage()]
			);
			return $fallback;
		}

		$this->logger->info(
			'Dossiq: the term rule raised a case priority',
			['case' => $caseId, 'floor' => $floor, 'rule' => $rule]
		);

		if (is_array($stored) === false) {
			return $this->priorityService->raise(current: $fallback, floor: $floor);
		}

		return (string)($stored['priority'] ?? $this->priorityService->raise(current: $fallback, floor: $floor));
	}//end writeFloor()

	/**
	 * Read one case, or null when there is no case to read.
	 *
	 * NO `catch (Throwable) { return null; }` HERE, DELIBERATELY. A case that
	 * does not exist already comes back as null from `findObjectAsArray()`
	 * without anything being thrown, so the only thing a catch here could
	 * swallow is the store itself failing — and swallowing that would report a
	 * case as having no priority when what actually happened is that nobody
	 * could read it. The escalation path decides what to do about a store
	 * failure, because it is the one that knows a termijn notification must go
	 * out either way. See `DeadlineEscalationService::notifyThreshold()`.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null The case, or null when there is none.
	 */
	private function readCase(string $caseId): ?array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
			)
		);
	}//end readCase()
}//end class
