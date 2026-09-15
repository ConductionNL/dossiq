<?php

/**
 * One process step, designed once, used by several case types.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use Psr\Log\LoggerInterface;

/**
 * A step several case types point at, rather than each keeping a copy.
 *
 * 🔑 A REFERENCE, AND THAT IS THE WHOLE DIFFERENCE FROM A COPY. Copying a step
 * into four case types means changing its lead time in four places, and the
 * fourth is the one nobody finds. Pointing at it means changing it once.
 *
 * 🔑 BOTH DIRECTIONS ARE READABLE, WHICH IS WHAT MAKES THE DELETE SAFE. A step
 * that only knows its own name cannot tell an administrator who would lose it,
 * so deleting one would be a decision made blind. `usedBy()` answers from the
 * case types, and `delete()` refuses while the answer is not empty and names
 * the case type that stopped it.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
class ReusableProcessStepService {

	/**
	 * The app config key naming the reusable step schema.
	 */
	public const STEPS = 'reusable_step_schema';

	/**
	 * The app config key naming the case type schema.
	 */
	public const CASE_TYPES = 'case_type_schema';

	/**
	 * The case type property holding the references.
	 */
	public const PROPERTY = 'reusableSteps';

	/**
	 * Constructor.
	 *
	 * @param StarterStore    $store  The OpenRegister seam.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The reusable steps one case type uses.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return array<int, array<string, mixed>>|null The steps, or null when unreachable.
	 */
	public function stepsOf(string $caseTypeId): ?array {
		$caseType = $this->store->row(configKey: self::CASE_TYPES, id: $caseTypeId);
		if ($caseType === null) {
			return null;
		}

		$ids = ($caseType[self::PROPERTY] ?? []);
		if (is_array($ids) === false) {
			return [];
		}

		$steps = [];
		foreach ($ids as $id) {
			$step = $this->store->row(configKey: self::STEPS, id: (string)$id);
			if ($step !== null) {
				$steps[] = $step;
			}
		}

		return $steps;
	}//end stepsOf()

	/**
	 * The case types that use one reusable step.
	 *
	 * @param string $stepId The step's id.
	 *
	 * @return array<int, array{id: string, title: string}>|null The case types,
	 *                                                           or null when unreachable.
	 */
	public function usedBy(string $stepId): ?array {
		$caseTypes = $this->store->rows(configKey: self::CASE_TYPES);
		if ($caseTypes === null) {
			return null;
		}

		$users = [];
		foreach ($caseTypes as $caseType) {
			$ids = ($caseType[self::PROPERTY] ?? []);
			if (is_array($ids) === false) {
				continue;
			}

			foreach ($ids as $id) {
				if ((string)$id !== $stepId) {
					continue;
				}

				$users[] = [
					'id' => $this->store->idOf(row: $caseType),
					'title' => (string)($caseType['title'] ?? ''),
				];
				break;
			}
		}

		return $users;
	}//end usedBy()

	/**
	 * Point a case type at a reusable step.
	 *
	 * @param string $caseTypeId The case type's id.
	 * @param string $stepId     The step's id.
	 *
	 * @return boolean True when the reference is there afterwards.
	 */
	public function attach(string $caseTypeId, string $stepId): bool {
		$caseType = $this->store->row(configKey: self::CASE_TYPES, id: $caseTypeId);
		if ($caseType === null || $this->store->row(configKey: self::STEPS, id: $stepId) === null) {
			return false;
		}

		$ids = ($caseType[self::PROPERTY] ?? []);
		if (is_array($ids) === false) {
			$ids = [];
		}

		$ids = array_values(array_unique(array_map(static fn (mixed $id): string => (string)$id, $ids)));
		if (in_array($stepId, $ids, true) === true) {
			return true;
		}

		$ids[] = $stepId;
		$caseType[self::PROPERTY] = $ids;

		return ($this->store->save(configKey: self::CASE_TYPES, payload: $caseType, id: $caseTypeId) !== null);
	}//end attach()

	/**
	 * Remove a reusable step, unless a case type still uses it.
	 *
	 * @param string $stepId The step's id.
	 *
	 * @return array{ok: bool, reason: string, usedBy: string} What happened, and
	 *         which case type stopped it when it did not.
	 */
	public function delete(string $stepId): array {
		$users = $this->usedBy(stepId: $stepId);
		if ($users === null) {
			return ['ok' => false, 'reason' => 'unavailable', 'usedBy' => ''];
		}

		if ($users !== []) {
			return ['ok' => false, 'reason' => 'in_use', 'usedBy' => $users[0]['title']];
		}

		if ($this->store->delete(configKey: self::STEPS, id: $stepId) === false) {
			return ['ok' => false, 'reason' => 'not_found', 'usedBy' => ''];
		}

		$this->logger->info('Dossiq starter: a reusable step was deleted', ['step' => $stepId]);

		return ['ok' => true, 'reason' => '', 'usedBy' => ''];
	}//end delete()
}//end class
