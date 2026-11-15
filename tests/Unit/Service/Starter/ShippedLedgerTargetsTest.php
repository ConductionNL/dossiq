<?php

/**
 * Shipped Ledger Targets Test
 *
 * Pins how a ledger target schema is resolved to its slug (known slug kept,
 * schema id mapped back and cached, unknown id kept), which target values a
 * ledger lookup searches (slug plus the instance's schema id), how rows from
 * both are merged per target object, and how a single row is found.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\Starter\ShippedLedgerTargets;
use OCA\Dossiq\Service\Starter\StarterStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShippedLedgerTargets.
 */
#[CoversClass(ShippedLedgerTargets::class)]
final class ShippedLedgerTargetsTest extends TestCase {

	/**
	 * The instance's schema ids per config key.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_IDS = [
		'case_type_schema' => '17',
		'role_type_schema' => '23',
	];

	/**
	 * A known slug and the empty target are kept without asking the store.
	 *
	 * @return void
	 */
	public function testKnownSlugAndEmptyTargetAreKept(): void {
		$store = $this->createMock(StarterStore::class);
		$store->expects($this->never())->method('schemaIdOf');
		$targets = new ShippedLedgerTargets(store: $store);

		self::assertSame('caseType', $targets->slugFor(targetSchema: 'caseType'));
		self::assertSame('', $targets->slugFor(targetSchema: ''));
	}//end testKnownSlugAndEmptyTargetAreKept()

	/**
	 * A schema id maps back to its slug, once; an unknown id is kept as it is.
	 *
	 * @return void
	 */
	public function testSchemaIdMapsBackToItsSlugAndIsCached(): void {
		$calls = 0;
		$store = $this->store(calls: $calls);
		$targets = new ShippedLedgerTargets(store: $store);

		self::assertSame('caseType', $targets->slugFor(targetSchema: '17'));
		$afterFirst = $calls;
		self::assertSame('caseType', $targets->slugFor(targetSchema: '17'));
		self::assertSame($afterFirst, $calls, 'the second lookup is served from the cache');

		self::assertSame('999', $targets->slugFor(targetSchema: '999'));
		$afterUnknown = $calls;
		self::assertSame('999', $targets->slugFor(targetSchema: '999'));
		self::assertSame($afterUnknown, $calls, 'an unknown id is cached as itself');
	}//end testSchemaIdMapsBackToItsSlugAndIsCached()

	/**
	 * The searched values are the slug and, when different, the instance's schema id.
	 *
	 * @return void
	 */
	public function testValuesForSlugAndSchemaId(): void {
		$calls = 0;
		$targets = new ShippedLedgerTargets(store: $this->store(calls: $calls));

		self::assertSame(['caseType', '17'], $targets->valuesFor(targetSchema: 'caseType'));
		self::assertSame(['caseType', '17'], $targets->valuesFor(targetSchema: '17'));
		self::assertSame(['status'], $targets->valuesFor(targetSchema: 'status'), 'no schema id configured');
		self::assertSame(['999'], $targets->valuesFor(targetSchema: '999'));
	}//end testValuesForSlugAndSchemaId()

	/**
	 * Rows under the slug and under the schema id are merged per target object, the first one winning.
	 *
	 * @return void
	 */
	public function testRowsAreMergedPerTargetObjectFirstWins(): void {
		$calls = 0;
		$queried = [];
		$store = $this->store(
			calls: $calls,
			rows: [
				'caseType' => [['targetObject' => 'a', 'from' => 'slug'], ['targetObject' => 'b', 'from' => 'slug']],
				'17' => [['targetObject' => 'b', 'from' => 'id'], ['targetObject' => 'c', 'from' => 'id']],
			],
			queried: $queried
		);

		$rows = (new ShippedLedgerTargets(store: $store))->rows(ledgerKey: 'shipped_ledger_schema', targetSchema: 'caseType');

		self::assertSame(
			[['targetObject' => 'a', 'from' => 'slug'], ['targetObject' => 'b', 'from' => 'slug'], ['targetObject' => 'c', 'from' => 'id']],
			$rows
		);
		self::assertSame(
			[['shipped_ledger_schema', ['targetSchema' => 'caseType']], ['shipped_ledger_schema', ['targetSchema' => '17']]],
			$queried
		);
	}//end testRowsAreMergedPerTargetObjectFirstWins()

	/**
	 * A store that cannot answer for one of the targets makes the whole read unanswered.
	 *
	 * @return void
	 */
	public function testRowsAreNullWhenTheStoreCannotAnswer(): void {
		$calls = 0;
		$store = $this->store(calls: $calls, rows: ['caseType' => [['targetObject' => 'a']], '17' => null]);

		self::assertNull((new ShippedLedgerTargets(store: $store))->rows(ledgerKey: 'ledger', targetSchema: 'caseType'));
	}//end testRowsAreNullWhenTheStoreCannotAnswer()

	/**
	 * A single row is looked up by slug first, then by schema id, limited to one.
	 *
	 * @return void
	 */
	public function testRowLooksUpSlugThenSchemaId(): void {
		$calls = 0;
		$queried = [];
		$store = $this->store(calls: $calls, rows: ['caseType' => [], '17' => [['targetObject' => 'x', 'from' => 'id']]], queried: $queried);
		$targets = new ShippedLedgerTargets(store: $store);

		self::assertSame(['targetObject' => 'x', 'from' => 'id'], $targets->row(ledgerKey: 'ledger', targetSchema: 'caseType', targetObject: 'x'));
		self::assertSame(
			[
				['ledger', ['targetSchema' => 'caseType', 'targetObject' => 'x', '_limit' => 1]],
				['ledger', ['targetSchema' => '17', 'targetObject' => 'x', '_limit' => 1]],
			],
			$queried
		);
	}//end testRowLooksUpSlugThenSchemaId()

	/**
	 * A row found under the slug stops the lookup; none anywhere answers null.
	 *
	 * @return void
	 */
	public function testRowStopsAtTheFirstHitAndIsNullWithoutOne(): void {
		$calls = 0;
		$queried = [];
		$store = $this->store(calls: $calls, rows: ['caseType' => [['targetObject' => 'x', 'from' => 'slug']], '17' => [['targetObject' => 'x', 'from' => 'id']]], queried: $queried);
		$targets = new ShippedLedgerTargets(store: $store);

		self::assertSame('slug', $targets->row(ledgerKey: 'ledger', targetSchema: 'caseType', targetObject: 'x')['from']);
		self::assertCount(1, $queried);

		$calls = 0;
		$none = $this->store(calls: $calls, rows: ['caseType' => null, '17' => []]);
		self::assertNull((new ShippedLedgerTargets(store: $none))->row(ledgerKey: 'ledger', targetSchema: 'caseType', targetObject: 'x'));
	}//end testRowStopsAtTheFirstHitAndIsNullWithoutOne()

	/**
	 * A store double over fixed schema ids and rows per target.
	 *
	 * @param int                                       $calls   Counts schemaIdOf calls.
	 * @param array<string, array<int, array<string, mixed>>|null> $rows    Rows per target schema.
	 * @param array<int, array<int, mixed>>             $queried Records rows() calls.
	 *
	 * @return StarterStore
	 */
	private function store(int &$calls, array $rows = [], array &$queried = []): StarterStore {
		$store = $this->createMock(StarterStore::class);
		$store->method('schemaIdOf')->willReturnCallback(
			static function (string $configKey) use (&$calls): string {
				$calls++;

				return (self::SCHEMA_IDS[$configKey] ?? '');
			}
		);
		$store->method('rows')->willReturnCallback(
			static function (string $configKey, array $filters = []) use ($rows, &$queried): ?array {
				$queried[] = [$configKey, $filters];

				$target = (string)$filters['targetSchema'];
				if (array_key_exists($target, $rows) === false) {
					return [];
				}

				return $rows[$target];
			}
		);

		return $store;
	}//end store()
}//end class
