<?php

/**
 * Dossiq mandate matrix.
 *
 * A tenant's mandate as rows of `{role: {action: bool}}`: which row is in
 * force at a moment, the matrix it carries, and whether it grants a role an
 * action. Missing entries deny.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Tenant
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
 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Tenant;

/**
 * Reads a mandate matrix; fails closed.
 *
 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
 */
final class MandateMatrix {

	/**
	 * Default deny-everything matrix (fail-closed fallback).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const DEFAULT_DENY_MATRIX = [];

	/**
	 * Check whether the matrix authorises (role, action).
	 *
	 * The matrix layout is `{role: {action: bool}}`. A wildcard role `*` or
	 * action `*` is honoured. Missing entries default to false (fail-closed).
	 *
	 * @param array<string, array<string, bool>> $matrix Active mandate matrix.
	 * @param string $role Resolved user role.
	 * @param string $action Requested action.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function allows(array $matrix, string $role, string $action): bool {
		return self::anyGrants(candidates: self::entriesFor(matrix: $matrix, role: $role), action: $action);
	}//end allows()

	/**
	 * The matrix entries that apply to a role: its own, then the wildcard role's.
	 *
	 * @param array<string, array<string, bool>> $matrix Active mandate matrix.
	 * @param string                             $role   Resolved user role.
	 *
	 * @return array<int, array<string, bool>> The entries.
	 */
	private static function entriesFor(array $matrix, string $role): array {
		$roleEntry = ($matrix[$role] ?? null);
		$wildcardEntry = ($matrix['*'] ?? null);

		$candidates = [];
		if (is_array($roleEntry) === true) {
			$candidates[] = $roleEntry;
		}

		if (is_array($wildcardEntry) === true) {
			$candidates[] = $wildcardEntry;
		}

		return $candidates;
	}//end entriesFor()

	/**
	 * Whether any entry grants the action, by name or by wildcard.
	 *
	 * @param array<int, array<string, bool>> $candidates The entries that apply.
	 * @param string                          $action     Requested action.
	 *
	 * @return bool True when one grants it.
	 */
	private static function anyGrants(array $candidates, string $action): bool {
		foreach ($candidates as $entry) {
			if (($entry[$action] ?? false) === true) {
				return true;
			}

			if (($entry['*'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end anyGrants()

	/**
	 * Pick the mandate row whose effective window contains "now".
	 *
	 * @param array<int, array<string, mixed>> $rows The tenantMandate rows, read as arrays.
	 *
	 * @return array<string, mixed>|null The active row, or null when none applies.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function activeRow(array $rows): ?array {
		$now = time();
		foreach ($rows as $row) {
			if (self::isEffectiveAt(row: $row, moment: $now) === true) {
				return $row;
			}
		}

		return null;
	}//end activeRow()

	/**
	 * Whether a mandate row's effective window holds the moment.
	 *
	 * A missing bound is open-ended; a bound that does not parse makes the row apply never.
	 *
	 * @param array<string, mixed> $row    The tenantMandate row.
	 * @param int                  $moment The moment, a Unix timestamp.
	 *
	 * @return bool True when the row is in force at the moment.
	 */
	private static function isEffectiveAt(array $row, int $moment): bool {
		$from = strtotime((string)($row['effectiveFrom'] ?? '1970-01-01'));
		$to = strtotime((string)($row['effectiveTo'] ?? '2099-12-31'));

		return ($from !== false && $to !== false && $from <= $moment && $moment <= $to);
	}//end isEffectiveAt()

	/**
	 * Normalise the `matrix` field of an active mandate row.
	 *
	 * @param mixed $matrixField The raw matrix value.
	 *
	 * @return array<string, array<string, bool>> The resolved matrix.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function normalise(mixed $matrixField): array {
		if (is_array($matrixField) === true) {
			return $matrixField;
		}

		if (is_string($matrixField) === true) {
			$decoded = json_decode($matrixField, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return self::DEFAULT_DENY_MATRIX;
		}

		// Fallback: a default role-action matrix when the active mandate row
		// does not embed one. Mirrors the common municipal mandate template.
		return [
			'tenant_admin' => ['*' => true],
			'case_handler' => ['create' => true, 'edit' => true, 'status_update' => true],
			'viewer' => [],
		];
	}//end normalise()
}//end class
