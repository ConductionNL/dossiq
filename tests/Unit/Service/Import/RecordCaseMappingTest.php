<?php

/**
 * A case type's import declaration reads a record into a case: dot paths,
 * value shapes, value lists, statuses and the term.
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-a-case-type-declares-how-another-apps-records-become-its-cases-req-cri-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use InvalidArgumentException;
use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class RecordCaseMappingTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * A declaration exercising every shape.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		return [
			'statusField' => 'state',
			'statuses' => [
				'open' => ['status' => 's-open'],
				'waiting' => ['status' => 's-wait', 'term' => 'suspended'],
				'done' => ['status' => 's-done', 'term' => 'closed', 'result' => 'r-done'],
				'odd' => ['status' => 's-odd', 'term' => 'sideways'],
			],
			'fields' => [
				['to' => 'title', 'from' => 'text', 'maxLength' => 12, 'required' => true],
				['to' => 'meta.source', 'value' => 'app-x'],
				['to' => 'meta.id', 'from' => '@id'],
				['to' => 'meta.count', 'from' => 'count', 'as' => 'integer'],
				['to' => 'startDate', 'from' => 'at', 'as' => 'date'],
				['to' => 'receivedAt', 'from' => 'at', 'as' => 'dateTime'],
				['to' => 'kind', 'from' => 'kind', 'values' => ['a' => 'alpha'], 'default' => 'other'],
				['to' => 'note', 'from' => 'missing'],
			],
			'formerReference' => ['application' => 'app-x', 'from' => 'number'],
			'term' => ['endDate' => 'due', 'extensions' => 'ext', 'extensionReason' => 'why', 'closedOn' => 'closed'],
		];
	}//end declaration()

	/**
	 * Every shape lands where the declaration says.
	 *
	 * @return void
	 */
	public function testEveryDeclaredShapeLandsOnTheCase(): void {
		$mapped = (new RecordCaseMapping(dates: $this->caseDates()))->map(
			import: $this->declaration(),
			record: ['state' => 'waiting', 'text' => "Twelve  words\nand more", 'count' => '3', 'at' => '2026-10-05T10:00:00+02:00', 'number' => 'N-1', 'due' => '2026-11-23', 'ext' => 1, 'why' => 'Reason'],
			recordId: 'rec-1',
			caseTypeId: 'type-1'
		);

		self::assertSame(
			[
				'caseType' => 'type-1',
				'title' => 'Twelve words',
				'meta' => ['source' => 'app-x', 'id' => 'rec-1', 'count' => 3],
				'startDate' => '2026-10-05',
				'receivedAt' => '2026-10-05T10:00:00+02:00',
				'kind' => 'other',
				'status' => 's-wait',
				'formerReferences' => [['application' => 'app-x', 'reference' => 'N-1']],
			],
			$mapped['case']
		);
		self::assertSame(['state' => 'suspended', 'endDate' => '2026-11-23', 'extensions' => 1, 'extensionReason' => 'Reason', 'closedOn' => ''], $mapped['term']);
		self::assertSame('', $mapped['result']);
	}//end testEveryDeclaredShapeLandsOnTheCase()

	/**
	 * A closed status carries its result, a listed value is translated, and a word too long is cut hard.
	 *
	 * @return void
	 */
	public function testAClosedStatusCarriesItsResult(): void {
		$mapped = (new RecordCaseMapping(dates: $this->caseDates()))->map(
			import: $this->declaration(),
			record: ['state' => 'done', 'text' => 'Onewordthatistoolong', 'kind' => 'a', 'closed' => '2026-02-05'],
			recordId: 'rec-2',
			caseTypeId: 'type-1'
		);

		self::assertSame('Onewordthati', $mapped['case']['title']);
		self::assertSame('alpha', $mapped['case']['kind']);
		self::assertSame('closed', $mapped['term']['state']);
		self::assertSame('2026-02-05', $mapped['term']['closedOn']);
		self::assertSame('r-done', $mapped['result']);
		self::assertArrayNotHasKey('formerReferences', $mapped['case']);
	}//end testAClosedStatusCarriesItsResult()

	/**
	 * Each refusal names what the record lacks.
	 *
	 * @return void
	 */
	public function testARecordTheDeclarationCannotReadIsRefused(): void {
		$cases = [
			'does not declare: gone' => ['state' => 'gone', 'text' => 'x'],
			'unknown term state: sideways' => ['state' => 'odd', 'text' => 'x'],
			'no readable text' => ['state' => 'open', 'text' => ' '],
			'kind must be one of: a' => ['state' => 'open', 'text' => 'x', 'kind' => 'b'],
		];
		$mapping = new RecordCaseMapping(dates: $this->caseDates());
		foreach ($cases as $message => $record) {
			try {
				$mapping->map(import: $this->declaration(), record: $record, recordId: 'r', caseTypeId: 't');
				self::fail('Expected a refusal: ' . $message);
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString($message, $e->getMessage());
			}
		}
	}//end testARecordTheDeclarationCannotReadIsRefused()

	/**
	 * A dot path reads a nested value, and null where the path ends early.
	 *
	 * @return void
	 */
	public function testADotPathReadsANestedValue(): void {
		$mapping = new RecordCaseMapping(dates: $this->caseDates());
		$case = ['a' => ['b' => 'c'], 'd' => 'e'];

		self::assertSame('c', $mapping->read(case: $case, path: 'a.b'));
		self::assertNull($mapping->read(case: $case, path: 'a.x'));
		self::assertNull($mapping->read(case: $case, path: 'd.e'));
	}//end testADotPathReadsANestedValue()
}//end class
