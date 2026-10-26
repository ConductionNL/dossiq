<?php

/**
 * InheritedRowValues unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\InheritedRowValues;
use PHPUnit\Framework\TestCase;

/**
 * What a child type's own declaration means for the merge.
 *
 * @covers \OCA\Dossiq\Service\CaseType\InheritedRowValues
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class InheritedRowValuesTest extends TestCase {

	/**
	 * Null, an empty array and blank text are "said nothing"; anything else is a value.
	 *
	 * @return void
	 */
	public function testEmptyIsNothingSaid(): void {
		$values = new InheritedRowValues();

		foreach ([null, [], '', '  '] as $empty) {
			self::assertTrue($values->isEmpty(value: $empty));
		}

		foreach (['x', 0, false, ['a']] as $value) {
			self::assertFalse($values->isEmpty(value: $value));
		}
	}//end testEmptyIsNothingSaid()

	/**
	 * A lifecycle needs a start and an end: one status that is not final and one that is.
	 *
	 * @return void
	 */
	public function testAWholeLifecycleHasAStartAndAnEnd(): void {
		$values = new InheritedRowValues();

		self::assertTrue($values->isWholeLifecycle(rows: [['isFinal' => false], ['isFinal' => true]]));
		self::assertFalse($values->isWholeLifecycle(rows: [['isFinal' => false]]));
		self::assertFalse($values->isWholeLifecycle(rows: [['isFinal' => true]]));
		self::assertFalse($values->isWholeLifecycle(rows: []));
	}//end testAWholeLifecycleHasAStartAndAnEnd()
}//end class
