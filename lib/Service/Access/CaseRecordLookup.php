<?php

/**
 * Dossiq case record lookup
 *
 * The three reads case-scoped reviewer access needs, regardless of who is
 * asking: the case row, its effective case type, and the rows of a schema
 * that belong to the case. Regardless of the caller because the reconciler
 * and the guards must see a record the caller cannot, to protect it; nothing
 * read here is answered to the caller.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Reads a case, its case type and its rows, unscoped.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */
class CaseRecordLookup {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService The object service, register and schema keys.
	 * @param CaseTypeStore    $caseTypes       The case type reference on a case.
	 * @param CaseTypeResolver $resolver        The effective case type.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseTypeResolver $resolver,
	) {
	}//end __construct()

	/**
	 * The rows of a schema whose field holds the value, read regardless of the caller.
	 *
	 * @param string $schema The schema slug; '' answers none.
	 * @param string $field  The field.
	 * @param string $caseId The value.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function rowsOf(string $schema, string $field, string $caseId): array {
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
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function caseRow(string $caseId): array {
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
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function caseTypeOf(array $case): array {
		$reference = $this->caseTypes->referenceId(value: ($case['caseType'] ?? ''));
		if ($reference === '') {
			return [];
		}

		return $this->resolver->effectiveCaseType(caseTypeId: $reference);
	}//end caseTypeOf()
}//end class
