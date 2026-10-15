<?php

/**
 * Unit tests for the document relevance store.
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

use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reviews of a case are read for the whole case, whoever asks, and only for that case.
 *
 * @covers \OCA\Dossiq\Review\DocumentRelevance
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-rule-of-the-case-reads-the-whole-case-req-cra-011
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Listener\LinkMarkerListener
 * @uses \OCA\Dossiq\Review\PagesSeen
 * @uses \OCA\Dossiq\Review\ReviewDepth
 */
class DocumentRelevanceTest extends TestCase {

	/**
	 * An object service that answers what it is seeded with and records how it was asked.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The store on that object service.
	 *
	 * @var DocumentRelevance
	 */
	private DocumentRelevance $reviews;

	/**
	 * A search that answers rows of two cases, as a loose filter would.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {

			/** @var list<array<string, mixed>> */
			public array $rows = [];

			/** @var list<array{rbac: bool, multitenancy: bool, filters: array<string, mixed>}> */
			public array $searches = [];

			/** @var list<array{uuid: string|null, object: array<string, mixed>}> */
			public array $saved = [];

			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'filters' => $filters];
				if ($_rbac === true) {
					// The caller is a batch reviewer who was granted none of these reviews.
					return [];
				}

				return $this->rows;
			}

			public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null): array {
				$this->saved[] = ['uuid' => $uuid, 'object' => $object];
				return ($object + ['id' => ($uuid ?? 'new-1')]);
			}
		};
		$this->objects->rows = [
			['id' => 'r-1', 'case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'in-scope', 'relevanceSource' => 'rule', 'rule' => 'Nieuwsbrieven'],
			['id' => 'r-2', 'case' => ['id' => 'case-x'], 'documentRef' => 'doc-2', 'relevance' => 'out-of-scope'],
			['id' => 'r-3', 'case' => 'case-y', 'documentRef' => 'doc-3', 'relevance' => 'in-scope'],
			['id' => 'r-4', 'case' => ['uuid' => 'case-y'], 'documentRef' => 'doc-1', 'relevance' => 'out-of-scope'],
			['id' => 'r-5', 'case' => 'case-x', 'documentRef' => '', 'relevance' => 'in-scope'],
		];

		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$this->reviews = new DocumentRelevance(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * The read goes around the caller's own grants, and keeps to the case it was asked for.
	 *
	 * @return void
	 */
	public function testTheWholeCaseIsReadWhoeverAsksAndNoOtherCase(): void {
		$reviews = $this->reviews->forCase(caseId: 'case-x');

		$this->assertSame(['doc-1', 'doc-2'], array_keys($reviews));
		$this->assertSame('r-1', $reviews['doc-1']['id'], 'the review of another case on the same document reference is not read');
		$this->assertSame([false], array_column($this->objects->searches, 'rbac'), 'a rule of the case does not read the caller\'s slice');
		$this->assertSame('case-x', $this->objects->searches[0]['filters']['case']);
	}//end testTheWholeCaseIsReadWhoeverAsksAndNoOtherCase()

	/**
	 * What still holds up the decision is counted over every review, not the caller's.
	 *
	 * @return void
	 */
	public function testOutstandingCountsReviewsTheCallerCannotRead(): void {
		$outstanding = $this->reviews->outstanding(caseId: 'case-x', documentIds: ['doc-1', 'doc-2', 'doc-3'], assessed: []);

		$this->assertSame(['doc-1', 'doc-3'], $outstanding, 'doc-2 is out of scope and needs no verdict; doc-3 has no review in this case');
	}//end testOutstandingCountsReviewsTheCallerCannotRead()

	/**
	 * Marking a document whose review the caller cannot read changes that review and makes no second one.
	 *
	 * @return void
	 */
	public function testMarkingChangesTheReviewTheCallerCannotRead(): void {
		$saved = $this->reviews->mark(caseId: 'case-x', documentRef: 'doc-1', relevance: 'out-of-scope', userId: 'p.bakker');

		$this->assertSame(['r-1'], array_column($this->objects->saved, 'uuid'));
		$this->assertSame('Nieuwsbrieven', $saved['overturnedRule']);
		$this->assertSame('p.bakker', $saved['markedBy']);
	}//end testMarkingChangesTheReviewTheCallerCannotRead()

	/**
	 * A document with no review in this case gets a new one, for this case.
	 *
	 * @return void
	 */
	public function testMarkingADocumentWithoutAReviewCreatesOneForThisCase(): void {
		$this->reviews->mark(caseId: 'case-x', documentRef: 'doc-3', relevance: 'in-scope', userId: 'p.bakker');

		$this->assertNull($this->objects->saved[0]['uuid'], 'the review of case-y on doc-3 is not taken over');
		$this->assertSame('case-x', $this->objects->saved[0]['object']['case']);
	}//end testMarkingADocumentWithoutAReviewCreatesOneForThisCase()

	/**
	 * Without a case or a review schema nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutACaseNothingIsRead(): void {
		$this->assertSame([], $this->reviews->forCase(caseId: ''));
		$this->assertSame([], $this->objects->searches);
	}//end testWithoutACaseNothingIsRead()
}//end class
