<?php

/**
 * The value shapes a declaration reads a record through: booleans, numbers,
 * the catch-all status and target paths that name nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Import
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses   \OCA\Dossiq\Service\Import\RecordMappingRun
 */
class RecordCaseMappingShapesTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * Map one record through a declaration with the given fields, under the catch-all status.
	 *
	 * @param array<int, array<string, mixed>> $fields The field declarations.
	 * @param array<string, mixed>             $record The source record.
	 *
	 * @return array<string, mixed> The case payload.
	 */
	private function caseFor(array $fields, array $record): array {
		$mapped = (new RecordCaseMapping(dates: $this->caseDates()))->map(
			import: [
				'statuses' => [RecordCaseMapping::ANY_STATUS => ['status' => 's-any']],
				'fields' => $fields,
			],
			record: $record,
			recordId: 'rec-9',
			caseTypeId: 'type-9'
		);

		return $mapped['case'];
	}//end caseFor()

	/**
	 * A boolean is true only for the declared true words, in any case; a real boolean passes through.
	 *
	 * @return void
	 */
	public function testABooleanIsTrueOnlyForTheTrueWords(): void {
		$fields = [
			['to' => 'a', 'from' => 'a', 'as' => 'boolean'],
			['to' => 'b', 'from' => 'b', 'as' => 'boolean'],
			['to' => 'c', 'from' => 'c', 'as' => 'boolean'],
			['to' => 'd', 'from' => 'd', 'as' => 'boolean'],
			['to' => 'e', 'from' => 'e', 'as' => 'boolean'],
			['to' => 'f', 'from' => 'f', 'as' => 'boolean'],
		];
		$case = $this->caseFor(
			fields: $fields,
			record: ['a' => 'YES', 'b' => ' True ', 'c' => 1, 'd' => 'no', 'e' => false, 'f' => 'y']
		);

		self::assertTrue($case['a']);
		self::assertTrue($case['b']);
		self::assertTrue($case['c']);
		self::assertFalse($case['d']);
		self::assertFalse($case['e']);
		self::assertFalse($case['f']);
	}//end testABooleanIsTrueOnlyForTheTrueWords()

	/**
	 * A number keeps its fraction; text that is no number leaves the field off the case.
	 *
	 * @return void
	 */
	public function testANumberKeepsItsFractionAndNonNumbersAreLeftOff(): void {
		$case = $this->caseFor(
			fields: [
				['to' => 'amount', 'from' => 'amount', 'as' => 'number'],
				['to' => 'whole', 'from' => 'whole', 'as' => 'number'],
				['to' => 'bad', 'from' => 'bad', 'as' => 'number'],
				['to' => 'blank', 'from' => 'blank', 'as' => 'boolean'],
			],
			record: ['amount' => '12.50', 'whole' => '7', 'bad' => 'twelve', 'blank' => '  ']
		);

		self::assertSame(['caseType' => 'type-9', 'amount' => 12.5, 'whole' => 7, 'status' => 's-any'], $case);
	}//end testANumberKeepsItsFractionAndNonNumbersAreLeftOff()

	/**
	 * A target path whose first key is empty writes nothing; a nested path builds its parents.
	 *
	 * @return void
	 */
	public function testATargetPathThatNamesNothingWritesNothing(): void {
		$case = $this->caseFor(
			fields: [
				['to' => '', 'from' => 'x'],
				['to' => '.hidden', 'from' => 'x'],
				['to' => 'deep.er.still', 'from' => 'x'],
			],
			record: ['x' => 'value']
		);

		self::assertSame(['caseType' => 'type-9', 'deep' => ['er' => ['still' => 'value']], 'status' => 's-any'], $case);
	}//end testATargetPathThatNamesNothingWritesNothing()

	/**
	 * Text is collapsed and cut at the last whole word; a word longer than the limit is cut hard.
	 *
	 * @return void
	 */
	public function testTextIsCollapsedAndCutAtAWord(): void {
		$case = $this->caseFor(
			fields: [
				['to' => 'title', 'from' => 'title', 'maxLength' => 12],
				['to' => 'code', 'from' => 'code', 'maxLength' => 4],
				['to' => 'short', 'from' => 'short', 'maxLength' => 40],
				['to' => 'blank', 'from' => 'blank'],
				['to' => 'listy', 'from' => 'listy'],
			],
			record: ['title' => "  Een   lange
 titel hier ", 'code' => 'ABCDEFGH', 'short' => 'kort', 'blank' => '   ', 'listy' => ['x']]
		);

		self::assertSame(
			['caseType' => 'type-9', 'title' => 'Een lange', 'code' => 'ABCD', 'short' => 'kort', 'status' => 's-any'],
			$case
		);
	}//end testTextIsCollapsedAndCutAtAWord()

	/**
	 * A listed value maps through its list, an empty one takes the default, an unknown one is refused.
	 *
	 * @return void
	 */
	public function testAListedValueMapsOrIsRefused(): void {
		$field = ['to' => 'kind', 'from' => 'kind', 'values' => ['a' => 'alpha', 'b' => 'beta'], 'default' => 'none'];

		self::assertSame('beta', $this->caseFor(fields: [$field], record: ['kind' => ' b '])['kind']);
		self::assertSame('none', $this->caseFor(fields: [$field], record: [])['kind']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The kind must be one of: a, b.');
		$this->caseFor(fields: [$field], record: ['kind' => 'c']);
	}//end testAListedValueMapsOrIsRefused()

	/**
	 * Dates, moments and integers take their shape; an unreadable date writes nothing.
	 *
	 * @return void
	 */
	public function testDatesMomentsAndIntegersTakeTheirShape(): void {
		$case = $this->caseFor(
			fields: [
				['to' => 'on', 'from' => 'on', 'as' => 'date'],
				['to' => 'at', 'from' => 'at', 'as' => 'dateTime'],
				['to' => 'count', 'from' => 'count', 'as' => 'integer'],
				['to' => 'bad', 'from' => 'bad', 'as' => 'date'],
			],
			record: ['on' => '2026-03-05', 'at' => '2026-03-05T10:15:00+00:00', 'count' => '42abc', 'bad' => 'someday']
		);

		self::assertSame('2026-03-05', $case['on']);
		// The moment is written in the tenant's zone, Amsterdam here.
		self::assertSame('2026-03-05T11:15:00+01:00', $case['at']);
		self::assertSame(42, $case['count']);
		self::assertArrayNotHasKey('bad', $case);
	}//end testDatesMomentsAndIntegersTakeTheirShape()
}//end class
