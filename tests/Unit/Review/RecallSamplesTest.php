<?php

/**
 * Unit tests for recall samples.
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
use OCA\Dossiq\Review\RecallSamples;
use OCA\Dossiq\Review\ReviewBatches;
use OCA\Dossiq\Review\ReviewDepth;
use OCA\Dossiq\Review\StoppingRuleMet;
use OCA\Dossiq\Review\StoppingRules;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\RecallEstimator;
use OCA\Dossiq\Woo\WooCaseDocuments;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drawing from the null set with a seed, judging apart from the marking, and estimating once complete.
 *
 * @covers \OCA\Dossiq\Review\RecallSamples
 * @covers \OCA\Dossiq\Review\ReviewDepth
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Review\StoppingRules
 * @uses \OCA\Dossiq\Woo\RecallEstimator
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 */
class RecallSamplesTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The documents on case X.
	 *
	 * @var list<string>
	 */
	private array $documents = [];

	/**
	 * The batch service.
	 *
	 * @var ReviewBatches&MockObject
	 */
	private ReviewBatches&MockObject $batches;

	/**
	 * The met recorder.
	 *
	 * @var StoppingRuleMet&MockObject
	 */
	private StoppingRuleMet&MockObject $met;

	/**
	 * Case X with `found` documents in scope, `outOfScope` set aside and `unmarked` not marked.
	 *
	 * @param int $found In scope.
	 * @param int $outOfScope Out of scope.
	 * @param int $unmarked Unmarked.
	 *
	 * @return RecallSamples The service.
	 */
	private function samples(int $found = 4, int $outOfScope = 3, int $unmarked = 3): RecallSamples {
		$this->store = new InMemoryRegister();
		$this->documents = [];
		$n = 0;
		foreach ([DocumentRelevance::IN_SCOPE => $found, DocumentRelevance::OUT_OF_SCOPE => $outOfScope, DocumentRelevance::UNMARKED => $unmarked] as $relevance => $count) {
			for ($i = 0; $i < $count; $i++) {
				$n++;
				$ref = sprintf('doc-%04d', $n);
				$this->documents[] = $ref;
				$this->store->seed(schema: 'documentReview', uuid: 'r-'.$n, row: ['case' => 'case-x', 'documentRef' => $ref, 'relevance' => $relevance]);
			}
		}

		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview', 'stopping_rule_schema' => 'stoppingRule', 'recall_sample_schema' => 'recallSample'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);

		$caseDocuments = $this->createMock(WooCaseDocuments::class);
		$caseDocuments->method('idsFor')->willReturn($this->documents);
		$this->batches = $this->createMock(ReviewBatches::class);
		$this->batches->method('createSampleBatch')->willReturn(['id' => 'batch-s']);
		$this->met = $this->createMock(StoppingRuleMet::class);

		$reviews = new DocumentRelevance(settingsService: $settings, logger: $logger);

		return new RecallSamples(
			settingsService: $settings,
			reviews: $reviews,
			caseDocuments: $caseDocuments,
			batches: $this->batches,
			depth: new ReviewDepth(),
			rules: new StoppingRules(settingsService: $settings, reviews: $reviews, logger: $logger),
			estimator: new RecallEstimator(),
			met: $this->met,
			logger: $logger,
		);
	}//end samples()

	/**
	 * Only out-of-scope and unmarked documents are drawn; N and found are recorded with the seed and a sample batch.
	 *
	 * @return void
	 */
	public function testTheSampleIsDrawnFromTheNullSetOnly(): void {
		$samples = $this->samples();
		$this->batches->expects($this->once())->method('createSampleBatch')
			->with('case-x', $this->anything(), 'reviewer-a', $this->callback(static fn (array $docs): bool => count($docs) === 6), 'handler-h');

		$sample = $samples->draw(caseId: 'case-x', size: 6, assignee: 'reviewer-a', userId: 'handler-h', seed: 42);

		$inScope = array_slice($this->documents, 0, 4);
		$this->assertSame([], array_intersect($inScope, $sample['documents']));
		$this->assertCount(6, $sample['documents']);
		$this->assertSame(6, $sample['nullSetSize']);
		$this->assertSame(4, $sample['found']);
		$this->assertSame(42, $sample['seed']);
		$this->assertSame('batch-s', $sample['batch']);
	}//end testTheSampleIsDrawnFromTheNullSetOnly()

	/**
	 * The same seed and null set draw the same documents, in any input order.
	 *
	 * @return void
	 */
	public function testTheSameSeedDrawsTheSameDocuments(): void {
		$set = array_map(static fn (int $i): string => 'doc-'.$i, range(1, 50));
		$depth = new ReviewDepth();

		$first = $depth->drawSample(items: $set, size: 10, seed: 7);
		$this->assertSame($first, $depth->drawSample(items: array_reverse($set), size: 10, seed: 7));
		$this->assertCount(10, array_unique($first));
		$this->assertNotSame($first, $depth->drawSample(items: $set, size: 10, seed: 8));
	}//end testTheSameSeedDrawsTheSameDocuments()

	/**
	 * Judging a sampled document in scope leaves its review marking as it was.
	 *
	 * @return void
	 */
	public function testAJudgementDoesNotChangeTheReviewMarking(): void {
		$samples = $this->samples();
		$sample = $samples->draw(caseId: 'case-x', size: 2, assignee: 'reviewer-a', userId: 'handler-h', seed: 3);
		$ref = $sample['documents'][0];
		$before = array_column($this->store->all('documentReview'), 'relevance', 'documentRef')[$ref];

		$judged = $samples->judge(caseId: 'case-x', sampleId: $sample['id'], documentRef: $ref, inScope: true, userId: 'reviewer-a');

		$this->assertSame(DocumentRelevance::IN_SCOPE, $judged['judgements'][$ref]);
		$this->assertSame($before, array_column($this->store->all('documentReview'), 'relevance', 'documentRef')[$ref]);
	}//end testAJudgementDoesNotChangeTheReviewMarking()

	/**
	 * The scenario "No estimate before the sample is judged": progress, no estimate, no met check.
	 *
	 * @return void
	 */
	public function testNoEstimateUntilEveryDocumentIsJudged(): void {
		$samples = $this->samples();
		$this->met->expects($this->never())->method('record');
		$sample = $samples->draw(caseId: 'case-x', size: 4, assignee: 'reviewer-a', userId: 'handler-h', seed: 3);

		foreach (array_slice($sample['documents'], 0, 3) as $ref) {
			$judged = $samples->judge(caseId: 'case-x', sampleId: $sample['id'], documentRef: $ref, inScope: false, userId: 'reviewer-a');
		}

		$this->assertSame(['judged' => 3, 'total' => 4], $judged['progress']);
		$this->assertArrayNotHasKey('recallEstimate', $judged);
		$listed = $samples->forCase(caseId: 'case-x');
		$this->assertCount(1, $listed);
		$this->assertArrayNotHasKey('recallLowerBound', $listed[0]);
	}//end testNoEstimateUntilEveryDocumentIsJudged()

	/**
	 * The scenario "Few relevant documents in the null set": found 400, N 1600, n 200, k 2 gives 0.9615 and 0.8892,
	 * computed at the declared rule's confidence, and the met recorder is asked once with the finished sample.
	 *
	 * @return void
	 */
	public function testTheLastJudgementEstimatesAtTheRulesConfidence(): void {
		$samples = $this->samples(found: 400, outOfScope: 1000, unmarked: 600);
		$this->store->seed(schema: 'stoppingRule', uuid: 'rule-1', row: ['case' => 'case-x', 'targetRecall' => 0.8, 'confidence' => 0.95]);
		$this->met->expects($this->once())->method('record')
			->with('case-x', $this->callback(static fn (?array $rule): bool => ($rule['targetRecall'] ?? null) === 0.8), $this->callback(static fn (array $s): bool => $s['recallLowerBound'] === 0.8892), 'reviewer-a')
			->willReturn('2026-10-10T12:00:00+00:00');

		$sample = $samples->draw(caseId: 'case-x', size: 200, assignee: 'reviewer-a', userId: 'handler-h', seed: 11);
		foreach ($sample['documents'] as $i => $ref) {
			$judged = $samples->judge(caseId: 'case-x', sampleId: $sample['id'], documentRef: $ref, inScope: $i < 2, userId: 'reviewer-a');
		}

		$this->assertSame(1600, $judged['nullSetSize']);
		$this->assertSame(2, $judged['k']);
		$this->assertSame(0.95, $judged['confidence']);
		$this->assertSame(0.01, $judged['elusionRate']);
		$this->assertSame(0.9615, $judged['recallEstimate']);
		$this->assertSame(0.8892, $judged['recallLowerBound']);
		$this->assertSame('2026-10-10T12:00:00+00:00', $judged['stoppingRuleMetAt']);
		$this->assertSame(['judged' => 200, 'total' => 200], $judged['progress']);
	}//end testTheLastJudgementEstimatesAtTheRulesConfidence()

	/**
	 * Refusals: a size larger than the null set, a document not in the sample, a closed sample, an unknown sample.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$samples = $this->samples();
		$refused = [];
		$attempts = [
			'size' => fn () => $samples->draw(caseId: 'case-x', size: 7, assignee: 'reviewer-a', userId: 'handler-h'),
			'assignee' => fn () => $samples->draw(caseId: 'case-x', size: 1, assignee: '', userId: 'handler-h'),
			'sample' => fn () => $samples->judge(caseId: 'case-x', sampleId: 'nope', documentRef: 'doc-0001', inScope: true, userId: 'r'),
		];
		$sample = $samples->draw(caseId: 'case-x', size: 1, assignee: 'reviewer-a', userId: 'handler-h', seed: 5);
		$attempts['document'] = fn () => $samples->judge(caseId: 'case-x', sampleId: $sample['id'], documentRef: 'doc-0001', inScope: true, userId: 'r');
		$samples->judge(caseId: 'case-x', sampleId: $sample['id'], documentRef: $sample['documents'][0], inScope: false, userId: 'r');
		$attempts['closed'] = fn () => $samples->judge(caseId: 'case-x', sampleId: $sample['id'], documentRef: $sample['documents'][0], inScope: true, userId: 'r');

		foreach ($attempts as $name => $attempt) {
			try {
				$attempt();
			} catch (RefusedException $e) {
				$refused[$name] = $e->getRule();
			}
		}

		$this->assertSame(
			[
				'size' => 'review-recall-size',
				'assignee' => 'review-recall-assignee-unknown',
				'sample' => 'review-recall-sample-unknown',
				'document' => 'review-recall-document-unknown',
				'closed' => 'review-recall-sample-closed',
			],
			$refused
		);
	}//end testRefusals()
}//end class
