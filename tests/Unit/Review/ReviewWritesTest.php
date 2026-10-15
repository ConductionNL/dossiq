<?php

/**
 * Unit tests for a person's writes on a review.
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
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\ReviewWrites;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A write that creates a review shares it with the people on the case; one that changes a review does not.
 *
 * @covers \OCA\Dossiq\Review\ReviewWrites
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-rule-of-the-case-reads-the-whole-case-req-cra-011
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Listener\LinkMarkerListener
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Review\PagesSeen
 * @uses \OCA\Dossiq\Review\ReviewDepth
 */
class ReviewWritesTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * What each reconcile after a write was told: the case and how many records were created.
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $shared = [];

	/**
	 * The writes on the store.
	 *
	 * @var ReviewWrites
	 */
	private ReviewWrites $writes;

	/**
	 * One review on case X, for doc-1, with every page required.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'unmarked', 'pagesRequired' => [1, 2]]);

		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));

		$access = $this->createMock(CaseReviewerAccess::class);
		$access->method('reconcileAfterCreate')->willReturnCallback(
			function (string $caseId, int $created): array {
				$this->shared[] = [$caseId, $created];
				return ['status' => 'reconciled', 'granted' => 0, 'revoked' => 0, 'refused' => 0];
			}
		);

		$this->writes = new ReviewWrites(
			reviews: new DocumentRelevance(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)),
			reviewerAccess: $access,
		);
	}//end setUp()

	/**
	 * A first mark creates the review and shares it; a second mark changes it and shares nothing new.
	 *
	 * @return void
	 */
	public function testAMarkThatCreatesAReviewSharesIt(): void {
		$created = $this->writes->mark(caseId: 'case-x', documentRef: 'doc-2', relevance: 'in-scope', userId: 'p.bakker');
		$this->writes->mark(caseId: 'case-x', documentRef: 'doc-2', relevance: 'out-of-scope', userId: 'p.bakker');

		$this->assertSame('in-scope', $created['relevance']);
		$this->assertSame([['case-x', 1], ['case-x', 0]], $this->shared);
		$this->assertCount(2, $this->store->all('documentReview'));
	}//end testAMarkThatCreatesAReviewSharesIt()

	/**
	 * A review the person cannot read is still a review: the mark changes it and creates nothing.
	 *
	 * @return void
	 */
	public function testAMarkOnAReviewThePersonCannotReadCreatesNothing(): void {
		$this->store->hiddenFromCaller = ['documentReview'];

		$this->writes->mark(caseId: 'case-x', documentRef: 'doc-1', relevance: 'in-scope', userId: 'p.bakker');

		$this->assertSame([['case-x', 0]], $this->shared);
		$this->assertSame('in-scope', $this->store->row(schema: 'documentReview', uuid: 'r-1')['relevance']);
		$this->assertCount(1, $this->store->all('documentReview'));
	}//end testAMarkOnAReviewThePersonCannotReadCreatesNothing()

	/**
	 * Pages seen answer the review with the pages still unseen, and share a review they create.
	 *
	 * @return void
	 */
	public function testPagesSeenAnswerWhatIsUnseenAndShareANewReview(): void {
		$known = $this->writes->pagesSeen(caseId: 'case-x', documentRef: 'doc-1', pages: [1], pageCount: null, userId: 'p.bakker');
		$fresh = $this->writes->pagesSeen(caseId: 'case-x', documentRef: 'doc-3', pages: [1], pageCount: 2, userId: 'p.bakker');

		$this->assertSame([2], $known['unseen']);
		$this->assertSame([1], array_column($known['pagesSeen'], 'page'));
		$this->assertSame('doc-3', $fresh['documentRef']);
		$this->assertSame([['case-x', 0], ['case-x', 1]], $this->shared);
	}//end testPagesSeenAnswerWhatIsUnseenAndShareANewReview()

	/**
	 * A refused write shares nothing: there is no new review to share.
	 *
	 * @return void
	 */
	public function testARefusedWriteSharesNothing(): void {
		try {
			$this->writes->mark(caseId: 'case-x', documentRef: 'doc-2', relevance: 'maybe', userId: 'p.bakker');
			$this->fail('An unknown relevance was stored.');
		} catch (RefusedException $e) {
			$this->assertSame('review-relevance-unknown', $e->getRule());
		}

		try {
			$this->writes->pagesSeen(caseId: 'case-x', documentRef: 'doc-2', pages: [], pageCount: null, userId: 'p.bakker');
			$this->fail('Pages seen without a page were stored.');
		} catch (RefusedException $e) {
			$this->assertSame('review-pages-required', $e->getRule());
		}

		$this->assertSame([], $this->shared);
		$this->assertCount(1, $this->store->all('documentReview'));
	}//end testARefusedWriteSharesNothing()
}//end class
