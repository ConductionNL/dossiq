<?php

/**
 * Unit tests for RecordValues: imported values are stored as compact JSON
 * when there is something to store, read as numbers only when numeric, and
 * written as case answers in the text form the case keeps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use OCA\Dossiq\Service\Import\RecordValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 */
class RecordValuesTest extends TestCase {

	public function testSomethingIsStoredAsCompactUnescapedJson(): void {
		$values = new RecordValues();

		$this->assertSame('{"straat":"Oudegracht","url":"https://x.nl/a"}', $values->json(['straat' => 'Oudegracht', 'url' => 'https://x.nl/a']));
		$this->assertSame('"Café"', $values->json('Café'));
		$this->assertSame('0', $values->json(0));
		$this->assertSame('false', $values->json(false));
	}//end testSomethingIsStoredAsCompactUnescapedJson()

	public function testNothingIsStoredForEmptyValuesOrUnencodableOnes(): void {
		$values = new RecordValues();

		$this->assertNull($values->json(null));
		$this->assertNull($values->json(''));
		$this->assertNull($values->json([]));
		$this->assertNull($values->json(NAN));
	}//end testNothingIsStoredForEmptyValuesOrUnencodableOnes()

	public function testANumberIsReadOnlyFromNumericText(): void {
		$values = new RecordValues();

		$this->assertSame(42, $values->number('42'));
		$this->assertSame(1.5, $values->number('1.5'));
		$this->assertSame(-3, $values->number('-3'));
		$this->assertNull($values->number('42 euro'));
		$this->assertNull($values->number(''));
	}//end testANumberIsReadOnlyFromNumericText()

	public function testAnAnswerKeepsScalarsAsTextAndEncodesTheRest(): void {
		$values = new RecordValues();
		$property = ['definition' => 'pd-1', 'name' => 'oppervlakte'];

		$this->assertSame(['propertyDefinition' => 'pd-1', 'name' => 'oppervlakte', 'value' => '120'], $values->answer($property, 120));
		$this->assertSame('true', $values->answer($property, true)['value']);
		$this->assertSame('false', $values->answer($property, false)['value']);
		$this->assertSame('Café', $values->answer($property, 'Café')['value']);
		$this->assertSame('["a","b/c"]', $values->answer($property, ['a', 'b/c'])['value']);
		$this->assertSame('null', $values->answer($property, null)['value']);
		$this->assertSame(['propertyDefinition' => '', 'name' => '', 'value' => 'x'], $values->answer([], 'x'));
	}//end testAnAnswerKeepsScalarsAsTextAndEncodesTheRest()
}//end class
