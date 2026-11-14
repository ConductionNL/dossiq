<?php

/**
 * Tests for reading case values into a product.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Product
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Product;

use OCA\Dossiq\Service\Product\ProductOutcome;
use PHPUnit\Framework\TestCase;

/**
 * A case value is a top-level field, else a property answer, compacted when declared.
 *
 * @covers \OCA\Dossiq\Service\Product\ProductOutcome
 */
class ProductOutcomeTest extends TestCase {

	/**
	 * An outcome over this case and declaration.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $declared The declaration.
	 *
	 * @return ProductOutcome
	 */
	private static function outcome(array $case, array $declared = []): ProductOutcome {
		return new ProductOutcome(objectService: new \stdClass(), register: 'dossiq', caseObject: $case, declared: $declared);
	}//end outcome()

	/**
	 * A top-level scalar wins over a property answer of the same name, trimmed.
	 *
	 * @return void
	 */
	public function testATopLevelFieldWinsOverAPropertyAnswer(): void {
		$outcome = self::outcome(case: ['adres' => ' Lindelaan 4 ', 'nummer' => 12, 'properties' => [['name' => 'adres', 'value' => 'Elders 1']]]);

		self::assertSame('Lindelaan 4', $outcome->answer(name: 'adres'));
		self::assertSame('12', $outcome->answer(name: 'nummer'));
	}//end testATopLevelFieldWinsOverAPropertyAnswer()

	/**
	 * A property answer is read when there is no scalar field, skipping malformed rows.
	 *
	 * @return void
	 */
	public function testAPropertyAnswerIsReadWhenNoFieldIs(): void {
		$outcome = self::outcome(
			case: [
				'kenteken' => ['nested'],
				'properties' => ['junk', ['name' => 'kenteken', 'value' => ['x']], ['name' => 'kenteken', 'value' => ' gz-482-k ']],
			]
		);

		self::assertSame('gz-482-k', $outcome->answer(name: 'kenteken'));
	}//end testAPropertyAnswerIsReadWhenNoFieldIs()

	/**
	 * A blank or unknown name answers the empty string.
	 *
	 * @return void
	 */
	public function testABlankOrUnknownNameAnswersEmpty(): void {
		$outcome = self::outcome(case: ['' => 'x', 'properties' => 'none']);

		self::assertSame('', $outcome->answer(name: ''));
		self::assertSame('', $outcome->answer(name: 'missing'));
	}//end testABlankOrUnknownNameAnswersEmpty()

	/**
	 * A compact field is stored upper-cased without spaces or dashes; other fields as answered.
	 *
	 * @return void
	 */
	public function testCompactFieldsAreNormalised(): void {
		$outcome = self::outcome(case: ['kenteken' => 'gz-482 k', 'adres' => 'Lindelaan 4'], declared: ['compactFields' => ['kenteken']]);

		self::assertSame('GZ482K', $outcome->stored(field: 'kenteken', name: 'kenteken'));
		self::assertSame('Lindelaan 4', $outcome->stored(field: 'adres', name: 'adres'));
		self::assertSame('gz-482 k', self::outcome(case: ['kenteken' => 'gz-482 k'])->stored(field: 'kenteken', name: 'kenteken'));
	}//end testCompactFieldsAreNormalised()
}//end class
