<?php

/**
 * Unit tests for the Woo pages seen.
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\PagesSeen;
use OCA\Dossiq\Review\ReviewDepth;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recording displayed pages, and the unseen required pages a verdict, decision or publication waits on.
 *
 * @covers \OCA\Dossiq\Review\PagesSeen
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 */
class PagesSeenTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Three reviews on case X: in scope with page 7 unseen, in scope all seen, out of scope unseen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'in-scope', 'pagesRequired' => [6, 7], 'pagesSeen' => [['page' => 6, 'by' => 'a']]]);
		$this->store->seed(schema: 'documentReview', uuid: 'r-2', row: ['case' => 'case-x', 'documentRef' => 'doc-2', 'relevance' => 'in-scope', 'pagesRequired' => [1], 'pagesSeen' => [['page' => 1, 'by' => 'b']]]);
		$this->store->seed(schema: 'documentReview', uuid: 'r-3', row: ['case' => 'case-x', 'documentRef' => 'doc-3', 'relevance' => 'out-of-scope', 'pagesRequired' => [1, 2]]);
	}//end setUp()

	/**
	 * The service on the store, or without a review schema.
	 *
	 * @param bool $configured Whether the review schema is configured.
	 *
	 * @return PagesSeen The service.
	 */
	private function pagesSeen(bool $configured = true): PagesSeen {
		$config = ['register' => 'dossiq'];
		if ($configured === true) {
			$config['document_review_schema'] = 'documentReview';
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));

		return new PagesSeen(reviews: new DocumentRelevance(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)), depth: new ReviewDepth());
	}//end pagesSeen()

	/**
	 * Only in-scope documents with unseen required pages are named, with those pages.
	 *
	 * @return void
	 */
	public function testTheCaseNamesTheInScopeDocumentsWithUnseenPages(): void {
		$pagesSeen = $this->pagesSeen();

		$this->assertSame(['doc-1' => [7]], $pagesSeen->unseenInCase(caseId: 'case-x'));
		$this->assertSame([7], $pagesSeen->unseenFor(caseId: 'case-x', documentRef: 'doc-1'));
		$this->assertSame([], $pagesSeen->unseenFor(caseId: 'case-x', documentRef: 'doc-3'));
		$this->assertSame([], $pagesSeen->unseenFor(caseId: 'case-x', documentRef: 'doc-unknown'));
		$this->assertSame([], $this->pagesSeen(configured: false)->unseenInCase(caseId: 'case-x'));
	}//end testTheCaseNamesTheInScopeDocumentsWithUnseenPages()

	/**
	 * A verdict on doc-1 is refused naming page 7; a verdict on doc-2 and an assessment without a verdict pass.
	 *
	 * @return void
	 */
	public function testAVerdictIsRefusedNamingTheUnseenPages(): void {
		$pagesSeen = $this->pagesSeen();

		$this->assertSame(['pagesSeen' => 'Open these pages before giving a verdict: 7.'], $pagesSeen->refusalFor(caseId: 'case-x', assessment: ['documentRef' => 'doc-1', 'classification' => 'openbaar']));
		$this->assertSame([], $pagesSeen->refusalFor(caseId: 'case-x', assessment: ['documentRef' => 'doc-2', 'classification' => 'openbaar']));
		$this->assertSame([], $pagesSeen->refusalFor(caseId: 'case-x', assessment: ['documentRef' => 'doc-1']));
	}//end testAVerdictIsRefusedNamingTheUnseenPages()

	/**
	 * Recording appends each new page once per reviewer, sets the page count once, and refuses an empty report.
	 *
	 * @return void
	 */
	public function testRecordingAppendsOncePerReviewerAndPage(): void {
		$pagesSeen = $this->pagesSeen();

		$saved = $pagesSeen->record(caseId: 'case-x', documentRef: 'doc-1', pages: [6, 7, 7, 0], pageCount: 9, userId: 'a');

		$this->assertSame([6, 7], array_column($saved['pagesSeen'], 'page'));
		$this->assertSame(9, $saved['pageCount']);
		$this->assertSame(range(1, 9), $saved['pagesRequired']);
		$this->assertSame([1, 2, 3, 4, 5, 8, 9], $pagesSeen->unseenPages(review: $saved));

		$again = $pagesSeen->record(caseId: 'case-x', documentRef: 'doc-1', pages: [7], pageCount: 4, userId: 'b');
		$this->assertSame(9, $again['pageCount']);
		$this->assertSame([6, 7, 7], array_column($again['pagesSeen'], 'page'));

		$this->expectException(RefusedException::class);
		$pagesSeen->record(caseId: 'case-x', documentRef: 'doc-1', pages: [0], pageCount: null, userId: 'a');
	}//end testRecordingAppendsOncePerReviewerAndPage()

	/**
	 * The decision guard refuses with 409 while page 7 is unseen, and passes once it is seen.
	 *
	 * @return void
	 */
	public function testTheDecisionWaitsForTheLastPage(): void {
		$pagesSeen = $this->pagesSeen();
		try {
			$pagesSeen->assertAllSeen(caseId: 'case-x');
			$this->fail('The decision did not wait for page 7.');
		} catch (RefusedException $e) {
			$this->assertSame('review-pages-unseen', $e->getRule());
			$this->assertSame(409, $e->getStatus());
		}

		$pagesSeen->record(caseId: 'case-x', documentRef: 'doc-1', pages: [7], pageCount: null, userId: 'a');
		$pagesSeen->assertAllSeen(caseId: 'case-x');
		$this->assertSame([], $pagesSeen->unseenInCase(caseId: 'case-x'));
	}//end testTheDecisionWaitsForTheLastPage()
}//end class
