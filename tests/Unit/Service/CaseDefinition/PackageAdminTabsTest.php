<?php

/**
 * Unit tests for PackageAdminTabs: only an installed package that declares an
 * admin tab is listed, with the ids of what the ledger says it installed,
 * grouped by the lists the tab declares.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageAdminTabs;
use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\Starter\StarterStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageAdminTabs
 *
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageAdminTab
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageDirectory
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class PackageAdminTabsTest extends TestCase {

	/**
	 * A temporary package directory.
	 *
	 * @var string
	 */
	private string $root = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/dq-admin-tabs-' . bin2hex(random_bytes(4));
		mkdir($this->root);
	}//end setUp()

	protected function tearDown(): void {
		foreach ((array)glob($this->root . '/*/package.json') as $file) {
			unlink((string)$file);
			rmdir(dirname((string)$file));
		}

		rmdir($this->root);
	}//end tearDown()

	/**
	 * Write a package manifest.
	 *
	 * @param string               $id       The package id.
	 * @param array<string, mixed> $manifest The manifest.
	 */
	private function package(string $id, array $manifest): void {
		mkdir($this->root . '/' . $id);
		file_put_contents($this->root . '/' . $id . '/package.json', (string)json_encode(['id' => $id] + $manifest));
	}//end package()

	/**
	 * A store whose ledger holds these rows and whose schemas hold these objects.
	 *
	 * @param array<string, array<int, array<string, mixed>>|null> $ledger  Ledger rows by package id.
	 * @param array<string, array<string, array<string, mixed>>>   $objects Rows by config key and id.
	 * @param array<string, string>                                $ids     Schema ids by config key.
	 *
	 * @return StarterStore&MockObject
	 */
	private function store(array $ledger, array $objects = [], array $ids = []): StarterStore {
		$store = $this->createMock(StarterStore::class);
		$store->method('rows')->willReturnCallback(
			function (string $configKey, array $filters) use ($ledger): ?array {
				$this->assertSame('shipped_origin_schema', $configKey);
				return $ledger[$filters['set']] ?? [];
			}
		);
		$store->method('row')->willReturnCallback(
			static fn (string $configKey, string $id): ?array => ($objects[$configKey][$id] ?? null)
		);
		$store->method('schemaIdOf')->willReturnCallback(
			static fn (string $configKey): string => ($ids[$configKey] ?? '')
		);

		return $store;
	}//end store()

	/**
	 * The reader over the temporary directory and this store.
	 *
	 * @param StarterStore $store The store.
	 *
	 * @return PackageAdminTabs
	 */
	private function tabs(StarterStore $store): PackageAdminTabs {
		return new PackageAdminTabs(packages: new PackageDirectory(root: $this->root), store: $store);
	}//end tabs()

	public function testAnInstalledPackageListsWhatItInstalledByDeclaredList(): void {
		$this->package('vth', ['adminTab' => ['title' => 'VTH', 'lists' => ['caseTypes', 'flows', 'feeRules', 'checklists']]]);
		$ledger = [
			'vth' => [
				['set' => 'vth', 'setVersion' => '1.3.1', 'targetSchema' => 'package', 'targetObject' => 'vth'],
				['set' => 'vth', 'setVersion' => '1.4.0', 'targetSchema' => 'caseType', 'targetObject' => 'ct-1'],
				['set' => 'vth', 'setVersion' => '1.4.0', 'targetSchema' => '165', 'targetObject' => 'ct-2'],
				['set' => 'vth', 'setVersion' => '1.4.0', 'targetSchema' => 'workflowTemplate', 'targetObject' => 'fl-1'],
				['set' => 'vth', 'setVersion' => '1.4.0', 'targetSchema' => 'statusType', 'targetObject' => 'st-1'],
				['set' => 'vth', 'setVersion' => '1.4.0', 'targetSchema' => 'inspectionChecklistTemplate', 'targetObject' => 'ck-1'],
				['set' => 'other', 'setVersion' => '9.0.0', 'targetSchema' => 'caseType', 'targetObject' => 'foreign'],
			],
		];
		$objects = [
			'case_type_schema' => ['ct-1' => ['title' => 'Sloopmelding'], 'ct-2' => ['title' => ['nl' => 'Handhavingszaak']]],
			'workflow_template_schema' => ['fl-1' => ['title' => 'Handhavingstraject', 'lifecycleStatus' => 'draft']],
			'inspection_checklist_template_schema' => ['ck-1' => ['name' => 'Fundering', 'status' => 'active']],
		];

		$tabs = $this->tabs($this->store(ledger: $ledger, objects: $objects, ids: ['case_type_schema' => '165']))->installed();

		$this->assertSame(
			[
				[
					'id' => 'vth',
					'version' => '1.4.0',
					'title' => 'VTH',
					'lists' => [
						'caseTypes' => [
							['id' => 'ct-1', 'title' => 'Sloopmelding', 'status' => ''],
							['id' => 'ct-2', 'title' => 'Handhavingszaak', 'status' => ''],
						],
						'flows' => [['id' => 'fl-1', 'title' => 'Handhavingstraject', 'status' => 'draft']],
						'feeRules' => [],
						'checklists' => [['id' => 'ck-1', 'title' => 'Fundering', 'status' => 'active']],
					],
				],
			],
			$tabs
		);
	}//end testAnInstalledPackageListsWhatItInstalledByDeclaredList()

	public function testAnUninstalledPackageHasNoTab(): void {
		$this->package('subsidie', ['adminTab' => ['title' => 'Subsidie', 'lists' => ['caseTypes']]]);

		$this->assertSame([], $this->tabs($this->store(ledger: ['subsidie' => []]))->installed());
	}//end testAnUninstalledPackageHasNoTab()

	public function testAnUnreachableLedgerListsNoTab(): void {
		$this->package('vth', ['adminTab' => ['title' => 'VTH', 'lists' => ['caseTypes']]]);

		$this->assertSame([], $this->tabs($this->store(ledger: ['vth' => null]))->installed());
	}//end testAnUnreachableLedgerListsNoTab()

	public function testAPackageWithoutATabIsNeverAskedAbout(): void {
		$this->package('woo', ['title' => 'Woo']);
		$this->package('broken', ['adminTab' => ['title' => 'Broken', 'lists' => ['payments']]]);
		$store = $this->createMock(StarterStore::class);
		$store->expects($this->never())->method('rows');

		$this->assertSame([], $this->tabs($store)->installed());
	}//end testAPackageWithoutATabIsNeverAskedAbout()

	public function testAnItemThisAppDoesNotStoreKeepsItsIdWithoutATitle(): void {
		$this->package('vth', ['adminTab' => ['title' => 'VTH', 'lists' => ['feeRules']]]);
		$ledger = ['vth' => [['set' => 'vth', 'setVersion' => '1.0.0', 'targetSchema' => 'FeeSchedule', 'targetObject' => 'fee-1']]];

		$tabs = $this->tabs($this->store(ledger: $ledger))->installed();

		$this->assertSame(['feeRules' => [['id' => 'fee-1', 'title' => '', 'status' => '']]], $tabs[0]['lists']);
	}//end testAnItemThisAppDoesNotStoreKeepsItsIdWithoutATitle()
}//end class
