<?php

/**
 * Unit tests for PropertyDefinitionRecord.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\PropertyDefinitionRecord;
use PHPUnit\Framework\TestCase;

/**
 * A record names its property, case type, type and default; only an enum carries its values.
 *
 * @covers \OCA\Dossiq\Repair\Support\PropertyDefinitionRecord
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PropertyDefinitionRecordTest extends TestCase {

	/**
	 * A plain property: the four fields, the default as text, no enum values.
	 *
	 * @return void
	 */
	public function testAPlainPropertyCarriesNoEnumValues(): void {
		$record = (new PropertyDefinitionRecord(enumValues: ['a', 'b']))->record(
			property: 'ceiling',
			type: 'number',
			caseTypeId: 'ct-1',
			value: 25000
		);

		self::assertSame(
			expected: ['name' => 'ceiling', 'caseType' => 'ct-1', 'propertyType' => 'number', 'defaultValue' => '25000'],
			actual: $record
		);
	}//end testAPlainPropertyCarriesNoEnumValues()

	/**
	 * An enum property carries the values it allows, as given.
	 *
	 * @return void
	 */
	public function testAnEnumCarriesItsAllowedValues(): void {
		$record = (new PropertyDefinitionRecord(enumValues: ['none', 'annually']))->record(
			property: 'frequency',
			type: 'enum',
			caseTypeId: 'ct-1',
			value: 'annually'
		);

		self::assertSame(expected: ['none', 'annually'], actual: $record['enumValues']);
		self::assertSame(expected: 'annually', actual: $record['defaultValue']);
	}//end testAnEnumCarriesItsAllowedValues()
}//end class
