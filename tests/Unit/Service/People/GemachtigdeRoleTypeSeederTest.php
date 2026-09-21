<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\GemachtigdeRoleTypeSeeder;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Exactly one generic Gemachtigde role type, on every case type.
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
 */
class GemachtigdeRoleTypeSeederTest extends TestCase {

	/**
	 * The configured register and schemas.
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'role_type_schema' => 'roleType',
	];

	/**
	 * The doubled object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Stand up a doubled OpenRegister holding no role type at all.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/**
			 * The role type rows this OpenRegister holds.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * Every write this seed made, in order.
			 *
			 * @var array<int, array{object: array<string, mixed>, uuid: string|null}>
			 */
			public array $writes = [];

			/**
			 * Whether runAsSystem() was the wrapper the write ran inside.
			 *
			 * @var bool
			 */
			public bool $elevated = false;

			/**
			 * The rows, whatever the filters ask.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->rows;
			}

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The row to update, null to create.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->writes[] = ['object' => $object, 'uuid' => $uuid];
				return $object;
			}

			/**
			 * Run an operation as the system principal.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed Whatever it returns.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->elevated = true;
				return $operation();
			}
		};
	}//end setUp()

	/**
	 * The seeder on the doubled OpenRegister.
	 *
	 * @return GemachtigdeRoleTypeSeeder The seeder.
	 */
	private function seeder(): GemachtigdeRoleTypeSeeder {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);

		return new GemachtigdeRoleTypeSeeder(settingsService: $settings);
	}//end seeder()

	/**
	 * An instance holding no Gemachtigde gets the row, with no case type on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function testTheGenericRoleTypeIsCreatedWhenNothingHoldsIt(): void {
		$this->objects->rows = [
			['id' => 'rt-1', 'name' => 'Behandelaar', '@self' => ['slug' => 'rol-behandelaar']],
		];

		$result = $this->seeder()->seed();

		$this->assertSame(1, $result['created']);
		$this->assertCount(1, $this->objects->writes);
		$this->assertSame('gemachtigde', $this->objects->writes[0]['object']['genericRole']);
		$this->assertArrayNotHasKey('caseType', $this->objects->writes[0]['object']);
		$this->assertNull($this->objects->writes[0]['uuid']);
		$this->assertTrue($this->objects->elevated);
	}//end testTheGenericRoleTypeIsCreatedWhenNothingHoldsIt()

	/**
	 * The second run writes nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function testASecondRunCreatesNothing(): void {
		$this->objects->rows = [
			['id' => 'rt-9', 'name' => 'Gemachtigde', 'genericRole' => 'gemachtigde', '@self' => ['slug' => 'rol-gemachtigde']],
		];

		$result = $this->seeder()->seed();

		$this->assertSame(0, $result['created']);
		$this->assertSame(0, $result['adopted']);
		$this->assertSame(1, $result['kept']);
		$this->assertSame([], $this->objects->writes);
	}//end testASecondRunCreatesNothing()

	/**
	 * The row an upgraded instance already holds is stamped, not duplicated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function testTheShippedRowIsAdoptedRatherThanDuplicated(): void {
		$this->objects->rows = [
			[
				'id' => 'rt-7',
				'name' => 'Gemachtigde',
				'description' => 'Persoon of organisatie gemachtigd om namens de aanvrager op te treden',
				'@self' => ['slug' => 'rol-gemachtigde'],
			],
		];

		$result = $this->seeder()->seed();

		$this->assertSame(1, $result['adopted']);
		$this->assertSame(0, $result['created']);
		$this->assertCount(1, $this->objects->writes);
		$this->assertSame('rt-7', $this->objects->writes[0]['uuid']);
		$this->assertSame('gemachtigde', $this->objects->writes[0]['object']['genericRole']);
		// The row keeps the name and description an administrator may have set.
		$this->assertSame('Gemachtigde', $this->objects->writes[0]['object']['name']);
		$this->assertArrayNotHasKey('@self', $this->objects->writes[0]['object']);
	}//end testTheShippedRowIsAdoptedRatherThanDuplicated()

	/**
	 * A case type's own gemachtigde seat is not the generic one.
	 *
	 * A role type naming a case type is offered on that type alone, so an
	 * instance holding only that one still has no representative role on every
	 * other case type. Treating it as the generic row would leave the whole
	 * requirement unmet while the seed reported success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function testARoleTypeScopedToACaseTypeDoesNotSatisfyTheGenericOne(): void {
		$this->objects->rows = [
			[
				'id' => 'rt-3',
				'name' => 'Gemachtigde',
				'genericRole' => 'gemachtigde',
				'caseType' => 'ct-bezwaar',
				'@self' => ['slug' => 'bezwaar-gemachtigde'],
			],
		];

		$result = $this->seeder()->seed();

		$this->assertSame(1, $result['created']);
		$this->assertCount(1, $this->objects->writes);
		$this->assertNull($this->objects->writes[0]['uuid']);
	}//end testARoleTypeScopedToACaseTypeDoesNotSatisfyTheGenericOne()

	/**
	 * An OpenRegister that cannot be reached costs an upgrade, not the install.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function testAnAbsentOpenRegisterIsReportedRatherThanThrown(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$result = (new GemachtigdeRoleTypeSeeder(settingsService: $settings))->seed();

		$this->assertFalse($result['available']);
		$this->assertSame(0, $result['created']);
	}//end testAnAbsentOpenRegisterIsReportedRatherThanThrown()
}//end class
