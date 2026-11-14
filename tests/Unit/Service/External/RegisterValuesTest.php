<?php

/**
 * Unit tests for RegisterValues: values from an external register are read as
 * strings, integers or string lists only when they are one, and fragment lists
 * are mapped over their object entries only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External;

use OCA\Dossiq\Service\External\RegisterValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\RegisterValues
 */
class RegisterValuesTest extends TestCase {

	public function testAStringIsAnyNonEmptyStringOrANumber(): void {
		$values = new RegisterValues();

		$this->assertSame('A', $values->stringOrNull('A'));
		$this->assertSame('0', $values->stringOrNull('0'));
		$this->assertSame('42', $values->stringOrNull(42));
		$this->assertSame('1.5', $values->stringOrNull(1.5));
		$this->assertNull($values->stringOrNull(''));
		$this->assertNull($values->stringOrNull(true));
		$this->assertNull($values->stringOrNull(['A']));
		$this->assertNull($values->stringOrNull(null));
	}//end testAStringIsAnyNonEmptyStringOrANumber()

	public function testAnIntegerIsANumberOrANumericString(): void {
		$values = new RegisterValues();

		$this->assertSame(250, $values->intOrNull(250));
		$this->assertSame(250, $values->intOrNull(250.9));
		$this->assertSame(250, $values->intOrNull('250'));
		$this->assertSame(250, $values->intOrNull('250.7'));
		$this->assertNull($values->intOrNull('250 m2'));
		$this->assertNull($values->intOrNull(''));
		$this->assertNull($values->intOrNull(false));
		$this->assertNull($values->intOrNull(null));
	}//end testAnIntegerIsANumberOrANumericString()

	public function testAStringListKeepsOnlyNonEmptyStrings(): void {
		$values = new RegisterValues();

		$this->assertSame(['woonfunctie'], $values->toStringArray('woonfunctie'));
		$this->assertSame(['woonfunctie', 'kantoorfunctie'], $values->toStringArray(['woonfunctie', '', 3, null, 'kantoorfunctie']));
		$this->assertSame([], $values->toStringArray(''));
		$this->assertSame([], $values->toStringArray(7));
	}//end testAStringListKeepsOnlyNonEmptyStrings()

	public function testFragmentsAreMappedOverTheirObjectEntriesInOrder(): void {
		$this->assertSame(
			['A', 'B'],
			(new RegisterValues())->mapFragments([['sectie' => 'A'], 'x', null, ['sectie' => 'B']], static fn (array $item): string => $item['sectie'])
		);
		$this->assertSame([], (new RegisterValues())->mapFragments([], static fn (array $item): string => 'never'));
	}//end testFragmentsAreMappedOverTheirObjectEntriesInOrder()
}//end class
