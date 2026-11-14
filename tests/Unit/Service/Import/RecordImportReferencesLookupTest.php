<?php

/**
 * Unit tests for the RecordImportReferences lookups: earlier imports are
 * found by their former reference in the same application, a record's number
 * is looked up once and cached, and references to the old record are pointed
 * at the new case, singly or inside lists.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use OCA\Dossiq\Service\Import\RecordImportReferences;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Import\RecordImportReferences
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class RecordImportReferencesLookupTest extends TestCase {

	private SettingsService&MockObject $settings;

	private RecordImportStore&MockObject $store;

	/** @var list<array<string, mixed>> */
	private array $listed = [];

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '');
		$this->store = $this->createMock(RecordImportStore::class);
		$this->store->method('idOf')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
	}//end setUp()

	/**
	 * Let the store list the given rows per "register/schema", recording each call.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows by "register/schema".
	 */
	private function storeLists(array $rows): void {
		$this->store->method('listAll')->willReturnCallback(
			function (object $objectService, string $register, string $schema, array $filters) use ($rows): array {
				$this->listed[] = ['where' => $register . '/' . $schema, 'filters' => $filters];
				$all = $rows[$register . '/' . $schema] ?? [];
				return array_values(array_filter($all, static function (array $row) use ($filters): bool {
					foreach ($filters as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}));
			}
		);
	}//end storeLists()

	private function references(): RecordImportReferences {
		return new RecordImportReferences($this->settings, $this->store);
	}//end references()

	/**
	 * An object service recording patches over stored rows.
	 *
	 * @param array<string, array<string, mixed>> $stored Rows by id.
	 */
	private function objectService(array $stored = []): object {
		return new class($stored) {
			/** @var list<array{string, array<string, mixed>}> */
			public array $patches = [];

			public function __construct(private array $stored) {
			}

			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				$this->patches[] = [$objectId, $data];
				return ($this->stored[$objectId] ?? []) + $data;
			}
		};
	}//end objectService()

	public function testEarlierImportsAreFoundByTheirReferenceInTheSameApplication(): void {
		$this->storeLists(
			[
				'dossiq/case' => [
					['id' => 'case-1', 'caseType' => 'ct-1', 'formerReferences' => [['application' => 'Corsa', 'reference' => ' Z-1 '], ['application' => 'Other', 'reference' => 'Z-9']]],
					['id' => 'case-2', 'caseType' => 'ct-1', 'formerReferences' => [['application' => 'Corsa', 'reference' => '']]],
					['id' => 'case-3', 'caseType' => 'ct-2', 'formerReferences' => [['application' => 'Corsa', 'reference' => 'Z-3']]],
				],
			]
		);

		$found = $this->references()->casesByFormerReference($this->objectService(), ['id' => 'ct-1'], ['formerReference' => ['application' => 'Corsa']]);

		$this->assertSame(['Z-1' => 'case-1'], $found);
		$this->assertSame([['where' => 'dossiq/case', 'filters' => ['caseType' => 'ct-1']]], $this->listed);
	}//end testEarlierImportsAreFoundByTheirReferenceInTheSameApplication()

	public function testARecordsNumberIsLookedUpOnceAndCached(): void {
		$this->storeLists(['dossiq/case' => [['id' => 'case-1', 'caseType' => 'ct-1', 'formerReferences' => [['application' => 'Corsa', 'reference' => 'Z-1']]]]]);
		$references = $this->references();
		$import = ['dedupeBy' => 'zaaknummer', 'formerReference' => ['application' => 'Corsa']];
		$cache = null;

		$this->assertSame('case-1', $references->caseOfSameNumber($this->objectService(), ['id' => 'ct-1'], $import, ['zaaknummer' => ' Z-1 '], $cache));
		$this->assertSame('', $references->caseOfSameNumber($this->objectService(), ['id' => 'ct-1'], $import, ['zaaknummer' => 'Z-2'], $cache));
		$this->assertSame(['Z-1' => 'case-1'], $cache);
		$this->assertCount(1, $this->listed);
	}//end testARecordsNumberIsLookedUpOnceAndCached()

	public function testWithoutANumberNothingIsLookedUp(): void {
		$this->store->expects($this->never())->method('listAll');
		$cache = null;

		$this->assertSame('', $this->references()->caseOfSameNumber($this->objectService(), [], ['dedupeBy' => ''], ['zaaknummer' => 'Z-1'], $cache));
		$this->assertSame('', $this->references()->caseOfSameNumber($this->objectService(), [], ['dedupeBy' => 'zaaknummer'], ['zaaknummer' => ' '], $cache));
		$this->assertNull($cache);
	}//end testWithoutANumberNothingIsLookedUp()

	public function testSingleReferencesToTheOldRecordPointAtTheNewCase(): void {
		$this->storeLists(
			[
				'dossiq/task' => [['id' => 't-1', 'case' => 'old-1'], ['id' => 't-2', 'case' => 'old-2'], ['id' => 't-3', 'case' => 'other']],
				'src/note' => [['id' => 'n-1', 'zaak' => 'old-1']],
			]
		);
		$service = $this->objectService();

		$moved = $this->references()->repoint(
			$service,
			[
				'sourceRegister' => 'src',
				'references' => [
					['register' => 'dossiq', 'schema' => 'task', 'field' => 'case'],
					['schema' => 'note', 'field' => 'zaak'],
					['schema' => '', 'field' => 'case'],
					['schema' => 'task'],
				],
			],
			['old-1', 'old-2', ' ', 'old-1'],
			'case-new'
		);

		$this->assertSame(3, $moved);
		$this->assertSame([['t-1', ['case' => 'case-new']], ['t-2', ['case' => 'case-new']], ['n-1', ['zaak' => 'case-new']]], $service->patches);
	}//end testSingleReferencesToTheOldRecordPointAtTheNewCase()

	public function testListReferencesReplaceOnlyTheOldEntries(): void {
		$this->storeLists(
			[
				'dossiq/decision' => [
					['id' => 'd-1', 'cases' => ['old-1', 'keep', 'old-2']],
					['id' => 'd-2', 'cases' => ['keep']],
					['id' => 'd-3', 'cases' => 'old-1'],
				],
			]
		);
		$service = $this->objectService();

		$moved = $this->references()->repoint(
			$service,
			['references' => [['register' => 'dossiq', 'schema' => 'decision', 'field' => 'cases', 'list' => true]]],
			['old-1', 'old-2'],
			'case-new'
		);

		$this->assertSame(1, $moved);
		$this->assertSame([['d-1', ['cases' => ['case-new', 'keep', 'case-new']]]], $service->patches);
		$this->assertSame([], $this->listed[0]['filters']);
	}//end testListReferencesReplaceOnlyTheOldEntries()

	public function testAnImportWithoutReferencesMovesNothing(): void {
		$this->store->expects($this->never())->method('listAll');

		$this->assertSame(0, $this->references()->repoint($this->objectService(), [], ['old-1'], 'case-new'));
	}//end testAnImportWithoutReferencesMovesNothing()
}//end class
