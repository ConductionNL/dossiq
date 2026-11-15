<?php

/**
 * The gemachtigde role type seeder at its edges: refusals, scoped seeds, missing configuration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\GemachtigdeRoleTypeSeeder;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for GemachtigdeRoleTypeSeeder at its edges.
 *
 * @covers \OCA\Dossiq\Service\People\GemachtigdeRoleTypeSeeder
 * @uses \OCA\Dossiq\Service\People\RoleTypeStore
 */
class GemachtigdeRoleTypeSeederEdgeTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * An object service with these role type rows; saving throws when told to.
	 *
	 * @param array<int, array<string, mixed>> $rows      The stored role types.
	 * @param string|null                      $saveError The message saving throws, or null.
	 *
	 * @return void
	 */
	private function objects(array $rows, ?string $saveError = null): void {
		$this->objects = new class ($rows, $saveError) {
			/**
			 * Saved objects as [object, uuid].
			 *
			 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
			 */
			public array $saved = [];

			/**
			 * @param array<int, array<string, mixed>> $rows      The rows.
			 * @param string|null                      $saveError The save error.
			 */
			public function __construct(private array $rows, private ?string $saveError) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($this->saveError !== null) {
					throw new RuntimeException($this->saveError);
				}

				$this->saved[] = [$object, $uuid];
				return $object;
			}
		};
	}//end objects()

	/**
	 * The seeder over the object service and this configuration.
	 *
	 * @param array<string, string> $config The configured values.
	 *
	 * @return GemachtigdeRoleTypeSeeder The service under test.
	 */
	private function seeder(array $config = ['register' => 'dossiq', 'role_type_schema' => 'roleType']): GemachtigdeRoleTypeSeeder {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new GemachtigdeRoleTypeSeeder(settingsService: $settings);
	}//end seeder()

	/**
	 * A write the register refuses is reported with its message, not thrown.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsReported(): void {
		$this->objects(rows: [], saveError: 'no write rights');

		self::assertSame(
			['available' => true, 'created' => 0, 'adopted' => 0, 'kept' => 0, 'refused' => 'no write rights'],
			$this->seeder()->seed()
		);
	}//end testARefusedWriteIsReported()

	/**
	 * A seeded row scoped to a case type is not adopted: a new generic one is created.
	 *
	 * @return void
	 */
	public function testAScopedSeededRowIsNotAdopted(): void {
		$this->objects(rows: [['@self' => ['slug' => GemachtigdeRoleTypeSeeder::SEEDED_SLUG, 'id' => 'rt-scoped'], 'caseType' => 'ct-1', 'name' => 'Gemachtigde']]);

		$result = $this->seeder()->seed();

		self::assertSame(1, $result['created']);
		self::assertSame(0, $result['adopted']);
		self::assertCount(1, $this->objects->saved);
		self::assertNull($this->objects->saved[0][1], 'A new row is created, not the scoped one updated.');
		self::assertSame(GemachtigdeRoleTypeSeeder::GENERIC_ROLE, $this->objects->saved[0][0]['genericRole']);
	}//end testAScopedSeededRowIsNotAdopted()

	/**
	 * The adopted row's uuid comes from @self when the row carries none of its own.
	 *
	 * @return void
	 */
	public function testTheAdoptedUuidFallsBackToSelf(): void {
		$this->objects(rows: [['@self' => ['slug' => GemachtigdeRoleTypeSeeder::SEEDED_SLUG, 'uuid' => 'rt-self'], 'id' => '', 'name' => 'Gemachtigde']]);

		$result = $this->seeder()->seed();

		self::assertSame(1, $result['adopted']);
		self::assertSame('rt-self', $this->objects->saved[0][1]);
		self::assertArrayNotHasKey('@self', $this->objects->saved[0][0]);
	}//end testTheAdoptedUuidFallsBackToSelf()

	/**
	 * A seeded row without any identifier is still adopted, saved without a uuid.
	 *
	 * @return void
	 */
	public function testASeededRowWithoutAnIdIsSavedWithoutAUuid(): void {
		$this->objects(rows: [['@self' => ['slug' => GemachtigdeRoleTypeSeeder::SEEDED_SLUG], 'name' => 'Gemachtigde']]);

		$result = $this->seeder()->seed();

		self::assertSame(1, $result['adopted']);
		self::assertNull($this->objects->saved[0][1]);
		self::assertSame(['name' => 'Gemachtigde', 'genericRole' => GemachtigdeRoleTypeSeeder::GENERIC_ROLE], $this->objects->saved[0][0]);
	}//end testASeededRowWithoutAnIdIsSavedWithoutAUuid()

	/**
	 * An unconfigured register or role type schema does nothing.
	 *
	 * @return void
	 */
	public function testMissingConfigurationDoesNothing(): void {
		$this->objects(rows: []);
		$nothing = ['available' => false, 'created' => 0, 'adopted' => 0, 'kept' => 0, 'refused' => null];

		self::assertSame($nothing, $this->seeder(config: ['role_type_schema' => 'roleType'])->seed());
		self::assertSame($nothing, $this->seeder(config: ['register' => 'dossiq'])->seed());
		self::assertSame([], $this->objects->saved);
	}//end testMissingConfigurationDoesNothing()
}//end class
