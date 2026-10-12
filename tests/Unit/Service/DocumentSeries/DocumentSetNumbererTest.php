<?php

/**
 * DocumentSetNumberer and SeriesDefinition unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DocumentSeries
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
 *
 * @spec openspec/specs/numbered-document-series/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DocumentSeries;

use InvalidArgumentException;
use OCA\Dossiq\Service\DocumentSeries\DocumentSetNumberer;
use OCA\Dossiq\Service\DocumentSeries\SeriesDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for numbering a set of documents, on a neutral fixture case type.
 *
 * The fixture is a case type called `fixture-case` with one series, `exhibit`:
 * it stands for no procedure, so nothing here tests a procedure's data.
 *
 * @covers \OCA\Dossiq\Service\DocumentSeries\DocumentSetNumberer
 * @covers \OCA\Dossiq\Service\DocumentSeries\SeriesDefinition
 */
class DocumentSetNumbererTest extends TestCase {

	/**
	 * The neutral fixture case type.
	 *
	 * @var array<string, mixed>
	 */
	private const FIXTURE_CASE_TYPE = [
		'id' => 'fixture-case',
		'documentSeries' => [
			'exhibit' => [
				'prefix' => 'X',
				'order' => [['field' => 'filedOn', 'direction' => 'asc'], 'title'],
				'width' => 3,
			],
		],
	];

	/**
	 * The service under test.
	 *
	 * @var DocumentSetNumberer
	 */
	private DocumentSetNumberer $numberer;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->numberer = new DocumentSetNumberer();
	}//end setUp()

	/**
	 * The set is numbered in the configured order with the configured prefix and width.
	 *
	 * @return void
	 */
	public function testNumbersInConfiguredOrder(): void {
		$definition = SeriesDefinition::fromCaseType(caseType: self::FIXTURE_CASE_TYPE, series: 'exhibit');

		$entries = $this->numberer->number(
			documents: [
				['id' => 'c', 'filedOn' => '2026-03-01', 'title' => 'Gamma'],
				['id' => 'a', 'filedOn' => '2026-01-01', 'title' => 'Alpha'],
				['id' => 'b', 'filedOn' => '2026-01-01', 'title' => 'Beta'],
			],
			definition: $definition,
		);

		self::assertSame(['a', 'b', 'c'], array_map(static fn (array $e): string => $e['document']['id'], $entries));
		self::assertSame(['X-001', 'X-002', 'X-003'], array_column($entries, 'number'));
		self::assertSame([1, 2, 3], array_column($entries, 'sequence'));
	}//end testNumbersInConfiguredOrder()

	/**
	 * Equal documents keep their input order, and a missing value sorts last.
	 *
	 * @return void
	 */
	public function testTiesAreStableAndMissingSortsLast(): void {
		$definition = new SeriesDefinition(series: 'exhibit', order: [['field' => 'filedOn', 'direction' => 'desc']]);

		$entries = $this->numberer->number(
			documents: [
				['id' => 'undated'],
				['id' => 'first', 'filedOn' => '2026-02-01'],
				['id' => 'second', 'filedOn' => '2026-02-01'],
				['id' => 'newest', 'filedOn' => '2026-05-01'],
			],
			definition: $definition,
		);

		self::assertSame(
			['newest', 'first', 'second', 'undated'],
			array_map(static fn (array $e): string => $e['document']['id'], $entries)
		);
		self::assertSame(['01', '02', '03', '04'], array_column($entries, 'number'));
	}//end testTiesAreStableAndMissingSortsLast()

	/**
	 * Numeric order values compare as numbers, not as text.
	 *
	 * @return void
	 */
	public function testNumericValuesCompareAsNumbers(): void {
		$definition = new SeriesDefinition(series: 'exhibit', order: [['field' => 'page', 'direction' => 'asc']]);

		$entries = $this->numberer->number(
			documents: [['id' => 'ten', 'page' => 10], ['id' => 'two', 'page' => '2']],
			definition: $definition,
		);

		self::assertSame(['two', 'ten'], array_map(static fn (array $e): string => $e['document']['id'], $entries));
	}//end testNumericValuesCompareAsNumbers()

	/**
	 * Under `renumber`, a removed document leaves no gap.
	 *
	 * @return void
	 */
	public function testRenumberLeavesNoGap(): void {
		$definition = new SeriesDefinition(series: 'exhibit', gapPolicy: SeriesDefinition::GAP_RENUMBER);

		$entries = $this->numberer->number(
			documents: [['id' => 'a', 'sequence' => 1], ['id' => 'c', 'sequence' => 3]],
			definition: $definition,
		);

		self::assertSame([1, 2], array_column($entries, 'sequence'));
	}//end testRenumberLeavesNoGap()

	/**
	 * Under `keep`, a cited number stays with its document and a new one comes after the highest.
	 *
	 * @return void
	 */
	public function testKeepHoldsNumbersAndAppends(): void {
		$definition = new SeriesDefinition(
			series: 'exhibit',
			order: [['field' => 'title', 'direction' => 'asc']],
			gapPolicy: SeriesDefinition::GAP_KEEP,
		);

		$entries = $this->numberer->number(
			documents: [
				['id' => 'z', 'title' => 'Zulu', 'sequence' => 1],
				['id' => 'new-b', 'title' => 'Bravo'],
				['id' => 'm', 'title' => 'Mike', 'sequence' => 3],
				['id' => 'new-a', 'title' => 'Alpha'],
			],
			definition: $definition,
		);

		self::assertSame(
			['z' => 1, 'm' => 3, 'new-a' => 4, 'new-b' => 5],
			array_combine(
				array_map(static fn (array $e): string => $e['document']['id'], $entries),
				array_column($entries, 'sequence')
			)
		);
	}//end testKeepHoldsNumbersAndAppends()

	/**
	 * Under `keep`, a number held twice is kept by the first document only.
	 *
	 * @return void
	 */
	public function testKeepNeverPrintsANumberTwice(): void {
		$definition = new SeriesDefinition(series: 'exhibit', gapPolicy: SeriesDefinition::GAP_KEEP, numberField: 'nr');

		$entries = $this->numberer->number(
			documents: [['id' => 'a', 'nr' => 2], ['id' => 'b', 'nr' => 2]],
			definition: $definition,
		);

		self::assertSame([2, 3], array_column($entries, 'sequence'));
		self::assertSame(['a', 'b'], array_map(static fn (array $e): string => $e['document']['id'], $entries));
	}//end testKeepNeverPrintsANumberTwice()

	/**
	 * An empty set numbers to an empty list.
	 *
	 * @return void
	 */
	public function testEmptySet(): void {
		self::assertSame([], $this->numberer->number(documents: [], definition: new SeriesDefinition(series: 'exhibit')));
	}//end testEmptySet()

	/**
	 * The filename sorts like the number and keeps the source's extension.
	 *
	 * @return void
	 */
	public function testFilename(): void {
		self::assertSame('01-first-letter.docx', $this->numberer->filename(sequence: 1, width: 2, title: 'First letter!', source: 'https://x.test/a/b.DOCX?x=1'));
		self::assertSame('007-document.pdf', $this->numberer->filename(sequence: 7, width: 3, title: '', source: ''));
	}//end testFilename()

	/**
	 * A case type that does not configure the series gets the caller's defaults.
	 *
	 * @return void
	 */
	public function testUnconfiguredSeriesUsesDefaults(): void {
		$definition = SeriesDefinition::fromCaseType(caseType: self::FIXTURE_CASE_TYPE, series: 'other', defaultPrefix: 'O');

		self::assertSame('O', $definition->prefix);
		self::assertSame([], $definition->order);
		self::assertSame(SeriesDefinition::DEFAULT_WIDTH, $definition->width);
		self::assertSame(SeriesDefinition::GAP_RENUMBER, $definition->gapPolicy);
	}//end testUnconfiguredSeriesUsesDefaults()

	/**
	 * A gap policy the schema does not allow is refused, not silently replaced.
	 *
	 * @return void
	 */
	public function testUnknownGapPolicyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		SeriesDefinition::fromArray(series: 'exhibit', config: ['gapPolicy' => 'shuffle']);
	}//end testUnknownGapPolicyIsRefused()

	/**
	 * An order direction the schema does not allow is refused.
	 *
	 * @return void
	 */
	public function testUnknownDirectionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		SeriesDefinition::fromArray(series: 'exhibit', config: ['order' => [['field' => 'title', 'direction' => 'sideways']]]);
	}//end testUnknownDirectionIsRefused()

	/**
	 * A width outside 1 to 9 is refused.
	 *
	 * @return void
	 */
	public function testWidthOutOfRangeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new SeriesDefinition(series: 'exhibit', width: 0);
	}//end testWidthOutOfRangeIsRefused()
}//end class
