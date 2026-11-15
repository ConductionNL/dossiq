<?php

/**
 * DocumentSetNumberer edge-case unit tests.
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

use OCA\Dossiq\Service\DocumentSeries\DocumentSetNumberer;
use OCA\Dossiq\Service\DocumentSeries\SeriesDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of numbering a set: which stored numbers `keep` honours, the
 * secondary sort key, descending numeric order, keyed input, the prefix-less
 * format and the filename's extension handling.
 *
 * @covers \OCA\Dossiq\Service\DocumentSeries\DocumentSetNumberer
 *
 * @uses \OCA\Dossiq\Service\DocumentSeries\SeriesDefinition
 */
class DocumentSetNumbererEdgeCasesTest extends TestCase {

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
	 * The document ids of a numbered set, in number order.
	 *
	 * @param array<int, array{sequence: int, number: string, document: array<string, mixed>}> $entries The entries.
	 *
	 * @return array<int, string>
	 */
	private function ids(array $entries): array {
		return array_map(static fn (array $entry): string => $entry['document']['id'], $entries);
	}//end ids()

	/**
	 * Under `keep`, only a positive whole number is honoured; anything else is numbered fresh.
	 *
	 * @return void
	 */
	public function testKeepHonoursOnlyPositiveWholeNumbers(): void {
		$definition = new SeriesDefinition(series: 'exhibit', gapPolicy: SeriesDefinition::GAP_KEEP);

		$entries = $this->numberer->number(
			documents: [
				['id' => 'zero', 'sequence' => 0],
				['id' => 'negative', 'sequence' => -2],
				['id' => 'fraction', 'sequence' => '2.5'],
				['id' => 'padded', 'sequence' => '05'],
				['id' => 'text', 'sequence' => 'four'],
				['id' => 'kept', 'sequence' => '3'],
			],
			definition: $definition,
		);

		self::assertSame(
			['kept' => 3, 'zero' => 4, 'negative' => 5, 'fraction' => 6, 'padded' => 7, 'text' => 8],
			array_combine($this->ids($entries), array_column($entries, 'sequence'))
		);
	}//end testKeepHonoursOnlyPositiveWholeNumbers()

	/**
	 * Under `keep` with nothing stored, numbering starts at 1 in the configured order.
	 *
	 * @return void
	 */
	public function testKeepWithNothingStoredStartsAtOne(): void {
		$definition = new SeriesDefinition(
			series: 'exhibit',
			prefix: 'X',
			order: [['field' => 'title', 'direction' => 'asc']],
			width: 3,
			gapPolicy: SeriesDefinition::GAP_KEEP,
		);

		$entries = $this->numberer->number(
			documents: [['id' => 'b', 'title' => 'Bravo'], ['id' => 'a', 'title' => 'Alpha']],
			definition: $definition,
		);

		self::assertSame(['a', 'b'], $this->ids($entries));
		self::assertSame(['X-001', 'X-002'], array_column($entries, 'number'));
	}//end testKeepWithNothingStoredStartsAtOne()

	/**
	 * A tie on the first key is broken by the second key before input position.
	 *
	 * @return void
	 */
	public function testTheSecondKeyBreaksATieOnTheFirst(): void {
		$definition = new SeriesDefinition(
			series: 'exhibit',
			order: [['field' => 'filedOn', 'direction' => 'asc'], ['field' => 'title', 'direction' => 'desc']],
		);

		$entries = $this->numberer->number(
			documents: [
				['id' => 'alpha', 'filedOn' => '2026-01-01', 'title' => 'Alpha'],
				['id' => 'later', 'filedOn' => '2026-02-01', 'title' => 'Zulu'],
				['id' => 'mike', 'filedOn' => '2026-01-01', 'title' => 'Mike'],
			],
			definition: $definition,
		);

		self::assertSame(['mike', 'alpha', 'later'], $this->ids($entries));
	}//end testTheSecondKeyBreaksATieOnTheFirst()

	/**
	 * Descending numeric order compares as numbers, and an empty string sorts last like a missing value.
	 *
	 * @return void
	 */
	public function testDescendingNumbersAndEmptyValuesSortLast(): void {
		$definition = new SeriesDefinition(series: 'exhibit', order: [['field' => 'page', 'direction' => 'desc']]);

		$entries = $this->numberer->number(
			documents: [
				['id' => 'empty', 'page' => ''],
				['id' => 'nine', 'page' => '9'],
				['id' => 'missing'],
				['id' => 'eleven', 'page' => 11],
			],
			definition: $definition,
		);

		self::assertSame(['eleven', 'nine', 'empty', 'missing'], $this->ids($entries));
	}//end testDescendingNumbersAndEmptyValuesSortLast()

	/**
	 * Keyed input is numbered in its own order and a prefix-less series prints the bare padded number.
	 *
	 * @return void
	 */
	public function testKeyedInputWithoutPrefixPrintsBareNumbers(): void {
		$entries = $this->numberer->number(
			documents: ['k-first' => ['id' => 'first'], 'k-second' => ['id' => 'second']],
			definition: new SeriesDefinition(series: 'exhibit', width: 4),
		);

		self::assertSame(
			[
				['sequence' => 1, 'number' => '0001', 'document' => ['id' => 'first']],
				['sequence' => 2, 'number' => '0002', 'document' => ['id' => 'second']],
			],
			$entries
		);
	}//end testKeyedInputWithoutPrefixPrintsBareNumbers()

	/**
	 * A source without an extension takes the caller's default extension.
	 *
	 * @return void
	 */
	public function testAFilenameWithoutASourceExtensionTakesTheDefault(): void {
		self::assertSame(
			'3-annual-report-2026.odt',
			$this->numberer->filename(sequence: 3, width: 1, title: '  Annual Report (2026) ', source: 'https://x.test/files/report', defaultExtension: 'odt')
		);
	}//end testAFilenameWithoutASourceExtensionTakesTheDefault()

	/**
	 * A plain path keeps its extension, stripped of anything but letters and digits.
	 *
	 * @return void
	 */
	public function testAFilenameKeepsAPlainPathsCleanedExtension(): void {
		self::assertSame('12-x.tar7', $this->numberer->filename(sequence: 12, width: 2, title: 'X', source: '/data/archive.TAR_7'));
		self::assertSame('01-document.pdf', $this->numberer->filename(sequence: 1, width: 2, title: '!!!', source: '/data/notes.__'));
	}//end testAFilenameKeepsAPlainPathsCleanedExtension()
}//end class
