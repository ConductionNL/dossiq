<?php

/**
 * Unit tests for RepairSchemas: a repair gets a schema to work on only when
 * OpenRegister is there and both the register and that schema are configured.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\RepairSchemas;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\Dossiq\Repair\Support\RepairSchemas
 *
 * @uses \OCA\Dossiq\Repair\Support\RepairSchema
 */
class RepairSchemasTest extends TestCase {

	/**
	 * Schemas over settings with the given object service and config.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        Config values by key.
	 */
	private function schemas(?object $objectService, array $config): RepairSchemas {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $config[$key] ?? '');
		return new RepairSchemas($settings);
	}//end schemas()

	public function testAConfiguredSchemaIsReturnedOnTheConfiguredRegister(): void {
		$service = new stdClass();

		$target = $this->schemas($service, ['register' => '4', 'case_schema' => '12'])->configured('case_schema');

		$this->assertNotNull($target);
		$this->assertSame($service, $target->objectService);
		$this->assertSame('4', $target->register);
		$this->assertSame('12', $target->schema);
	}//end testAConfiguredSchemaIsReturnedOnTheConfiguredRegister()

	public function testAnyMissingPieceGivesNoSchema(): void {
		$this->assertNull($this->schemas(null, ['register' => '4', 'case_schema' => '12'])->configured('case_schema'));
		$this->assertNull($this->schemas(new stdClass(), ['case_schema' => '12'])->configured('case_schema'));
		$this->assertNull($this->schemas(new stdClass(), ['register' => '4', 'case_schema' => '12'])->configured('role_schema'));
	}//end testAnyMissingPieceGivesNoSchema()
}//end class
