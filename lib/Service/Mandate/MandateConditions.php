<?php

/**
 * Dossiq mandate conditions.
 *
 * The conditions a mandate sets on a case: its ceiling in cents and whether it
 * may be subdelegated. The ceiling is checked first and its reason wins.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mandate
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
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mandate;

use OCA\Dossiq\Service\MandaatCheckService;

/**
 * Checks a mandate's conditions against a case's properties.
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
 */
final class MandateConditions {

	/**
	 * Evaluate voorwaarden (plafond, subdelegatie) against the case properties.
	 *
	 * @param array<string, mixed> $mandate Mandaat row.
	 * @param array<string, mixed> $caseProperties Case properties (e.g. bedragCents, subdelegatieRequested).
	 *
	 * @return array{passed:bool, reason:string, failedConditions:array<int,string>}
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function evaluate(array $mandate, array $caseProperties): array {
		$voorw = (array)($mandate['terms'] ?? []);
		$failed = [];
		$redenen = [];

		// Plafond check (cents).
		if ($this->plafondExceeded(terms: $voorw, caseProperties: $caseProperties) === true) {
			$failed[] = 'plafond';
			$redenen[] = MandaatCheckService::REDEN_PLAFOND_OVERSCHREDEN;
		}

		// Subdelegation check.
		if ($this->subdelegatieDenied(terms: $voorw, caseProperties: $caseProperties) === true) {
			$failed[] = 'subdelegatie';
			$redenen[] = MandaatCheckService::REDEN_SUBDELEGATIE_NIET_TOEGESTAAN;
		}

		if (count($failed) === 0) {
			return ['passed' => true, 'reason' => '', 'failedConditions' => []];
		}

		// The most-specific failure wins; plafond is evaluated first and so
		// takes precedence over subdelegatie.
		$effectiveReason = ($redenen[0] ?? MandaatCheckService::REDEN_NIET_BEVOEGD);

		return ['passed' => false, 'reason' => $effectiveReason, 'failedConditions' => $failed];
	}//end evaluate()

	/**
	 * Check whether the case amount exceeds the mandaat plafond.
	 *
	 * Both the plafond and the case amount must be present; when either is
	 * absent the plafond is not applicable and the check passes.
	 *
	 * @param array<string, mixed> $terms Mandaat voorwaarden.
	 * @param array<string, mixed> $caseProperties Case properties.
	 *
	 * @return bool True when the plafond is exceeded.
	 */
	private function plafondExceeded(array $terms, array $caseProperties): bool {
		if (isset($terms['plafondCents'], $caseProperties['bedragCents']) === false) {
			return false;
		}

		$plafond = (int)$terms['plafondCents'];
		$amount = (int)$caseProperties['bedragCents'];

		return ($amount > $plafond);
	}//end plafondExceeded()

	/**
	 * Check whether a requested subdelegation is denied by the voorwaarden.
	 *
	 * Only evaluated when the case explicitly requests subdelegation.
	 *
	 * @param array<string, mixed> $terms Mandaat voorwaarden.
	 * @param array<string, mixed> $caseProperties Case properties.
	 *
	 * @return bool True when subdelegation was requested but is not allowed.
	 */
	private function subdelegatieDenied(array $terms, array $caseProperties): bool {
		if (($caseProperties['subdelegatieRequested'] ?? false) !== true) {
			return false;
		}

		return ((bool)($terms['subdelegatie'] ?? false) === false);
	}//end subdelegatieDenied()
}//end class
