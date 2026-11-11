<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 *
 * PartnerMigrationService summary tests: one partner migrated with its
 * mapping, one already present skipped, a row without an id and a failed
 * insert counted as failed; and an empty summary when OpenRegister is
 * missing or the partner rows cannot be read.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\PartnerMigrationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the PartnerMigrationService summary.
 */
class PartnerMigrationSummaryTest extends TestCase {
	/**
	 * The service over the given rows, or over a read that throws when null.
	 *
	 * @param array<int, mixed>|null $rows      The partner rows, or null to fail the read.
	 * @param bool                   $installed Whether OpenRegister is installed.
	 *
	 * @return PartnerMigrationService
	 */
	private function service(?array $rows, bool $installed = true): PartnerMigrationService {
		$objectService = new class ($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed>|null $rows The rows, or null to fail.
			 */
			public function __construct(private ?array $rows) {
			}//end __construct()

			/**
			 * @param string               $registerSlug The register.
			 * @param string               $schemaSlug   The schema.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				if ($this->rows === null) {
					throw new RuntimeException('schema absent');
				}

				return $this->rows;
			}//end searchObjectsBySlug()
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$mapper = new class {
			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object The organisation.
			 */
			public function findByUuid(string $uuid): object {
				if ($uuid !== 'partner-present') {
					throw new RuntimeException('does not exist');
				}

				return new class {
					/** @return string */
					public function getUuid(): string {
						return 'partner-present';
					}//end getUuid()
				};
			}//end findByUuid()

			/**
			 * @param object $entity The organisation.
			 *
			 * @return object The organisation.
			 */
			public function insert(object $entity): object {
				if ($entity->getUuid() === 'partner-broken') {
					throw new RuntimeException('insert refused');
				}

				return $entity;
			}//end insert()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed === true ? ['openregister'] : []);

		return new PartnerMigrationService($settings, $container, $appManager, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A run counts each row once and maps the migrated partner.
	 *
	 * @return void
	 */
	public function testTheSummaryCountsEveryRow(): void {
		$rows = [
			['id' => 'partner-new', 'name' => 'Gemeente Zwolle', 'contactEmail' => 'a@zwolle.nl'],
			['id' => 'partner-present', 'name' => 'Gemeente Deventer'],
			['name' => 'No id'],
			['id' => 'partner-broken', 'name' => 'Broken'],
		];

		self::assertSame(
			[
				'migrated' => 1,
				'skipped' => 1,
				'failed' => 2,
				'total' => 4,
				'mappings' => [['partner' => 'partner-new', 'organisation' => 'partner-new']],
			],
			$this->service($rows)->migrate()
		);
	}//end testTheSummaryCountsEveryRow()

	/**
	 * Without OpenRegister, or without readable partner rows, the summary is empty.
	 *
	 * @return void
	 */
	public function testNothingIsMigratedWithoutItsPrerequisites(): void {
		$empty = ['migrated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0, 'mappings' => []];

		self::assertSame($empty, $this->service([['id' => 'partner-new']], false)->migrate());
		self::assertSame($empty, $this->service(null)->migrate());
	}//end testNothingIsMigratedWithoutItsPrerequisites()
}//end class
