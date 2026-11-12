<?php

/**
 * Reading custody holdings, and the register context the read needs.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\CustodyRows;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Custody\CustodyRows
 * @covers \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CustodyRowsTest extends TestCase {

	/**
	 * A settings bridge with the given object service and configuration.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        Configured values; anything else answers ''.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return $settings;
	}//end settings()

	/**
	 * An object service that records its slug searches and answers fixed rows.
	 *
	 * @param array<int, mixed> $rows The rows to answer.
	 *
	 * @return object
	 */
	private function objectService(array $rows): object {
		return new class($rows) {
			/**
			 * Calls made, as [register, schema, filters].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows Rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * Search by slugs.
			 *
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param array<string, mixed> $filters  Filters.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = [$register, $schema, $filters];
				return $this->rows;
			}
		};
	}//end objectService()

	public function testFindSearchesTheCustodySchemaOfTheConfiguredRegister(): void {
		$service = $this->objectService(rows: [['id' => 'h-1', 'caseId' => 'c-1'], 'skipped']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$rows = new CustodyRows(
			settingsService: $this->settings(objectService: $service, config: ['register' => 'dossiq', 'case_custody_schema' => 'custody']),
			logger: $logger,
		);

		$found = $rows->find(filters: ['caseId' => 'c-1', '_limit' => 50], failure: 'unused');

		self::assertSame([['id' => 'h-1', 'caseId' => 'c-1']], $found);
		self::assertSame([['dossiq', 'custody', ['caseId' => 'c-1', '_limit' => 50]]], $service->calls);
	}//end testFindSearchesTheCustodySchemaOfTheConfiguredRegister()

	public function testAnUnconfiguredSchemaIsLoggedWithTheFiltersButNotTheLimit(): void {
		$service = $this->objectService(rows: [['id' => 'never']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'custody unreadable',
				['organisationUnit' => 'ou-7', 'exception' => 'Dossiq schema case_custody_schema not configured']
			);

		$rows = new CustodyRows(
			settingsService: $this->settings(objectService: $service, config: ['register' => 'dossiq']),
			logger: $logger,
		);

		self::assertSame([], $rows->find(filters: ['organisationUnit' => 'ou-7', '_limit' => 10], failure: 'custody unreadable'));
		self::assertSame([], $service->calls);
	}//end testAnUnconfiguredSchemaIsLoggedWithTheFiltersButNotTheLimit()

	public function testAMissingObjectServiceIsAWarningNotAnException(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('read failed', ['caseId' => 'c-2', 'exception' => 'OpenRegister is not available']);

		$rows = new CustodyRows(
			settingsService: $this->settings(objectService: null, config: ['register' => 'dossiq', 'case_custody_schema' => 'custody']),
			logger: $logger,
		);

		self::assertSame([], $rows->find(filters: ['caseId' => 'c-2'], failure: 'read failed'));
	}//end testAMissingObjectServiceIsAWarningNotAnException()

	public function testContextAnswersTheServiceAndTheRegister(): void {
		$service = new \stdClass();
		$register = new DossierRegister(settingsService: $this->settings(objectService: $service, config: ['register' => '12']));

		self::assertSame([$service, '12'], $register->context());
	}//end testContextAnswersTheServiceAndTheRegister()

	public function testContextRefusesWithoutAnObjectService(): void {
		$register = new DossierRegister(settingsService: $this->settings(objectService: null, config: ['register' => 'dossiq']));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$register->context();
	}//end testContextRefusesWithoutAnObjectService()

	public function testContextRefusesWithoutARegister(): void {
		$register = new DossierRegister(settingsService: $this->settings(objectService: new \stdClass(), config: []));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossier register not configured');
		$register->context();
	}//end testContextRefusesWithoutARegister()

	public function testSchemaAnswersTheConfiguredValueOrNamesTheMissingKey(): void {
		$register = new DossierRegister(settingsService: $this->settings(objectService: null, config: ['case_schema' => 'case']));

		self::assertSame('case', $register->schema(key: 'case_schema'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossiq schema case_custody_schema not configured');
		$register->schema(key: 'case_custody_schema');
	}//end testSchemaAnswersTheConfiguredValueOrNamesTheMissingKey()
}//end class
