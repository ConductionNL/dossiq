<?php

/**
 * Case Draft Data Test
 *
 * The render data of a drafted document, built from a case type's
 * declaration: fields by dot path with value maps, related records as
 * numbered rows, and a summary over one list field.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Drafts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-data-is-built-from-the-declaration-req-cdd-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Drafts;

use InvalidArgumentException;
use OCA\Dossiq\Service\Drafts\CaseDraftData;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Drafts\CaseDraftData
 */
class CaseDraftDataTest extends TestCase {

	/**
	 * A declaration with a field, a list and a summary.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		return [
			'fields' => [
				['to' => 'reference', 'from' => 'identifier', 'required' => true],
				['to' => 'applicant.name', 'from' => 'request.name', 'default' => 'unknown'],
				['to' => 'kind', 'value' => 'letter'],
			],
			'lists' => [
				[
					'to' => 'items',
					'schemaKey' => 'item_schema',
					'caseField' => 'case',
					'sortBy' => 'ref',
					'numberAs' => 'number',
					'fields' => [
						['to' => 'ref', 'from' => 'ref'],
						['to' => 'verdict', 'from' => 'state', 'values' => ['yes' => 'granted', 'no' => 'refused']],
						['to' => 'codes', 'from' => 'codes', 'default' => []],
					],
				],
			],
			'summaries' => [
				['to' => 'outcome', 'over' => 'items', 'field' => 'verdict', 'all' => ['granted' => 'granted', 'refused' => 'refused'], 'empty' => 'nothing', 'mixed' => 'partly'],
			],
		];
	}//end declaration()

	/**
	 * A related-record reader over fixed rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return callable
	 */
	private function related(array $rows): callable {
		return static function (string $schemaKey, string $caseField) use ($rows): array {
			self::assertSame(['item_schema', 'case'], [$schemaKey, $caseField]);
			return $rows;
		};
	}//end related()

	public function testFieldsListsAndSummariesAreBuilt(): void {
		$data = (new CaseDraftData())->build(
			declaration: $this->declaration(),
			case: ['identifier' => 'Z-1', 'request' => []],
			related: $this->related(rows: [['ref' => 'b', 'state' => 'no', 'codes' => ['5.1.2e']], ['ref' => 'a', 'state' => 'yes']])
		);

		self::assertSame('Z-1', $data['reference']);
		self::assertSame(['name' => 'unknown'], $data['applicant'], 'an empty field takes its default');
		self::assertSame('letter', $data['kind']);
		self::assertSame(
			[
				['ref' => 'a', 'verdict' => 'granted', 'codes' => [], 'number' => 1],
				['ref' => 'b', 'verdict' => 'refused', 'codes' => ['5.1.2e'], 'number' => 2],
			],
			$data['items'],
			'rows are sorted and numbered, so the numbers are stable'
		);
		self::assertSame('partly', $data['outcome']);
	}//end testFieldsListsAndSummariesAreBuilt()

	public function testASummaryAnswersTheSharedValueOrEmpty(): void {
		$builder = new CaseDraftData();
		$case = ['identifier' => 'Z-1'];

		self::assertSame('granted', $builder->build(declaration: $this->declaration(), case: $case, related: $this->related(rows: [['ref' => 'a', 'state' => 'yes'], ['ref' => 'b', 'state' => 'yes']]))['outcome']);
		self::assertSame('nothing', $builder->build(declaration: $this->declaration(), case: $case, related: $this->related(rows: []))['outcome']);
	}//end testASummaryAnswersTheSharedValueOrEmpty()

	public function testAnUnlistedValueIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('draft-value-unlisted:verdict=maybe');

		(new CaseDraftData())->build(declaration: $this->declaration(), case: ['identifier' => 'Z-1'], related: $this->related(rows: [['ref' => 'a', 'state' => 'maybe']]));
	}//end testAnUnlistedValueIsRefused()

	public function testAnEmptyRequiredFieldIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('draft-field-empty:reference');

		(new CaseDraftData())->build(declaration: $this->declaration(), case: [], related: $this->related(rows: []));
	}//end testAnEmptyRequiredFieldIsRefused()

	public function testReadFollowsADotPath(): void {
		$builder = new CaseDraftData();

		self::assertSame('x', $builder->read(row: ['a' => ['b' => 'x']], path: 'a.b'));
		self::assertNull($builder->read(row: ['a' => 'flat'], path: 'a.b'));
	}//end testReadFollowsADotPath()
	public function testTodayIsTheDayTheDraftIsMade(): void {
		$data = (new CaseDraftData())->build(declaration: ['fields' => [['to' => 'date', 'value' => CaseDraftData::TODAY]]], case: [], related: static fn (): array => []);

		self::assertSame(gmdate('Y-m-d'), $data['date']);
	}//end testTodayIsTheDayTheDraftIsMade()
}//end class
