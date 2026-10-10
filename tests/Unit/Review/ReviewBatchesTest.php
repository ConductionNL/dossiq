<?php

/**
 * Unit tests for Woo review batches.
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
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\ReviewBatches;
use OCA\Dossiq\Review\ReviewConfiguration;
use OCA\Dossiq\Review\ReviewDepth;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Batches through the real review store and case documents, with the engine task seam doubled.
 *
 * @covers \OCA\Dossiq\Review\ReviewBatches
 * @covers \OCA\Dossiq\Review\ReviewDepth
 * @covers \OCA\Dossiq\Review\ReviewConfiguration
 * @covers \OCA\Dossiq\Woo\WooCaseDocuments
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 */
class ReviewBatchesTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The engine task seam.
	 *
	 * @var EngineTaskGateway&MockObject
	 */
	private EngineTaskGateway $tasks;

	/**
	 * The tasks the engine was handed, in order.
	 *
	 * @var list<array{task: array<string, mixed>, caseId: string, actor: ?string}>
	 */
	private array $handed = [];

	/**
	 * Seed 40 in-scope documents on case X, ten of them marked by the rule "Nieuwsbrieven".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		for ($n = 0; $n < 40; $n++) {
			$this->store->seed(schema: 'document', uuid: 'doc-'.$n, row: ['case' => 'case-x']);
			$review = ['case' => 'case-x', 'documentRef' => 'doc-'.$n, 'relevance' => 'in-scope'];
			if ($n >= 30) {
				$review += ['relevanceSource' => 'rule', 'rule' => 'Nieuwsbrieven'];
			}

			$this->store->seed(schema: 'documentReview', uuid: 'review-'.$n, row: $review);
		}

		$this->store->seed(schema: 'document', uuid: 'doc-y', row: ['case' => 'case-y']);

		$this->tasks = $this->createMock(EngineTaskGateway::class);
		$this->tasks->method('mirrorImport')->willReturnCallback(
			function (array $task, string $caseId, ?string $actor): string {
				$this->handed[] = ['task' => $task, 'caseId' => $caseId, 'actor' => $actor];
				return 'engine-task-'.count($this->handed);
			}
		);
	}//end setUp()

	/**
	 * The batches service on the store.
	 *
	 * @param list<string> $users The users that exist.
	 *
	 * @return ReviewBatches The service.
	 */
	private function batches(array $users = ['reviewer-a', 'reviewer-b']): ReviewBatches {
		$config = ['register' => 'dossiq', 'document_schema' => 'document', 'document_review_schema' => 'documentReview', 'review_batch_schema' => 'reviewBatch', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $users, true));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new ReviewBatches(
			settingsService: $settings,
			reviews: new DocumentRelevance(settingsService: $settings, logger: $logger),
			caseDocuments: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger),
			depth: new ReviewDepth(),
			configuration: new ReviewConfiguration(settingsService: $settings),
			tasks: $this->tasks,
			userManager: $userManager,
			l10n: $l10n,
			logger: $logger,
		);
	}//end batches()

	/**
	 * The documents doc-$from up to doc-$to, exclusive.
	 *
	 * @param int $from The first number.
	 * @param int $to The number after the last.
	 *
	 * @return list<string> The refs.
	 */
	private static function refs(int $from, int $to): array {
		return array_map(static fn (int $n): string => 'doc-'.$n, range($from, $to - 1));
	}//end refs()

	/**
	 * The scenario: "Mail 2025" with 25 for A and "Notities" with 15 for B; each reviewer gets a task naming their batch.
	 *
	 * @return void
	 */
	public function testEachBatchGetsATaskForItsReviewer(): void {
		$batches = $this->batches();

		$mail = $batches->create(caseId: 'case-x', name: 'Mail 2025', assignee: 'reviewer-a', documents: self::refs(0, 25), userId: 'handler-h');
		$notes = $batches->create(caseId: 'case-x', name: 'Notities', assignee: 'reviewer-b', documents: self::refs(25, 40), userId: 'handler-h');

		$this->assertCount(2, $this->handed);
		$this->assertSame('reviewer-a', $this->handed[0]['task']['assignee']);
		$this->assertStringContainsString('Mail 2025', $this->handed[0]['task']['title']);
		$this->assertSame('reviewer-b', $this->handed[1]['task']['assignee']);
		$this->assertStringContainsString('Notities', $this->handed[1]['task']['title']);
		$this->assertSame('case-x', $this->handed[0]['caseId']);
		$this->assertSame('handler-h', $this->handed[0]['actor']);
		$this->assertSame('review-batch', $this->handed[0]['task']['metadata']['dossiq']['kind']);

		$this->assertSame('engine-task-1', $mail['batch']['task']);
		$this->assertSame('open', $mail['batch']['status']);
		$this->assertTrue($mail['taskCreated']);
		$this->assertSame(25, $mail['progress']['total']);
		$this->assertSame(0, $mail['progress']['assessed']);
		$this->assertSame(15, $notes['progress']['total']);

		$mailId = $mail['batch']['id'];
		$inMail = array_filter($this->store->all('documentReview'), static fn (array $r): bool => ($r['batch'] ?? '') === $mailId);
		$this->assertCount(25, $inMail);
	}//end testEachBatchGetsATaskForItsReviewer()

	/**
	 * The scenario: a document in "Mail 2025" cannot go into another open batch, and the refusal names the batch.
	 *
	 * @return void
	 */
	public function testADocumentCannotBeInTwoOpenBatches(): void {
		$batches = $this->batches();
		$batches->create(caseId: 'case-x', name: 'Mail 2025', assignee: 'reviewer-a', documents: self::refs(0, 25), userId: 'handler-h');

		$this->assertSame(['doc-3' => 'Mail 2025'], $batches->taken(caseId: 'case-x', documents: ['doc-3', 'doc-30']));

		$writes = $this->store->writes;
		try {
			$batches->create(caseId: 'case-x', name: 'Tweede', assignee: 'reviewer-b', documents: ['doc-3', 'doc-30'], userId: 'handler-h');
			$this->fail('A document already in an open batch was added to another.');
		} catch (RefusedException $e) {
			$this->assertSame('review-batch-document-taken', $e->getRule());
			$this->assertSame(409, $e->getStatus());
		}

		$this->assertSame($writes, $this->store->writes);
		$this->assertCount(1, $this->handed);
	}//end testADocumentCannotBeInTwoOpenBatches()

	/**
	 * A closed batch lets its documents go into a new one.
	 *
	 * @return void
	 */
	public function testAClosedBatchReleasesItsDocuments(): void {
		$batches = $this->batches();
		$first = $batches->create(caseId: 'case-x', name: 'Mail 2025', assignee: 'reviewer-a', documents: ['doc-1'], userId: 'handler-h');
		$this->store->rows['reviewBatch'][$first['batch']['id']]['status'] = 'closed';

		$this->assertSame([], $batches->taken(caseId: 'case-x', documents: ['doc-1']));
		$second = $batches->create(caseId: 'case-x', name: 'Opnieuw', assignee: 'reviewer-b', documents: ['doc-1'], userId: 'handler-h');
		$this->assertSame('open', $second['batch']['status']);
	}//end testAClosedBatchReleasesItsDocuments()

	/**
	 * A filter on the rule takes the documents that rule marked, and records the filter on the batch.
	 *
	 * @return void
	 */
	public function testABatchByFilterTakesTheMatchingDocuments(): void {
		$batches = $this->batches();

		$selected = $batches->select(caseId: 'case-x', documents: [], filter: ['rule' => 'Nieuwsbrieven']);
		$this->assertEqualsCanonicalizing(self::refs(30, 40), $selected);

		$made = $batches->create(caseId: 'case-x', name: 'Nieuwsbrieven nakijken', assignee: 'reviewer-a', documents: $selected, userId: 'handler-h', filter: ['rule' => 'Nieuwsbrieven']);
		$this->assertSame(['rule' => 'Nieuwsbrieven'], $made['batch']['filter']);
		$this->assertCount(10, $made['batch']['documents']);
	}//end testABatchByFilterTakesTheMatchingDocuments()

	/**
	 * A filter on a field the documents do not carry yet is refused rather than answering an empty batch.
	 *
	 * @return void
	 */
	public function testAFilterOnAnUnknownFieldIsRefused(): void {
		try {
			$this->batches()->select(caseId: 'case-x', documents: [], filter: ['custodian' => 'j.devries']);
			$this->fail('A filter on a field no document carries answered a batch.');
		} catch (RefusedException $e) {
			$this->assertSame('review-batch-filter-unknown', $e->getRule());
			$this->assertSame(422, $e->getStatus());
		}
	}//end testAFilterOnAnUnknownFieldIsRefused()

	/**
	 * A document of another case, an empty batch, a missing name and an unknown reviewer are refused before anything is written.
	 *
	 * @return void
	 */
	public function testABatchNeedsANameAReviewerAndDocumentsOfTheCase(): void {
		$batches = $this->batches();
		$cases = [
			['review-batch-document-unknown', 'Mail', 'reviewer-a', ['doc-y']],
			['review-batch-empty', 'Mail', 'reviewer-a', []],
			['review-batch-name-required', '  ', 'reviewer-a', ['doc-1']],
			['review-batch-assignee-unknown', 'Mail', 'nobody', ['doc-1']],
		];
		foreach ($cases as [$rule, $name, $assignee, $documents]) {
			try {
				$batches->create(caseId: 'case-x', name: $name, assignee: $assignee, documents: $documents, userId: 'handler-h');
				$this->fail('Expected '.$rule);
			} catch (RefusedException $e) {
				$this->assertSame($rule, $e->getRule());
				$this->assertSame(422, $e->getStatus());
			}
		}

		$this->assertSame([], $this->store->all('reviewBatch'));
		$this->assertSame([], $this->handed);
	}//end testABatchNeedsANameAReviewerAndDocumentsOfTheCase()

	/**
	 * When the engine does not take the task, the batch still stands and says so.
	 *
	 * @return void
	 */
	public function testABatchWhoseTaskTheEngineRefusedSaysSo(): void {
		$this->tasks = $this->createMock(EngineTaskGateway::class);
		$this->tasks->method('mirrorImport')->willReturn('');

		$made = $this->batches()->create(caseId: 'case-x', name: 'Mail', assignee: 'reviewer-a', documents: ['doc-1'], userId: 'handler-h');

		$this->assertFalse($made['taskCreated']);
		$this->assertSame('', $made['batch']['task'] ?? '');
		$this->assertCount(1, $this->store->all('reviewBatch'));
	}//end testABatchWhoseTaskTheEngineRefusedSaysSo()

	/**
	 * The case's batches with their progress: assessed of total.
	 *
	 * @return void
	 */
	public function testTheBatchesListShowsProgress(): void {
		$batches = $this->batches();
		$batches->create(caseId: 'case-x', name: 'Mail 2025', assignee: 'reviewer-a', documents: self::refs(0, 25), userId: 'handler-h');

		$listed = $batches->forCase(caseId: 'case-x', assessed: ['doc-1' => true, 'doc-30' => true]);

		$this->assertCount(1, $listed);
		$this->assertSame('Mail 2025', $listed[0]['name']);
		$this->assertSame('reviewer-a', $listed[0]['assignee']);
		$this->assertSame(['assessed' => 1, 'total' => 25], $listed[0]['progress']);
	}//end testTheBatchesListShowsProgress()

	/**
	 * The scenario: a sampled 200-page export records sample 5 with its seed, a 3-page letter every page 1 to 3.
	 *
	 * @return void
	 */
	public function testTheBatchRecordsTheDepthPerReview(): void {
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'Woo-verzoek', 'documentReview' => ['reviewDepth' => ['export' => ['mode' => 'sample', 'sampleSize' => 5]]]]);
		$this->store->seed(schema: 'case', uuid: 'case-x', row: ['caseType' => 'ct-1']);
		$this->store->rows['document']['doc-0']['informatieobjecttype'] = 'export';
		$this->store->rows['document']['doc-1']['informatieobjecttype'] = 'brief';
		$this->store->rows['documentReview']['review-0']['pageCount'] = 200;
		$this->store->rows['documentReview']['review-1']['pageCount'] = 3;

		$this->batches()->create(caseId: 'case-x', name: 'Exports', assignee: 'reviewer-a', documents: ['doc-0', 'doc-1', 'doc-2'], userId: 'handler-h');

		$export = $this->store->row('documentReview', 'review-0');
		$this->assertSame('sample', $export['depth']['mode']);
		$this->assertSame(5, $export['depth']['sampleSize']);
		$this->assertIsInt($export['depth']['seed']);
		$this->assertCount(5, $export['pagesRequired']);
		$this->assertSame($export['pagesRequired'], (new ReviewDepth())->pagesRequired(depth: $export['depth'], pageCount: 200));

		$letter = $this->store->row('documentReview', 'review-1');
		$this->assertSame(['mode' => 'every-page'], $letter['depth']);
		$this->assertSame([1, 2, 3], $letter['pagesRequired']);

		$this->assertSame([1], $this->store->row('documentReview', 'review-2')['pagesRequired']);
	}//end testTheBatchRecordsTheDepthPerReview()
}//end class
