<?php

/**
 * Dossiq mandate rows.
 *
 * Questions asked of mandate register rows: is a row in force on a date, does
 * it cover a decision and case type, is one active for an organisation, and
 * which rows and role assignment apply to a role.
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

/**
 * Reads mandate and role-assignment rows; holds no state.
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
 */
final class MandateRows {

	/**
	 * Check whether a row's validFrom/validUntil window covers the given date.
	 *
	 * @param array<string, mixed> $row Row carrying validFrom/validUntil.
	 * @param string $dateStr Date in Y-m-d form.
	 *
	 * @return bool True when the row is temporally valid on that date.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function isValidOn(array $row, string $dateStr): bool {
		$validFrom = (string)($row['validFrom'] ?? '1970-01-01');
		$validUntil = (string)($row['validUntil'] ?? '');
		if ($validFrom > $dateStr) {
			return false;
		}

		if ($validUntil !== '' && $validUntil < $dateStr) {
			return false;
		}

		return true;
	}//end isValidOn()

	/**
	 * Check a mandaat's decisionTypes/caseTypes voorwaarden against the request.
	 *
	 * An empty list means "no restriction"; an empty case type skips the
	 * case-type filter entirely.
	 *
	 * @param array<string, mixed>                          $row     Mandaat row.
	 * @param array{decisionType: string, caseType: string} $request The decision type slug and the case type slug (may be empty).
	 *
	 * @return bool True when the mandaat applies to the pair.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function matchesTypes(array $row, array $request): bool {
		$decisionType = $request['decisionType'];
		$caseType = $request['caseType'];
		$voorw = (array)($row['terms'] ?? []);
		$decTypes = (array)($voorw['decisionTypes'] ?? []);
		if (count($decTypes) > 0 && in_array($decisionType, $decTypes, true) === false) {
			return false;
		}

		$caseTypes = (array)($voorw['caseTypes'] ?? []);
		if ($caseType !== '' && count($caseTypes) > 0 && in_array($caseType, $caseTypes, true) === false) {
			return false;
		}

		return true;
	}//end matchesTypes()

	/**
	 * Whether any row is an active mandate of the organisation, valid on the date.
	 *
	 * @param array<int, array<string, mixed>>             $rows  The mandate rows.
	 * @param array{organisation: string, dateStr: string} $scope `organisation` the organisation UUID; `dateStr` the date, `Y-m-d`.
	 *
	 * @return bool True when one is.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
	 */
	public function anyActiveFor(array $rows, array $scope): bool {
		[$organisation, $dateStr] = [$scope['organisation'], $scope['dateStr']];
		foreach ($rows as $row) {
			if ((string)($row['status'] ?? '') === 'active'
				&& self::organisationOf(row: $row) === $organisation
				&& $this->isValidOn(row: $row, dateStr: $dateStr) === true
			) {
				return true;
			}
		}

		return false;
	}//end anyActiveFor()

	/**
	 * The organisation that owns a row: its `@self` organisation, else its own field.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The organisation UUID, or ''.
	 */
	private static function organisationOf(array $row): string {
		$organisation = ($row['@self']['organisation'] ?? ($row['organisation'] ?? ''));
		if (is_array($organisation) === true) {
			$organisation = ($organisation['uuid'] ?? ($organisation['id'] ?? ''));
		}

		if (is_scalar($organisation) === false) {
			return '';
		}

		return (string)$organisation;
	}//end organisationOf()

	/**
	 * The mandates a role may use: those given to the role, and those given to no role.
	 *
	 * Each carries `unilateral`: whether it was given to this role.
	 *
	 * @param array<int, array<string, mixed>> $rows   The applicable mandates.
	 * @param string                           $roleId The role id.
	 *
	 * @return array<int, array<string, mixed>> The mandates.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function forRole(array $rows, string $roleId): array {
		$out = [];
		foreach ($rows as $row) {
			$mandateRoleId = (string)($row['mandateeRole'] ?? '');
			if ($mandateRoleId !== '' && $mandateRoleId !== $roleId) {
				continue;
			}

			$row['unilateral'] = ($mandateRoleId === $roleId);
			$out[] = $row;
		}

		return $out;
	}//end forRole()

	/**
	 * The rows valid on the day.
	 *
	 * @param array<int, array<string, mixed>> $rows    The rows.
	 * @param string                           $dateStr The day (Y-m-d).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function validOn(array $rows, string $dateStr): array {
		$active = [];
		foreach ($rows as $row) {
			if ($this->isValidOn(row: $row, dateStr: $dateStr) === false) {
				continue;
			}

			$active[] = $row;
		}

		return $active;
	}//end validOn()

	/**
	 * The assignment that counts: primair first, then waarnemer, then tijdelijk.
	 *
	 * @param array<int, array<string, mixed>> $active The valid assignments, at least one.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function firstByAllocation(array $active): array {
		$order = ['primair' => 0, 'observer' => 1, 'tijdelijk' => 2];
		usort(
			$active,
			static fn (array $a, array $b): int
				=> ($order[(string)($a['allocationType'] ?? 'primair')] ?? 99) <=> ($order[(string)($b['allocationType'] ?? 'primair')] ?? 99)
		);

		return $active[0];
	}//end firstByAllocation()
}//end class
