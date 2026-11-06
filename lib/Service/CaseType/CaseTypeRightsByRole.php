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
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;

/**
 * Reads a case type's rights matrix per role.
 *
 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */
class CaseTypeRightsByRole {

	/**
	 * Department values that stand for every department.
	 *
	 * @var array<int, string>
	 */
	private const EVERY_DEPARTMENT = ['*', 'all', 'alle'];

	/**
	 * Rights verbs that change a case.
	 *
	 * @var array<int, string>
	 */
	private const EDIT_VERBS = ['create', 'update', 'delete', 'handle'];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver    $resolver  Reads the case type with its inherited parts.
	 * @param IL10N               $l10n      Words the cells.
	 * @param MandaatCheckService $mandates  Reads the mandates that apply to the case type.
	 * @param OrganisationRoles   $roles     Knows a role by id and by name.
	 * @param TranslatedText|null $text      Reads a status name, a language map, in the reader's language.
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly IL10N $l10n,
		private readonly MandaatCheckService $mandates,
		private readonly OrganisationRoles $roles,
		private readonly ?TranslatedText $text=null,
	) {
	}//end __construct()

	/**
	 * One row per role: `{ role, seeCases, handle, decide, documents }`.
	 *
	 * See cases names the departments whose cases the role reads (Own team for
	 * `$ownTeam`, All for every department). Handle is All phases, the status
	 * names the role's edit rights are limited to, or No. Decide reads the
	 * mandate register: the highest ceiling of the role's mandates for this case
	 * type, Unlimited without a ceiling, No without a mandate. Documents is All
	 * (delete), Add or Read, from `documentActions` or, without it, the verbs.
	 * A row past its `until` grants nothing.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<int, array<string, string>>|null The rows, or null when there is no such case type.
	 *
	 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function rowsFor(string $caseTypeId): ?array {
		$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return null;
		}

		$byRole = [];
		foreach ($this->currentRows(matrix: $caseType['rightsMatrix'] ?? []) as $row) {
			$role = (string)$row['role'];
			$byRole[$role] ??= ['departments' => [], 'edit' => [], 'documents' => []];
			$verbs = array_values(array_filter((array)($row['actions'] ?? []), 'is_string'));
			if (array_intersect($verbs, ['read', 'list']) !== []) {
				$byRole[$role]['departments'][] = (string)($row['department'] ?? '');
			}

			if (array_intersect($verbs, self::EDIT_VERBS) !== []) {
				$byRole[$role]['edit'][] = array_values(array_filter((array)($row['statuses'] ?? []), 'is_string'));
			}

			$byRole[$role]['documents'] = array_merge($byRole[$role]['documents'], $this->documentRights(row: $row, verbs: $verbs));
		}

		$statusNames = $this->statusNames(caseTypeId: $caseTypeId);
		$decide = $this->decideByRole(caseType: $caseType, caseTypeId: $caseTypeId, roles: array_keys($byRole));

		$rows = [];
		foreach ($byRole as $role => $grant) {
			$rows[] = [
				'role'      => (string)$role,
				'seeCases'  => $this->seeCases(departments: $grant['departments']),
				'handle'    => $this->handle(edits: $grant['edit'], statusNames: $statusNames),
				'decide'    => $decide[(string)$role],
				'documents' => $this->documents(rights: $grant['documents']),
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
	 * The departments a role sees cases of: Own team, a list, All, or No.
	 *
	 * @param array<int, string> $departments The departments of the rows that read.
	 *
	 * @return string
	 */
	private function seeCases(array $departments): string {
		if ($departments === []) {
			return $this->l10n->t('No');
		}

		$names = [];
		foreach ($departments as $department) {
			$department = trim($department);
			if (in_array(strtolower($department), self::EVERY_DEPARTMENT, true) === true) {
				return $this->l10n->t('All (cases)');
			}

			if ($department === CaseTypeRightsMatrixRows::OWN_TEAM) {
				$department = $this->l10n->t('Own team');
			}

			if ($department !== '') {
				$names[] = $department;
			}
		}

		return implode(', ', array_values(array_unique($names)));
	}//end seeCases()

	/**
	 * Where a role may edit: All phases, the status names, or No.
	 *
	 * @param array<int, array<int, string>> $edits       Per editing row, its statuses (empty is every status).
	 * @param array<string, string>          $statusNames Status names by id.
	 *
	 * @return string
	 */
	private function handle(array $edits, array $statusNames): string {
		if ($edits === []) {
			return $this->l10n->t('No');
		}

		$names = [];
		foreach ($edits as $statuses) {
			if ($statuses === []) {
				return $this->l10n->t('All phases');
			}

			foreach ($statuses as $status) {
				$names[] = ($statusNames[$status] ?? $status);
			}
		}

		return implode(', ', array_values(array_unique($names)));
	}//end handle()

	/**
	 * The status names of the case type, by id.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<string, string>
	 */
	private function statusNames(string $caseTypeId): array {
		$names = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $status) {
			$id = (string)($status['id'] ?? ($status['@self']['id'] ?? ''));
			if ($id !== '') {
				$names[$id] = $this->nameOf(name: ($status['name'] ?? null), fallback: $id);
			}
		}

		return $names;
	}//end statusNames()

	/**
	 * A status name as text: a language map in the reader's language.
	 *
	 * @param mixed  $name     The stored name, a string or a language map.
	 * @param string $fallback What to say when it has no text.
	 *
	 * @return string The name.
	 */
	private function nameOf(mixed $name, string $fallback): string {
		$text = trim(($this->text ?? new TranslatedText(l10n: $this->l10n))->forReader(value: $name));
		if ($text === '') {
			return $fallback;
		}

		return $text;
	}//end nameOf()

	/**
	 * The Decide cell per role, from the mandate register.
	 *
	 * @param array<string, mixed> $caseType   The case type.
	 * @param string               $caseTypeId Its id.
	 * @param array<int, string>   $roles      The roles named in the rights rows.
	 *
	 * @return array<string, string> The cell by role.
	 */
	private function decideByRole(array $caseType, string $caseTypeId, array $roles): array {
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
	}//end decideByRole()

	/**
	 * The mandates that apply to the case type: no case types named, or one of its keys.
	 *
	 * @param array<int, array<string, mixed>> $mandates The active mandates.
	 * @param array<int, string>               $keys     The case type's id, slug and identifier.
	 *
	 * @return array<int, array<string, mixed>>
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
	 * The highest ceiling of a role's mandates: Up to an amount, Unlimited, or No.
	 *
	 * @param array<int, array<string, mixed>> $mandates The role's mandates.
	 *
	 * @return string
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

	/**
	 * What one rights row lets the role do with documents.
	 *
	 * @param array<string, mixed> $row   The rights row.
	 * @param array<int, string>   $verbs Its case verbs.
	 *
	 * @return array<int, string> Of read, add, delete.
	 */
	private function documentRights(array $row, array $verbs): array {
		if (array_key_exists('documentActions', $row) === true) {
			return array_values(array_filter((array)$row['documentActions'], 'is_string'));
		}

		if (in_array('delete', $verbs, true) === true) {
			return ['read', 'add', 'delete'];
		}

		if (in_array('create', $verbs, true) === true) {
			return ['read', 'add'];
		}

		return array_values(array_intersect(['read'], $verbs));
	}//end documentRights()

	/**
	 * What a role may do with documents: All, Add, Read or No.
	 *
	 * @param array<int, string> $rights Of read, add, delete.
	 *
	 * @return string
	 */
	private function documents(array $rights): string {
		if (in_array('delete', $rights, true) === true) {
			return $this->l10n->t('All');
		}

		if (in_array('add', $rights, true) === true) {
			return $this->l10n->t('Add');
		}

		if (in_array('read', $rights, true) === true) {
			return $this->l10n->t('Read');
		}

		return $this->l10n->t('No');
	}//end documents()
}//end class
