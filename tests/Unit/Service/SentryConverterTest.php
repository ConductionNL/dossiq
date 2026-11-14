<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SentryConverter;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * One sentry through the closed conversion tables, built on its own.
 *
 * @covers \OCA\Dossiq\Service\SentryConverter
 */
class SentryConverterTest extends TestCase {

	/**
	 * A plan-item on-part and an if-part become one OpenRegister sentry, the id kept.
	 *
	 * @return void
	 */
	public function testConvertsBothPartsAndKeepsTheId(): void {
		$sentry = (new SentryConverter())->convertSentry(
			sentry: [
				'id' => ' s1 ',
				'onPart' => ['planItem' => ' intake ', 'standardEvent' => 'complete'],
				'ifPart' => ['field' => 'amount', 'operator' => 'gte', 'value' => 10],
			],
			where: 'test'
		);

		$this->assertSame(
			expected: [
				'id' => 's1',
				'on' => ['event' => 'case.item.completed', 'item' => 'intake'],
				'if' => ['>=' => [['var' => 'amount'], 10]],
			],
			actual: $sentry
		);
	}//end testConvertsBothPartsAndKeepsTheId()

	/**
	 * Every criteria entry is converted in order; a case-file on-part is an object write.
	 *
	 * @return void
	 */
	public function testCriteriaListConvertsEveryEntry(): void {
		$criteria = (new SentryConverter())->convertCriteria(
			criteria: [
				['onPart' => ['caseFileItem' => 'besluit', 'caseFileEvent' => 'changed']],
				['ifPart' => ['field' => 'x', 'operator' => 'notIn', 'value' => [1]]],
			],
			where: 'entry'
		);

		$this->assertSame(
			expected: [
				['on' => ['event' => SentryConverter::OBJECT_WRITE_EVENT]],
				['if' => ['!' => ['in' => [['var' => 'x'], [1]]]]],
			],
			actual: $criteria
		);
		$this->assertSame(expected: [], actual: (new SentryConverter())->convertCriteria(criteria: 'none', where: 'entry'));
	}//end testCriteriaListConvertsEveryEntry()

	/**
	 * A criteria entry that is not an object is refused with its one-based position.
	 *
	 * @return void
	 */
	public function testRefusesANonObjectEntryByPosition(): void {
		$this->expectException(exception: UnexpectedValueException::class);
		$this->expectExceptionMessage(message: 'entry sentry #2 is not an object.');

		(new SentryConverter())->convertCriteria(criteria: [['ifPart' => ['field' => 'a']], 'x'], where: 'entry');
	}//end testRefusesANonObjectEntryByPosition()

	/**
	 * An unknown standard event is refused by name, the table listed.
	 *
	 * @return void
	 */
	public function testRefusesAStandardEventOutsideTheTable(): void {
		$this->expectException(exception: UnexpectedValueException::class);
		$this->expectExceptionMessage(message: "w names standardEvent 'resume', which is outside the conversion table (complete, terminate, disable).");

		(new SentryConverter())->convertSentry(sentry: ['onPart' => ['planItem' => 'a', 'standardEvent' => 'resume']], where: 'w');
	}//end testRefusesAStandardEventOutsideTheTable()

	/**
	 * A sentry with neither part is refused.
	 *
	 * @return void
	 */
	public function testRefusesASentryWithNothingToConvert(): void {
		$this->expectException(exception: UnexpectedValueException::class);
		$this->expectExceptionMessage(message: 'w has neither an onPart nor an ifPart; there is nothing to convert.');

		(new SentryConverter())->convertSentry(sentry: ['id' => 'x', 'onPart' => []], where: 'w');
	}//end testRefusesASentryWithNothingToConvert()
}//end class
