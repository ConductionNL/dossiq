<?php

/**
 * Dossiq case reviewer access
 *
 * Case-scoped reviewer access, for any case type that declares it
 * (case-reviewer-access, REQ-CRA-001 to REQ-CRA-004). A case type names, under
 * `reviewerAccess`, the records it keeps private to the case (by the app
 * config key of their schema and the field that holds the case), where its
 * reviewers are named, the supervising group, and whether a reviewer is
 * barred from another reviewer's records. This class computes who must read
 * each such record and hands the OpenRegister work to ObjectReadGrants:
 * the case assignee reads all of them, every named reviewer reads them unless
 * `hideOthers` is on, the supervising group owns them, and the author of a
 * record (its OpenRegister owner) and the supervisors are the only ones who
 * may change it.
 *
 * Which procedure uses it is configuration on its case type, never code here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-declares-the-records-it-keeps-private-to-the-case-req-cra-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IGroupManager;

/**
 * Who reads and who changes the records a case type keeps private to the case.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-declares-the-records-it-keeps-private-to-the-case-req-cra-001
 */
class CaseReviewerAccess {

	use SearchesObjects;

	/**
	 * The supervising group when the case type names none.
	 */
	public const DEFAULT_SUPERVISORS = 'dossiq-coordinators';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService The object service, register and schema keys.
	 * @param CaseTypeStore    $caseTypes       The case type reference on a case, every case type.
	 * @param CaseTypeResolver $resolver        The effective case type.
	 * @param ObjectReadGrants $grants          The OpenRegister side.
	 * @param IGroupManager    $groupManager    Supervisors and administrators.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseTypeResolver $resolver,
		private readonly ObjectReadGrants $grants,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * A case type's reviewer access declaration, normalised; `records` empty when it declares none.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{
	 *     records: list<array{schemaKey: string, caseField: string}>,
	 *     reviewers: list<array{schemaKey: string, caseField: string, userField: string}>,
	 *     supervisorGroup: string,
	 *     hideOthers: bool
	 * }
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-declares-the-records-it-keeps-private-to-the-case-req-cra-001
	 */
	public function declarationFor(array $caseType): array {
		$declared = (array)($caseType['reviewerAccess'] ?? []);
		$records = [];
		foreach ((array)($declared['records'] ?? []) as $row) {
			$row = (array)$row;
			$schemaKey = trim((string)($row['schemaKey'] ?? ''));
			if ($schemaKey !== '') {
				$records[] = ['schemaKey' => $schemaKey, 'caseField' => trim((string)($row['caseField'] ?? 'case'))];
			}
		}

		$reviewers = [];
		foreach ((array)($declared['reviewers'] ?? []) as $row) {
			$row = (array)$row;
			$schemaKey = trim((string)($row['schemaKey'] ?? ''));
			if ($schemaKey !== '') {
				$reviewers[] = [
					'schemaKey' => $schemaKey,
					'caseField' => trim((string)($row['caseField'] ?? 'case')),
					'userField' => trim((string)($row['userField'] ?? 'assignee')),
				];
			}
		}

		$group = trim((string)($declared['supervisorGroup'] ?? ''));
		if ($group === '') {
			$group = self::DEFAULT_SUPERVISORS;
		}

		return [
			'records' => $records,
			'reviewers' => $reviewers,
			'supervisorGroup' => $group,
			'hideOthers' => (($declared['hideOthers'] ?? false) === true),
		];
	}//end declarationFor()

	/**
	 * Make the grants of every declared record of the case match the people on it.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int}
	 *         `status` is `reconciled`, `not-declared` (the case type keeps nothing private)
	 *         or `unavailable` (no per-object sharing in this OpenRegister; nothing written).
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function reconcile(string $caseId): array {
		$answer = ['status' => 'not-declared', 'granted' => 0, 'revoked' => 0, 'refused' => 0];
		$case = $this->caseRow(caseId: $caseId);
		$declaration = $this->declarationFor(caseType: $this->caseTypeOf(case: $case));
		if ($case === [] || $declaration['records'] === []) {
			return $answer;
		}

		if ($this->grants->available() === false) {
			$answer['status'] = 'unavailable';
			return $answer;
		}

		$readers = $this->readersOf(caseId: $caseId, case: $case, declaration: $declaration);
		$answer['status'] = 'reconciled';
		foreach ($declaration['records'] as $declared) {
			$schema = $this->settingsService->getConfigValue($declared['schemaKey']);
			foreach ($this->rowsOf(schema: $schema, field: $declared['caseField'], caseId: $caseId) as $row) {
				$result = $this->grants->reconcile(
					schema: $schema,
					uuid: (string)($row['id'] ?? ($row['uuid'] ?? '')),
					readers: $readers,
					group: $declaration['supervisorGroup']
				);
				$answer['granted'] += $result['granted'];
				$answer['revoked'] += $result['revoked'];
				$answer['refused'] += $result['refused'];
			}
		}

		return $answer;
	}//end reconcile()

	/**
	 * Reconcile every case of every case type that declares reviewer access.
	 *
	 * @return array{status: string, cases: int, granted: int, revoked: int, refused: int, failed: list<string>}
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
	 */
	public function reconcileAll(): array {
		$answer = ['status' => 'reconciled', 'cases' => 0, 'granted' => 0, 'revoked' => 0, 'refused' => 0, 'failed' => []];
		if ($this->grants->available() === false) {
			$answer['status'] = 'unavailable';
			return $answer;
		}

		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		foreach ($this->caseTypes->everyCaseType() as $caseType) {
			$caseTypeId = $this->caseTypes->rowId(row: $caseType);
			if ($caseTypeId === '' || $this->declarationFor(caseType: $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId))['records'] === []) {
				continue;
			}

			foreach ($this->rowsOf(schema: $caseSchema, field: 'caseType', caseId: $caseTypeId) as $case) {
				$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
				$result = $this->reconcile(caseId: $caseId);
				$answer['cases']++;
				$answer['granted'] += $result['granted'];
				$answer['revoked'] += $result['revoked'];
				$answer['refused'] += $result['refused'];
				if ($result['refused'] > 0) {
					$answer['failed'][] = $caseId;
				}
			}
		}

		return $answer;
	}//end reconcileAll()

	/**
	 * Whether the user may change this record of the case: its author, a supervisor or an administrator.
	 *
	 * A case type that keeps nothing private leaves every record changeable as before.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $record The stored record, as OpenRegister serialises it.
	 * @param string               $userId The caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function mayChange(string $caseId, array $record, string $userId): bool {
		$declaration = $this->declarationFor(caseType: $this->caseTypeOf(case: $this->caseRow(caseId: $caseId)));
		if ($declaration['records'] === []) {
			return true;
		}

		$owner = (string)(((array)($record['@self'] ?? []))['owner'] ?? '');
		if ($owner === '' || $owner === $userId) {
			return true;
		}

		return $this->isSupervisor(userId: $userId, group: $declaration['supervisorGroup']);
	}//end mayChange()

	/**
	 * The refusal of a change to someone else's record, or null when the user may change it.
	 *
	 * The refusal names the record's reference and the rule only: never its
	 * author or its content, which the caller may not be allowed to read.
	 *
	 * @param string               $caseId    The case uuid.
	 * @param array<string, mixed> $record    The stored record.
	 * @param string               $userId    The caller.
	 * @param string               $reference What the caller calls the record (a document reference).
	 *
	 * @return array{documentRef: string, errors: array{assessment: string}}|null
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function refusalFor(string $caseId, array $record, string $userId, string $reference): ?array {
		if ($this->mayChange(caseId: $caseId, record: $record, userId: $userId) === true) {
			return null;
		}

		return ['documentRef' => $reference, 'errors' => ['assessment' => 'owned-by-another-reviewer']];
	}//end refusalFor()

	/**
	 * Reconcile the case when a writer created declared records; nothing when it created none.
	 *
	 * @param string $caseId  The case uuid.
	 * @param int    $created How many records the writer created.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int}
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function reconcileAfterCreate(string $caseId, int $created): array {
		if ($created <= 0) {
			return ['status' => 'nothing-created', 'granted' => 0, 'revoked' => 0, 'refused' => 0];
		}

		return $this->reconcile(caseId: $caseId);
	}//end reconcileAfterCreate()

	/**
	 * Whether the user supervises: a member of the group, or an administrator.
	 *
	 * @param string $userId The user.
	 * @param string $group  The supervising group.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function isSupervisor(string $userId, string $group): bool {
		if ($userId === '') {
			return false;
		}

		return $this->groupManager->isAdmin($userId) === true || $this->groupManager->isInGroup($userId, $group) === true;
	}//end isSupervisor()

	/**
	 * The user ids that must read the case's private records.
	 *
	 * @param string               $caseId      The case uuid.
	 * @param array<string, mixed> $case        The case row.
	 * @param array<string, mixed> $declaration The normalised declaration.
	 *
	 * @return list<string>
	 */
	private function readersOf(string $caseId, array $case, array $declaration): array {
		$readers = [trim((string)($case['assignee'] ?? ''))];
		if ($declaration['hideOthers'] === false) {
			foreach ($declaration['reviewers'] as $source) {
				$schema = $this->settingsService->getConfigValue($source['schemaKey']);
				foreach ($this->rowsOf(schema: $schema, field: $source['caseField'], caseId: $caseId) as $row) {
					foreach ((array)($row[$source['userField']] ?? []) as $user) {
						$readers[] = trim((string)$user);
					}
				}
			}
		}

		return array_values(array_unique(array_filter($readers, static fn (string $user): bool => $user !== '')));
	}//end readersOf()

	/**
	 * The rows of a schema whose field holds the value, read regardless of the caller.
	 *
	 * @param string $schema The schema slug; '' answers none.
	 * @param string $field  The field.
	 * @param string $caseId The value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema, string $field, string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [$field => $caseId, '_limit' => 1000]
		);

		return array_values(
			array_filter($rows, fn (array $row): bool => $this->caseTypes->referenceId(value: ($row[$field] ?? '')) === $caseId)
		);
	}//end rowsOf()

	/**
	 * The case row, or [].
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function caseRow(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return [];
		}

		return ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId) ?? []);
	}//end caseRow()

	/**
	 * The effective case type of a case row, or [].
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed>
	 */
	private function caseTypeOf(array $case): array {
		$reference = $this->caseTypes->referenceId(value: ($case['caseType'] ?? ''));
		if ($reference === '') {
			return [];
		}

		return $this->resolver->effectiveCaseType(caseTypeId: $reference);
	}//end caseTypeOf()
}//end class
