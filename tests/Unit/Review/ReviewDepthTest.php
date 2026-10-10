<?php

/**
 * Unit tests for Woo review depth.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\ReviewDepth;
use PHPUnit\Framework\TestCase;

/**
 * Depth per document type, and the seeded sample of pages.
 *
 * @covers \OCA\Dossiq\Review\ReviewDepth
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
 */
class ReviewDepthTest extends TestCase {

	/**
	 * The scenario's configuration: exports are sampled at five pages.
	 */
	private const CONFIG = ['export' => ['mode' => 'sample', 'sampleSize' => 5]];

	/**
	 * A 200-page export records `sample` 5, a seed, and five distinct pages within the document.
	 *
	 * @return void
	 */
	public function testASampleDrawsItsSizeWithARecordedSeed(): void {
		$depth = (new ReviewDepth())->depthFor(reviewDepth: self::CONFIG, type: 'export');

		$this->assertSame('sample', $depth['mode']);
		$this->assertSame(5, $depth['sampleSize']);
		$this->assertIsInt($depth['seed']);

		$pages = (new ReviewDepth())->pagesRequired(depth: $depth, pageCount: 200);
		$this->assertCount(5, $pages);
		$this->assertSame($pages, array_values(array_unique($pages)));
		$this->assertSame($pages, (static function (array $p): array {
			sort($p);
			return $p;
		})($pages));
		foreach ($pages as $page) {
			$this->assertGreaterThanOrEqual(1, $page);
			$this->assertLessThanOrEqual(200, $page);
		}
	}//end testASampleDrawsItsSizeWithARecordedSeed()

	/**
	 * The same seed draws the same pages, so the sample can be redrawn and checked later.
	 *
	 * @return void
	 */
	public function testTheSameSeedDrawsTheSamePages(): void {
		$depth = ['mode' => 'sample', 'sampleSize' => 5, 'seed' => 424242];

		$this->assertSame((new ReviewDepth())->pagesRequired(depth: $depth, pageCount: 200), (new ReviewDepth())->pagesRequired(depth: $depth, pageCount: 200));
		$this->assertNotSame(
			(new ReviewDepth())->pagesRequired(depth: $depth, pageCount: 200),
			(new ReviewDepth())->pagesRequired(depth: ['seed' => 7] + $depth, pageCount: 200)
		);
	}//end testTheSameSeedDrawsTheSamePages()

	/**
	 * A type the configuration does not name is every page; a 3-page letter requires pages 1 to 3.
	 *
	 * @return void
	 */
	public function testAnUnnamedTypeIsEveryPage(): void {
		$depth = (new ReviewDepth())->depthFor(reviewDepth: self::CONFIG, type: 'brief');

		$this->assertSame(['mode' => 'every-page'], $depth);
		$this->assertSame([1, 2, 3], (new ReviewDepth())->pagesRequired(depth: $depth, pageCount: 3));
		$this->assertSame(['mode' => 'every-page'], (new ReviewDepth())->depthFor(reviewDepth: [], type: 'export'));
	}//end testAnUnnamedTypeIsEveryPage()

	/**
	 * A sample larger than the document is every page; a document of unknown length requires its first page.
	 *
	 * @return void
	 */
	public function testShortAndUnknownDocuments(): void {
		$depth = ['mode' => 'sample', 'sampleSize' => 5, 'seed' => 1];

		$this->assertSame([1, 2, 3], (new ReviewDepth())->pagesRequired(depth: $depth, pageCount: 3));
		$this->assertSame([1], (new ReviewDepth())->pagesRequired(depth: $depth, pageCount: null));
		$this->assertSame([1], (new ReviewDepth())->pagesRequired(depth: ['mode' => 'every-page'], pageCount: 0));
	}//end testShortAndUnknownDocuments()

	/**
	 * A nonsensical entry falls back to every page rather than to no pages.
	 *
	 * @return void
	 */
	public function testABrokenEntryIsEveryPage(): void {
		$depthService = new ReviewDepth();

		$this->assertSame(['mode' => 'every-page'], $depthService->depthFor(reviewDepth: ['export' => ['mode' => 'sample', 'sampleSize' => 0]], type: 'export'));
		$this->assertSame(['mode' => 'every-page'], $depthService->depthFor(reviewDepth: ['export' => 'sample'], type: 'export'));
	}//end testABrokenEntryIsEveryPage()

	/**
	 * The type of a document: its informatieobjecttype, or the class of its mime type when it has none.
	 *
	 * @return void
	 */
	public function testTheTypeIsTheInformatieobjecttypeOrTheMimeClass(): void {
		$this->assertSame('export', (new ReviewDepth())->typeOf(document: ['informatieobjecttype' => 'export', 'mimeType' => 'text/csv']));
		$this->assertSame('spreadsheet', (new ReviewDepth())->typeOf(document: ['mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
		$this->assertSame('spreadsheet', (new ReviewDepth())->typeOf(document: ['formaat' => 'text/csv']));
		$this->assertSame('pdf', (new ReviewDepth())->typeOf(document: ['mimeType' => 'application/pdf']));
		$this->assertSame('mail', (new ReviewDepth())->typeOf(document: ['mimeType' => 'message/rfc822']));
		$this->assertSame('image', (new ReviewDepth())->typeOf(document: ['mimeType' => 'image/png']));
		$this->assertSame('', (new ReviewDepth())->typeOf(document: []));
	}//end testTheTypeIsTheInformatieobjecttypeOrTheMimeClass()
}//end class
