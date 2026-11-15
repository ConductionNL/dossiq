<?php

/**
 * The case leaf reader: parties by master entity, their cases by status,
 * the linked case types and statuses, and a failed read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Integration;

use OCA\Dossiq\Integration\CaseLeafProvider;
use OCA\Dossiq\Integration\CaseLeafReader;
use OCA\Dossiq\Integration\CaseLeafRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Integration\CaseLeafReader
 * @uses \OCA\Dossiq\Integration\CaseLeafRegister
 */
class CaseLeafReaderTest extends TestCase {

	/**
	 * The fake object service the reader reads from.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The reader over these rows; a schema mapped to an exception throws it.
	 *
	 * @param array<string, array<int, array<string, mixed>>|\Throwable> $rows   Rows per schema.
	 * @param LoggerInterface|null                                        $logger The logger.
	 *
	 * @return CaseLeafReader The reader.
	 */
	private function reader(array $rows, ?LoggerInterface $logger=null): CaseLeafReader {
		$this->objects = new class ($rows) {
			/**
			 * The reads, as [register, schema, filters].
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $reads = [];

			/**
			 * @param array<string, mixed> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->reads[] = [$register, $schema, $filters];
				$rows = $this->rows[$schema] ?? [];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		};

		return new CaseLeafReader(
			source: new CaseLeafRegister(objectService: $this->objects, register: 'dossiq'),
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end reader()

	/**
	 * Party ids come from every party schema, only for rows that really carry
	 * the reference, without empty ids and without duplicates.
	 *
	 * @return void
	 */
	public function testPartyIdsKeepOnlyMatchingRowsOnce(): void {
		$reader = $this->reader(
			rows: [
				'brpPerson' => [
					['id' => 'p-1', 'masterEntityRef' => 'm-1'],
					['id' => '', 'masterEntityRef' => 'm-1'],
					['id' => 'p-2', 'masterEntityRef' => 'm-other'],
				],
				'kvkCompany' => [
					['id' => 'k-1', 'masterEntityRef' => 'm-1'],
					['id' => 'p-1', 'masterEntityRef' => 'm-1'],
					['masterEntityRef' => 'm-1'],
				],
			]
		);

		self::assertSame(['p-1', 'k-1'], $reader->partyIds(ref: 'm-1'));
		self::assertSame(
			[
				['dossiq', 'brpPerson', ['masterEntityRef' => 'm-1', '_limit' => CaseLeafProvider::MAX_ROWS]],
				['dossiq', 'kvkCompany', ['masterEntityRef' => 'm-1', '_limit' => CaseLeafProvider::MAX_ROWS]],
			],
			$this->objects->reads
		);
	}//end testPartyIdsKeepOnlyMatchingRowsOnce()

	/**
	 * Cases are read per party and filtered on their final status.
	 *
	 * @return void
	 */
	public function testCasesOfFiltersOnStatus(): void {
		$rows = [
			'case' => [
				['id' => 'c-open', 'isFinalStatus' => false],
				['id' => 'c-closed', 'isFinalStatus' => true],
				['id' => 'c-unset'],
				['id' => 'c-truthy', 'isFinalStatus' => 1],
				['isFinalStatus' => true],
			],
		];

		self::assertSame(['c-open', 'c-unset', 'c-truthy'], array_keys($this->reader(rows: $rows)->casesOf(partyIds: ['p-1'], status: 'open')));
		self::assertSame(['c-closed'], array_keys($this->reader(rows: $rows)->casesOf(partyIds: ['p-1'], status: 'closed')));
		self::assertSame(
			['c-open', 'c-closed', 'c-unset', 'c-truthy'],
			array_keys($this->reader(rows: $rows)->casesOf(partyIds: ['p-1'], status: 'all'))
		);
	}//end testCasesOfFiltersOnStatus()

	/**
	 * Every party's cases are read with its id as requester, and a case found
	 * twice is listed once.
	 *
	 * @return void
	 */
	public function testCasesOfReadsEachPartyAndMergesById(): void {
		$reader = $this->reader(rows: ['case' => [['id' => 'c-1', 'title' => 'Same']]]);

		$cases = $reader->casesOf(partyIds: ['p-1', 'p-2'], status: 'all');

		self::assertSame(['c-1' => ['id' => 'c-1', 'title' => 'Same']], $cases);
		self::assertSame(
			[
				['dossiq', 'case', ['requester' => 'p-1', '_limit' => CaseLeafProvider::MAX_ROWS]],
				['dossiq', 'case', ['requester' => 'p-2', '_limit' => CaseLeafProvider::MAX_ROWS]],
			],
			$this->objects->reads
		);
	}//end testCasesOfReadsEachPartyAndMergesById()

	/**
	 * Without parties no case is read.
	 *
	 * @return void
	 */
	public function testCasesOfWithoutPartiesReadsNothing(): void {
		$reader = $this->reader(rows: ['case' => [['id' => 'c-1']]]);

		self::assertSame([], $reader->casesOf(partyIds: [], status: 'all'));
		self::assertSame([], $this->objects->reads);
	}//end testCasesOfWithoutPartiesReadsNothing()

	/**
	 * Linked objects are read once, from the field's own schema, by their
	 * distinct non-empty ids, and keyed by id.
	 *
	 * @return void
	 */
	public function testLinkedByIdReadsDistinctIdsFromTheFieldSchema(): void {
		$reader = $this->reader(
			rows: [
				'caseType' => [['id' => 't-1', 'title' => 'Melding'], ['id' => 't-2', 'title' => 'Subsidie']],
				'statusType' => [['id' => 's-1', 'name' => 'Nieuw']],
			]
		);
		$cases = [
			'c-1' => ['caseType' => 't-1', 'status' => 's-1'],
			'c-2' => ['caseType' => 't-2', 'status' => 's-1'],
			'c-3' => ['caseType' => 't-1', 'status' => ''],
			'c-4' => [],
		];

		$types    = $reader->linkedById(cases: $cases, field: 'caseType');
		$statuses = $reader->linkedById(cases: $cases, field: 'status');

		self::assertSame(['t-1' => ['id' => 't-1', 'title' => 'Melding'], 't-2' => ['id' => 't-2', 'title' => 'Subsidie']], $types);
		self::assertSame(['s-1' => ['id' => 's-1', 'name' => 'Nieuw']], $statuses);
		self::assertSame(
			[
				['dossiq', 'caseType', ['_ids' => ['t-1', 't-2'], '_limit' => CaseLeafProvider::MAX_ROWS]],
				['dossiq', 'statusType', ['_ids' => ['s-1'], '_limit' => CaseLeafProvider::MAX_ROWS]],
			],
			$this->objects->reads
		);
	}//end testLinkedByIdReadsDistinctIdsFromTheFieldSchema()

	/**
	 * Cases without any linked id read nothing.
	 *
	 * @return void
	 */
	public function testLinkedByIdWithoutIdsReadsNothing(): void {
		$reader = $this->reader(rows: []);

		self::assertSame([], $reader->linkedById(cases: ['c-1' => ['caseType' => '']], field: 'caseType'));
		self::assertSame([], $this->objects->reads);
	}//end testLinkedByIdWithoutIdsReadsNothing()

	/**
	 * A failed read is logged as a warning with its schema and reads as none,
	 * while the other schemas are still read.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndReadsAsNone(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq case leaf: reading {schema} failed: {message}',
			['schema' => 'brpPerson', 'message' => 'register unavailable']
		);
		$reader = $this->reader(
			rows: [
				'brpPerson' => new \RuntimeException('register unavailable'),
				'kvkCompany' => [['id' => 'k-1', 'masterEntityRef' => 'm-1']],
			],
			logger: $logger
		);

		self::assertSame(['k-1'], $reader->partyIds(ref: 'm-1'));
	}//end testAFailedReadIsLoggedAndReadsAsNone()
}//end class
