<?php

/**
 * Dossiq record-import references
 *
 * What other records know about a record a case type imports: the cases that
 * already keep its number (so a second record with that number joins the
 * same case), and the fields of other records that point at it (moved onto
 * its case). Generic: which number and which fields are the case type's
 * declaration.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-record-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * Finds the case a number already became, and moves references onto a case.
 *
 * @spec openspec/specs/case-record-import/spec.md
 */
class RecordImportReferences {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Register and schema configuration.
	 * @param RecordImportStore $store           Pages through rows and reads their ids.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly RecordImportStore $store,
	) {
	}//end __construct()

	/**
	 * The cases of this type by the earlier number they keep, for `dedupeBy`.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $caseType      The case type.
	 * @param array<string, mixed> $import        The declaration, naming the former reference's application.
	 *
	 * @return array<string, string> Earlier number => case id.
	 *
	 * @throws Throwable When the cases cannot be read.
	 *
	 * @spec openspec/specs/case-record-import/spec.md
	 */
	public function casesByFormerReference(object $objectService, array $caseType, array $import): array {
		$application = (string)($import['formerReference']['application'] ?? '');
		$cases = $this->store->listAll(
			objectService: $objectService,
			register: (string)$this->settingsService->getConfigValue('register'),
			schema: (string)$this->settingsService->getConfigValue('case_schema'),
			filters: ['caseType' => $this->store->idOf(row: $caseType)]
		);
		$byKey = [];
		foreach ($cases as $case) {
			foreach ((array)($case['formerReferences'] ?? []) as $former) {
				$reference = trim((string)($former['reference'] ?? ''));
				if ($reference !== '' && (string)($former['application'] ?? '') === $application) {
					$byKey[$reference] = $this->store->idOf(row: $case);
				}
			}
		}

		return $byKey;
	}//end casesByFormerReference()

	/**
	 * The number a record shares with its duplicates, by `dedupeBy`, or ''.
	 *
	 * @param array<string, mixed> $import The declaration.
	 * @param array<string, mixed> $source The source record.
	 *
	 * @return string The key.
	 */
	private function numberOf(array $import, array $source): string {
		$field = (string)($import['dedupeBy'] ?? '');
		if ($field === '') {
			return '';
		}

		return trim((string)($source[$field] ?? ''));
	}//end numberOf()

	/**
	 * The case a record's number already became, or ''.
	 *
	 * @param object                     $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed>       $caseType      The case type.
	 * @param array<string, mixed>       $import        The declaration, with `dedupeBy`.
	 * @param array<string, mixed>       $source        The source record.
	 * @param array<string, string>|null $cache         Number => case id, read on first need.
	 *
	 * @return string The case id, or '' when the number is new or none is declared.
	 *
	 * @throws Throwable When the cases cannot be read.
	 *
	 * @spec openspec/specs/case-record-import/spec.md
	 */
	public function caseOfSameNumber(object $objectService, array $caseType, array $import, array $source, ?array &$cache): string {
		$number = $this->numberOf(import: $import, source: $source);
		if ($number === '') {
			return '';
		}

		$cache = ($cache ?? $this->casesByFormerReference(objectService: $objectService, caseType: $caseType, import: $import));
		return (string)($cache[$number] ?? '');
	}//end caseOfSameNumber()

	/**
	 * Remember the case a record's number became, so a later record with it joins that case.
	 *
	 * @param array<string, mixed>       $import The declaration.
	 * @param array<string, mixed>       $source The source record.
	 * @param string                     $caseId The case.
	 * @param array<string, string>|null $cache  Number => case id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-record-import/spec.md
	 */
	public function rememberNumber(array $import, array $source, string $caseId, ?array &$cache): void {
		$number = $this->numberOf(import: $import, source: $source);
		if ($number !== '') {
			$cache[$number] = $caseId;
		}
	}//end rememberNumber()

	/**
	 * Point the declared references at the case a record moved onto.
	 *
	 * Each `references` entry `{schema, field, register?}` names a field of
	 * another record that holds the moved record's id or slug; every such
	 * value becomes the case id. A value already moved matches nothing, so a
	 * second run moves nothing.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $import        The declaration.
	 * @param array<int, string>   $oldValues     The moved record's id and slug.
	 * @param string               $caseId        The case.
	 *
	 * @return int How many references moved.
	 *
	 * @throws Throwable When a referencing record cannot be read or written.
	 *
	 * @spec openspec/specs/case-record-import/spec.md
	 */
	public function repoint(object $objectService, array $import, array $oldValues, string $caseId): int {
		$moved = 0;
		foreach ((array)($import['references'] ?? []) as $reference) {
			$register = (string)($reference['register'] ?? ($import['sourceRegister'] ?? ''));
			$schema = (string)($reference['schema'] ?? '');
			$field = (string)($reference['field'] ?? '');
			if ($schema === '' || $field === '') {
				continue;
			}

			$old = array_values(array_unique(array_filter($oldValues, static fn (string $value): bool => trim($value) !== '')));
			if (($reference['list'] ?? false) === true) {
				$moved += $this->repointLists(objectService: $objectService, where: [$register, $schema, $field], oldValues: $old, caseId: $caseId);
				continue;
			}

			foreach ($old as $value) {
				foreach ($this->store->listAll(objectService: $objectService, register: $register, schema: $schema, filters: [$field => $value]) as $row) {
					$this->patchObjectAsArray(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						id: $this->store->idOf(row: $row),
						changes: [$field => $caseId]
					);
					$moved++;
				}
			}
		}

		return $moved;
	}//end repoint()

	/**
	 * Replace the moved record's entry in a list of references, keeping the
	 * other entries and their order. A list cannot be filtered on one of its
	 * entries, so every row of the schema is read.
	 *
	 * @param object             $objectService The OpenRegister ObjectService.
	 * @param array<int, string> $where         Register, schema and list field.
	 * @param array<int, string> $oldValues     The moved record's id and slug.
	 * @param string             $caseId        The case.
	 *
	 * @return int How many rows changed.
	 *
	 * @throws Throwable When a row cannot be read or written.
	 */
	private function repointLists(object $objectService, array $where, array $oldValues, string $caseId): int {
		[$register, $schema, $field] = $where;
		$moved = 0;
		foreach ($this->store->listAll(objectService: $objectService, register: $register, schema: $schema, filters: []) as $row) {
			$list = $row[$field] ?? null;
			if (is_array($list) === false || array_intersect($oldValues, $list) === []) {
				continue;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $this->store->idOf(row: $row),
				changes: [$field => array_map(fn (mixed $entry): mixed => $this->replaced(entry: $entry, oldValues: $oldValues, caseId: $caseId), $list)]
			);
			$moved++;
		}

		return $moved;
	}//end repointLists()

	/**
	 * One entry of a list of references, the case where it named the moved record.
	 *
	 * @param mixed              $entry     The entry.
	 * @param array<int, string> $oldValues The moved record's id and slug.
	 * @param string             $caseId    The case.
	 *
	 * @return mixed The entry.
	 */
	private function replaced(mixed $entry, array $oldValues, string $caseId): mixed {
		if (in_array($entry, $oldValues, true) === true) {
			return $caseId;
		}

		return $entry;
	}//end replaced()
}//end class
