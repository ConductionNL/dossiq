<?php

/**
 * Where a case merge reads and writes, when the register is absent or fails.
 *
 * A merge that cannot read or write must answer "nothing" or "did not land"
 * and log why, never throw past the merge and never claim a write it did not
 * make.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseMergeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reads, partial writes and searches under an absent or failing register.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseMergeStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Support\ReferencedUuid
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseMergeStoreTest extends TestCase {

	/**
	 * The register the store reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The logger the store reports to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A register holding one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(schema: 'case', uuid: 'c-1', row: ['title' => 'Kerkstraat 12', 'mergeState' => 'active']);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Without OpenRegister nothing is read, written or found, and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsReadOrWritten(): void {
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$store = $this->store(service: null);

		self::assertNull($store->readCase(caseId: 'c-1'));
		self::assertFalse($store->patchCase(caseId: 'c-1', changes: ['mergeState' => 'merged']));
		self::assertSame([], $store->rowsFor(schemaId: 'role', filters: ['case' => 'c-1']));
	}//end testWithoutOpenRegisterNothingIsReadOrWritten()

	/**
	 * An unconfigured case schema reads and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaReadsAndWritesNothing(): void {
		$store = $this->store(service: $this->register, config: ['register' => 'dossiq']);

		self::assertNull($store->readCase(caseId: 'c-1'));
		self::assertFalse($store->patchCase(caseId: 'c-1', changes: ['mergeState' => 'merged']));
		self::assertSame(0, $this->register->writes);
	}//end testAnUnconfiguredCaseSchemaReadsAndWritesNothing()

	/**
	 * A read the register refuses is logged and answers null.
	 *
	 * @return void
	 */
	public function testARefusedReadIsLoggedAndAnswersNull(): void {
		$this->register->refusedToCaller = ['case'];
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq: case "c-1" could not be read: '
				. "User 'Anonymous' does not have permission to 'read' objects in schema 'case'"
			);

		self::assertNull($this->store(service: $this->register)->readCase(caseId: 'c-1'));
	}//end testARefusedReadIsLoggedAndAnswersNull()

	/**
	 * A write on a row that is not there is logged as an error and did not land.
	 *
	 * @return void
	 */
	public function testAWriteOnAMissingRowDidNotLand(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with('Dossiq: a merge write on "nope" failed: object_not_found_for_partial_write');

		self::assertFalse(
			$this->store(service: $this->register)->patchRow(schemaId: 'case', id: 'nope', changes: ['case' => 'c-2'])
		);
		self::assertSame(0, $this->register->writes);
	}//end testAWriteOnAMissingRowDidNotLand()

	/**
	 * A write on an existing case lands and keeps the fields it did not name.
	 *
	 * @return void
	 */
	public function testAWriteOnACaseLandsAndKeepsTheRest(): void {
		self::assertTrue($this->store(service: $this->register)->patchCase(caseId: 'c-1', changes: ['mergeState' => 'merged']));

		$row = $this->register->row(schema: 'case', uuid: 'c-1');
		self::assertSame('merged', $row['mergeState']);
		self::assertSame('Kerkstraat 12', $row['title']);
	}//end testAWriteOnACaseLandsAndKeepsTheRest()

	/**
	 * A search on a schema the register does not hold is logged and finds nothing.
	 *
	 * @return void
	 */
	public function testASearchOnAMissingSchemaIsLoggedAndFindsNothing(): void {
		$this->register->seed(schema: 'role', uuid: 'r-1', row: ['case' => 'c-1']);
		$this->register->missingSchemas = ['role'];
		$this->logger->expects($this->once())
			->method('warning')
			->with('Dossiq: a merge read failed: No schema role in this register');

		self::assertSame([], $this->store(service: $this->register)->rowsFor(schemaId: 'role', filters: ['case' => 'c-1']));
	}//end testASearchOnAMissingSchemaIsLoggedAndFindsNothing()

	/**
	 * An unknown slug has no schema id and never asks the configuration.
	 *
	 * @return void
	 */
	public function testAnUnknownSlugHasNoSchemaId(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getConfigValue');
		$store = new CaseMergeStore(settingsService: $settings, logger: $this->logger);

		self::assertSame('', $store->schemaId(slug: 'noSuchSchema'));
		self::assertSame('', $store->schemaId(slug: ''));
	}//end testAnUnknownSlugHasNoSchemaId()

	/**
	 * A known slug reads its configured id; a reference reads as its uuid either way.
	 *
	 * @return void
	 */
	public function testAKnownSlugAndAReferenceResolve(): void {
		$store = $this->store(service: $this->register);

		self::assertSame('case', $store->schemaId(slug: 'case'));
		self::assertSame('c-1', $store->referencedId(value: ' c-1 '));
		self::assertSame('c-2', $store->referencedId(value: ['id' => 'c-2', 'title' => 'Dorpsstraat 1']));
		self::assertSame('', $store->referencedId(value: 42));
	}//end testAKnownSlugAndAReferenceResolve()

	/**
	 * A store over the given object service and configuration.
	 *
	 * @param object|null           $service The object service, or null for none.
	 * @param array<string, string> $config  The configuration values.
	 *
	 * @return CaseMergeStore The store.
	 */
	private function store(?object $service, array $config = ['register' => 'dossiq', 'case_schema' => 'case']): CaseMergeStore {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new CaseMergeStore(settingsService: $settings, logger: $this->logger);
	}//end store()
}//end class
