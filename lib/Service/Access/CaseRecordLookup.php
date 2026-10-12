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
use RuntimeException;

/**
 * Reads a case, its case type and its rows, unscoped.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */
class CaseRecordLookup {

	use SearchesObjects;

	/**
	 * Rows per page.
	 */
	private const PAGE = 1000;

	/**
	 * The most pages one value is read over.
	 */
	private const MAX_PAGES = 100;

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
	 * Every row, read a page at a time: a rule or a reconcile that saw only the
	 * first page of a large case would answer for a slice and say nothing.
	 *
	 * @param string $schema The schema slug; '' answers none.
	 * @param string $field  The field.
	 * @param string $caseId The value.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When the value holds more rows than this reads; nothing is answered then.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function rowsOf(string $schema, string $field, string $caseId): array {
		$rows = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$read = $this->page(schema: $schema, field: $field, value: $caseId, offset: ($page * self::PAGE), limit: self::PAGE);
			$rows = array_merge($rows, $read['rows']);
			if ($read['read'] < self::PAGE) {
				return $rows;
			}
		}

		throw new RuntimeException(
			'Dossiq case record lookup: more than '.(self::MAX_PAGES * self::PAGE).' rows of '.$schema.' name '.$caseId.'; not read in part'
		);
	}//end rowsOf()

	/**
	 * One page of the rows of a schema whose field holds the value, read regardless of the caller.
	 *
	 * @param string $schema The schema slug; '' answers none.
	 * @param string $field  The field.
	 * @param string $value  The value.
	 * @param int    $offset How many rows to skip.
	 * @param int    $limit  The page size.
	 *
	 * @return array{rows: list<array<string, mixed>>, read: int} The rows that name the value exactly, and how
	 *                                                            many rows the store answered: fewer than the
	 *                                                            limit means this was the last page.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
	 */
	public function page(string $schema, string $field, string $value, int $offset, int $limit): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || $schema === '' || $value === '') {
			return ['rows' => [], 'read' => 0];
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [$field => $value, '_limit' => $limit, '_offset' => $offset]
		);

		return [
			'rows' => array_values(
				array_filter($rows, fn (array $row): bool => $this->caseTypes->referenceId(value: ($row[$field] ?? '')) === $value)
			),
			'read' => count($rows),
		];
	}//end page()

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
