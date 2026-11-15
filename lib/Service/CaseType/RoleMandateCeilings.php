<?php

/**
 * Dossiq mandate ceilings per role for one case type.
 *
 * The Decide column of a case type's rights by role: for each role, the
 * highest ceiling of the mandates it holds for this case type, Unlimited
 * when one of them has no ceiling, No without a mandate, Unknown when the
 * mandate register cannot be read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\MandaatCheckService;
use OCP\IL10N;

/**
 * Reads the mandate register into one Decide cell per role.
 *
 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RoleMandateCeilings {

	/**
	 * Constructor.
	 *
	 * @param MandaatCheckService $mandates Reads the mandates that apply to the case type.
	 * @param OrganisationRoles   $roles    Knows a role by id and by name.
	 * @param IL10N               $l10n     Words the cells.
	 */
	public function __construct(
		private readonly MandaatCheckService $mandates,
		private readonly OrganisationRoles $roles,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The Decide cell of each role.
	 *
	 * @param array<string, mixed> $caseType   The effective case type.
	 * @param string               $caseTypeId Its id.
	 * @param array<int, mixed>    $roles      The roles of the card, as the matrix names them.
	 *
	 * @return array<string, string> The cell per role.
	 *
	 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function byRole(array $caseType, string $caseTypeId, array $roles): array {
		$cells = [];
		try {
			$mandates = $this->forCaseType(
				mandates: $this->mandates->activeRows(date: new DateTimeImmutable(), context: ['caseType' => $caseTypeId]),
				keys: [$caseTypeId, (string)($caseType['slug'] ?? ''), (string)($caseType['identifier'] ?? '')]
			);
			$known = $this->roles->all();
		} catch (RefusedException $e) {
			foreach ($roles as $role) {
				$cells[(string)$role] = $this->l10n->t('Unknown');
			}

			return $cells;
		}

		foreach ($roles as $role) {
			$keys = $this->roles->keysOf(role: (string)$role, roles: $known);
			$held = array_filter($mandates, static fn (array $mandate): bool => in_array((string)($mandate['mandateeRole'] ?? ''), $keys, true));
			$cells[(string)$role] = $this->ceiling(mandates: array_values($held));
		}

		return $cells;
	}//end byRole()

	/**
	 * The mandates for this case type: those naming one of its keys, or none.
	 *
	 * @param array<int, array<string, mixed>> $mandates The active mandates.
	 * @param array<int, string>               $keys     The case type's id, slug and identifier.
	 *
	 * @return array<int, array<string, mixed>> The mandates that apply.
	 */
	private function forCaseType(array $mandates, array $keys): array {
		$keys = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));

		return array_values(
			array_filter(
				$mandates,
				static function (array $mandate) use ($keys): bool {
					$caseTypes = (array)($mandate['terms']['caseTypes'] ?? []);
					return $caseTypes === [] || array_intersect($keys, $caseTypes) !== [];
				}
			)
		);
	}//end forCaseType()

	/**
	 * The highest ceiling of a role's mandates, worded.
	 *
	 * @param array<int, array<string, mixed>> $mandates The role's mandates.
	 *
	 * @return string No, Unlimited, or Up to the highest ceiling in whole euros.
	 */
	private function ceiling(array $mandates): string {
		if ($mandates === []) {
			return $this->l10n->t('No');
		}

		$highest = 0;
		foreach ($mandates as $mandate) {
			$cents = ($mandate['terms']['plafondCents'] ?? null);
			if (is_int($cents) === false) {
				return $this->l10n->t('Unlimited');
			}

			$highest = max($highest, $cents);
		}

		return $this->l10n->t('Up to %s', ['€ ' . number_format(intdiv($highest, 100), 0, ',', '.')]);
	}//end ceiling()
}//end class
