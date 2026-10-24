<?php

/**
 * Dossiq case type rights by role.
 *
 * A case type's rights as board DqZaaktypeOverzicht draws them on the Overview:
 * one row per role, saying which cases it sees, whether it handles and
 * decides, and what it may do with documents. The stored rights matrix is
 * department x role x confidentiality x verbs; this reads it per role. The
 * full matrix stays on the Rights tab.
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
 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCP\IL10N;

/**
 * Reads a case type's rights matrix per role.
 *
 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
 */
class CaseTypeRightsByRole {

	/**
	 * Department values that stand for every department.
	 *
	 * @var array<int, string>
	 */
	private const EVERY_DEPARTMENT = ['*', 'all', 'alle'];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver $resolver Reads the case type with its inherited parts.
	 * @param IL10N            $l10n     Words the cells.
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * One row per role: `{ role, seeCases, handle, decide, documents }`.
	 *
	 * See cases names the departments whose cases the role reads or lists (All
	 * for every department); handle is the update verb, decide the decide verb;
	 * documents is All with delete, Add with create, Read with read. A row past
	 * its `until` date grants nothing.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<int, array<string, string>>|null The rows, or null when there is no such case type.
	 *
	 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
	 */
	public function rowsFor(string $caseTypeId): ?array {
		$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return null;
		}

		$byRole = [];
		foreach ($this->currentRows(matrix: $caseType['rightsMatrix'] ?? []) as $row) {
			$role = (string) $row['role'];
			$byRole[$role] ??= ['departments' => [], 'verbs' => []];
			$verbs = array_values(array_filter((array) $row['actions'], 'is_string'));
			if (array_intersect($verbs, ['read', 'list']) !== []) {
				$byRole[$role]['departments'][] = (string) ($row['department'] ?? '');
			}

			$byRole[$role]['verbs'] = array_merge($byRole[$role]['verbs'], $verbs);
		}

		$rows = [];
		foreach ($byRole as $role => $grant) {
			$verbs = array_unique($grant['verbs']);
			$rows[] = [
				'role'      => $role,
				'seeCases'  => $this->seeCases(departments: $grant['departments']),
				'handle'    => $this->yesNo(granted: in_array('update', $verbs, true)),
				'decide'    => $this->yesNo(granted: in_array('decide', $verbs, true)),
				'documents' => $this->documents(verbs: $verbs),
			];
		}

		return $rows;
	}//end rowsFor()

	/**
	 * The matrix rows that grant something today.
	 *
	 * @param mixed $matrix The stored matrix.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function currentRows(mixed $matrix): array {
		if (is_array($matrix) === false) {
			return [];
		}

		$today = (new DateTimeImmutable())->format('Y-m-d');
		return array_values(
			array_filter(
				$matrix,
				static function (mixed $row) use ($today): bool {
					if (is_array($row) === false || is_string($row['role'] ?? null) === false || $row['role'] === '') {
						return false;
					}

					$until = $row['until'] ?? null;
					return is_string($until) === false || $until === '' || substr($until, 0, 10) >= $today;
				}
			)
		);
	}//end currentRows()

	/**
	 * The departments a role sees cases of, or All, or No.
	 *
	 * @param array<int, string> $departments The departments of the rows that read or list.
	 *
	 * @return string
	 */
	private function seeCases(array $departments): string {
		if ($departments === []) {
			return $this->l10n->t('No');
		}

		foreach ($departments as $department) {
			if (in_array(strtolower(trim($department)), self::EVERY_DEPARTMENT, true) === true) {
				return $this->l10n->t('All (cases)');
			}
		}

		return implode(', ', array_values(array_unique(array_filter($departments, static fn (string $name): bool => $name !== ''))));
	}//end seeCases()

	/**
	 * What a role may do with documents: All, Add, Read or No.
	 *
	 * @param array<int, string> $verbs The verbs granted.
	 *
	 * @return string
	 */
	private function documents(array $verbs): string {
		if (in_array('delete', $verbs, true) === true) {
			return $this->l10n->t('All');
		}

		if (in_array('create', $verbs, true) === true) {
			return $this->l10n->t('Add');
		}

		if (in_array('read', $verbs, true) === true) {
			return $this->l10n->t('Read');
		}

		return $this->l10n->t('No');
	}//end documents()

	/**
	 * Yes or No.
	 *
	 * @param bool $granted Whether the verb is granted.
	 *
	 * @return string
	 */
	private function yesNo(bool $granted): string {
		if ($granted === true) {
			return $this->l10n->t('Yes');
		}

		return $this->l10n->t('No');
	}//end yesNo()
}//end class
