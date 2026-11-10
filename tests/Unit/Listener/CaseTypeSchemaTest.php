<?php

/**
 * CaseTypeSchema.
 *
 * The case type recognition the case type listeners share: by schema id, or
 * by a schema reference ending in it, read from `@self` before the payload.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseTypeSchema;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Which payloads are case types.
 *
 * @covers \OCA\Dossiq\Listener\CaseTypeSchema
 */
class CaseTypeSchemaTest extends TestCase {

	/**
	 * The recogniser over a configured schema.
	 *
	 * @param string $configured The configured case type schema.
	 *
	 * @return CaseTypeSchema
	 */
	private function schema(string $configured): CaseTypeSchema {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($key === 'case_type_schema' ? $configured : 'other'));

		return new CaseTypeSchema($settings);
	}//end schema()

	/**
	 * By id, by a reference ending in it, @self first; nothing without a configured schema.
	 *
	 * @return void
	 */
	public function testWhichPayloadsAreCaseTypes(): void {
		$schema = $this->schema('12');

		$this->assertTrue($schema->contains(['@self' => ['schema' => '12']]));
		$this->assertTrue($schema->contains(['schema' => '12']));
		$this->assertTrue($schema->contains(['@self' => ['schema' => 'https://x.test/api/schemas/12']]));
		$this->assertFalse($schema->contains(['@self' => ['schema' => '112']]));
		$this->assertFalse($schema->contains(['@self' => ['schema' => '13'], 'schema' => '12']));
		$this->assertFalse($schema->contains(['@self' => ['schema' => ''], 'schema' => '12']));
		$this->assertFalse($schema->contains([]));
		$this->assertFalse($this->schema('')->contains(['schema' => '']));
	}//end testWhichPayloadsAreCaseTypes()
}//end class
