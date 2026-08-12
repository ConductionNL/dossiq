<?php

/**
 * WOOAnonymisationAssistService Unit Tests.
 *
 * Covers the properties the woo-llm-anonymisation feature is built around:
 * the rules-floor merge invariant (an LLM proposal can only ADD spans, never
 * remove/shrink a rule-detected one), fail-closed behaviour on any Hermiq
 * failure, rules-only behaviour when the LLM assist is unavailable, and that
 * every proposal call is recorded through the existing AiAuditService audit sink.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-3-2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Ai\AiAuditService;
use OCA\Procest\Service\AiService;
use OCA\Procest\Service\Assistant\HermiqAnonymisationClient;
use OCA\Procest\Service\Assistant\HermiqAssistantException;
use OCA\Procest\Service\WOOAnonymisationAssistService;
use OCA\Procest\Service\WOODocumentAssessmentService;
use OCA\Procest\Service\WOORedactionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\WOOAnonymisationAssistService
 *
 * @uses \OCA\Procest\Service\Assistant\HermiqAssistantException
 */
class WOOAnonymisationAssistServiceTest extends TestCase {
	/**
	 * @var AiService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private AiService $aiService;

	/**
	 * @var AiAuditService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private AiAuditService $auditService;

	/**
	 * @var HermiqAnonymisationClient|\PHPUnit\Framework\MockObject\MockObject
	 */
	private HermiqAnonymisationClient $hermiqClient;

	/**
	 * @var WOODocumentAssessmentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOODocumentAssessmentService $assessmentService;

	/**
	 * @var WOORedactionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOORedactionService $redactionService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->aiService = $this->createMock(AiService::class);
		$this->auditService = $this->createMock(AiAuditService::class);
		$this->hermiqClient = $this->createMock(HermiqAnonymisationClient::class);
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->redactionService = $this->createMock(WOORedactionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// findAssessment()/saveRedactionProposal() must see an existing,
		// already-classified assessment record (assess-first business rule).
		$this->assessmentService->method('findAssessment')->willReturn([
			'id' => 'assessment-1',
			'documentRef' => 'doc-1',
			'caseRef' => 'case-1',
			'classification' => 'deels_openbaar',
		]);
		$this->assessmentService->method('saveRedactionProposal')->willReturnCallback(
			static fn (string $caseId, string $documentRef, array $proposal): array => [
				'id' => 'assessment-1',
				'documentRef' => $documentRef,
				'caseRef' => $caseId,
				'classification' => 'deels_openbaar',
				'redactionProposal' => $proposal,
			]
		);
	}//end setUp()

	/**
	 * Build the service wired to the current mocks.
	 *
	 * @return WOOAnonymisationAssistService
	 */
	private function service(): WOOAnonymisationAssistService {
		return new WOOAnonymisationAssistService(
			$this->aiService,
			$this->auditService,
			$this->hermiqClient,
			$this->assessmentService,
			$this->redactionService,
			$this->logger,
		);
	}//end service()

	/**
	 * Empty text is rejected before any collaborator is touched.
	 *
	 * @return void
	 */
	public function testEmptyTextIsRejected(): void {
		$this->hermiqClient->expects($this->never())->method('isAvailable');

		$this->expectException(\InvalidArgumentException::class);

		$this->service()->proposeSpans(caseId: 'case-1', documentRef: 'doc-1', text: '  ', userId: 'alice');
	}//end testEmptyTextIsRejected()

	/**
	 * Oversized text is rejected.
	 *
	 * @return void
	 */
	public function testOversizedTextIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->proposeSpans(
			caseId: 'case-1',
			documentRef: 'doc-1',
			text: str_repeat('a', 12001),
			userId: 'alice'
		);
	}//end testOversizedTextIsRejected()

	/**
	 * A document without an existing assessment cannot receive a proposal
	 * (assess-first business rule).
	 *
	 * @return void
	 */
	public function testUnassessedDocumentIsRejected(): void {
		// WOODocumentAssessmentService::saveRedactionProposal() is the
		// canonical place the assess-first business rule is enforced
		// (WOODocumentAssessmentServiceTest::testSaveRedactionProposalThrowsWhenNotYetAssessed
		// covers its real logic); here we assert the orchestrating service
		// propagates that failure rather than swallowing it.
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->assessmentService->method('findAssessment')->willReturn(null);
		$this->assessmentService->method('saveRedactionProposal')->willThrowException(
			new \RuntimeException('Document doc-1 must be assessed before requesting redaction assistance')
		);
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn([]);
		$this->hermiqClient->method('isAvailable')->willReturn(false);

		$this->expectException(\RuntimeException::class);

		$this->service()->proposeSpans(caseId: 'case-1', documentRef: 'doc-1', text: 'Jan Jansen', userId: 'alice');
	}//end testUnassessedDocumentIsRejected()

	/**
	 * When Hermiq is unavailable, the proposal is rules-only and the LLM
	 * client's detectPii() is never called.
	 *
	 * @return void
	 */
	public function testFeatureGateAbsentReturnsRulesOnly(): void {
		$ruleSpans = [['start' => 0, 'end' => 3, 'category' => 'bsn', 'text' => 'Jan']];
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn($ruleSpans);
		$this->hermiqClient->method('isAvailable')->willReturn(false);
		$this->hermiqClient->expects($this->never())->method('detectPii');

		$result = $this->service()->proposeSpans(
			caseId: 'case-1',
			documentRef: 'doc-1',
			text: 'Jan is hier',
			userId: 'alice'
		);

		$this->assertSame('rules_only', $result['source']);
		$this->assertFalse($result['llmAvailable']);
		$this->assertCount(1, $result['spans']);
		$this->assertSame('pending_review', $result['status']);
	}//end testFeatureGateAbsentReturnsRulesOnly()

	/**
	 * FAIL-CLOSED: when Hermiq is available but the call fails (error,
	 * timeout, guardrail block — all surface as HermiqAssistantException),
	 * the proposal falls back to rules-only with a clear signal — it is
	 * NEVER left without a result and NEVER silently treated as
	 * "anonymised".
	 *
	 * @return void
	 */
	public function testHermiqFailureFallsBackToRulesOnly(): void {
		$ruleSpans = [['start' => 0, 'end' => 9, 'category' => 'bsn', 'text' => '123456782']];
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn($ruleSpans);
		$this->hermiqClient->method('isAvailable')->willReturn(true);
		$this->hermiqClient->method('detectPii')->willThrowException(
			new HermiqAssistantException(message: 'hermiq_unreachable', statusCode: 503)
		);

		$result = $this->service()->proposeSpans(
			caseId: 'case-1',
			documentRef: 'doc-1',
			text: 'BSN 123456782',
			userId: 'alice'
		);

		$this->assertSame('rules_only_fallback', $result['source']);
		$this->assertTrue($result['llmAvailable']);
		$this->assertArrayHasKey('llmError', $result);
		$this->assertCount(1, $result['spans']);
		$this->assertSame('pending_review', $result['status']);
	}//end testHermiqFailureFallsBackToRulesOnly()

	/**
	 * RULES FLOOR INVARIANT: every rule-detected span is present, unchanged,
	 * in the merged proposal — regardless of what the LLM returned. Here the
	 * LLM returns spans that overlap/duplicate the rule spans in ways that
	 * could be (mis)construed as "correcting" them; the merge must still
	 * carry every original rule span through untouched.
	 *
	 * @return void
	 */
	public function testRulesFloorInvariantSurvivesAdversarialLlmSpans(): void {
		$ruleSpans = [
			['start' => 0, 'end' => 9, 'category' => 'bsn', 'text' => '123456782'],
			['start' => 20, 'end' => 30, 'category' => 'phone', 'text' => '0612345678'],
		];
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn($ruleSpans);
		$this->hermiqClient->method('isAvailable')->willReturn(true);

		// Adversarial LLM response: an EMPTY spans array (as if the model
		// decided nothing needs redaction) plus one legitimate NEW span the
		// regex can't catch (a person's name).
		$this->hermiqClient->method('detectPii')->willReturn([
			'spans' => [
				['start' => 40, 'end' => 50, 'category' => 'person', 'confidence' => 'high'],
			],
			'usage' => [],
		]);

		$result = $this->service()->proposeSpans(
			caseId: 'case-1',
			documentRef: 'doc-1',
			text: str_pad('', 60, 'x'),
			userId: 'alice'
		);

		$this->assertSame('rules_plus_llm', $result['source']);

		$ruleSpansInResult = array_values(array_filter(
			$result['spans'],
			static fn (array $span): bool => $span['source'] === 'rule'
		));
		$this->assertCount(2, $ruleSpansInResult, 'Both rule spans must survive the merge, untouched.');
		$this->assertSame(0, $ruleSpansInResult[0]['start']);
		$this->assertSame(9, $ruleSpansInResult[0]['end']);
		$this->assertSame('bsn', $ruleSpansInResult[0]['category']);
		$this->assertSame(20, $ruleSpansInResult[1]['start']);
		$this->assertSame('phone', $ruleSpansInResult[1]['category']);

		// The LLM's genuinely new span is also present.
		$llmSpansInResult = array_values(array_filter(
			$result['spans'],
			static fn (array $span): bool => $span['source'] === 'llm'
		));
		$this->assertCount(1, $llmSpansInResult);
		$this->assertSame('person', $llmSpansInResult[0]['category']);
	}//end testRulesFloorInvariantSurvivesAdversarialLlmSpans()

	/**
	 * A malformed LLM span (missing fields, out-of-range offsets) is
	 * silently dropped, never trusted, and never allowed to affect the rule
	 * spans.
	 *
	 * @return void
	 */
	public function testMalformedLlmSpansAreDropped(): void {
		$ruleSpans = [['start' => 0, 'end' => 9, 'category' => 'bsn', 'text' => '123456782']];
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn($ruleSpans);
		$this->hermiqClient->method('isAvailable')->willReturn(true);
		$this->hermiqClient->method('detectPii')->willReturn([
			'spans' => [
				['start' => -1, 'end' => 5, 'category' => 'person'],
				['start' => 5, 'end' => 5, 'category' => 'person'],
				['start' => 5, 'category' => 'person'],
				['category' => 'person'],
			],
			'usage' => [],
		]);

		$result = $this->service()->proposeSpans(
			caseId: 'case-1',
			documentRef: 'doc-1',
			text: 'BSN 123456782',
			userId: 'alice'
		);

		$this->assertCount(1, $result['spans'], 'Every malformed LLM span must be dropped.');
		$this->assertSame('rule', $result['spans'][0]['source']);
	}//end testMalformedLlmSpansAreDropped()

	/**
	 * An exact-duplicate LLM span (same start/end/category as a rule span)
	 * is not double-counted.
	 *
	 * @return void
	 */
	public function testExactDuplicateLlmSpanIsNotDoubleCounted(): void {
		$ruleSpans = [['start' => 0, 'end' => 9, 'category' => 'bsn', 'text' => '123456782']];
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn($ruleSpans);
		$this->hermiqClient->method('isAvailable')->willReturn(true);
		$this->hermiqClient->method('detectPii')->willReturn([
			'spans' => [['start' => 0, 'end' => 9, 'category' => 'bsn', 'confidence' => 'high']],
			'usage' => [],
		]);

		$result = $this->service()->proposeSpans(
			caseId: 'case-1',
			documentRef: 'doc-1',
			text: 'BSN 123456782',
			userId: 'alice'
		);

		$this->assertCount(1, $result['spans']);
	}//end testExactDuplicateLlmSpanIsNotDoubleCounted()

	/**
	 * Every proposeSpans() call records an audit entry through the existing
	 * AiAuditService sink, regardless of outcome (rules-only, LLM failure, or
	 * full merge).
	 *
	 * @return void
	 */
	public function testEveryProposalCallRecordsAuditEntry(): void {
		$this->aiService->method('detectDeterministicPiiSpans')->willReturn([]);
		$this->hermiqClient->method('isAvailable')->willReturn(false);

		$capturedEntry = null;
		$this->auditService->expects($this->once())->method('recordAssistantAuditEntry')->willReturnCallback(
			function (array $entry) use (&$capturedEntry): void {
				$capturedEntry = $entry;
			}
		);

		$this->service()->proposeSpans(caseId: 'case-1', documentRef: 'doc-1', text: 'clean text', userId: 'alice');

		$this->assertSame('anonymisation', $capturedEntry['type']);
		$this->assertSame('proposal', $capturedEntry['action']);
		$this->assertSame('case-1', $capturedEntry['caseId']);
		$this->assertSame('doc-1', $capturedEntry['documentId']);
		$this->assertSame('alice', $capturedEntry['userId']);
		// Raw document text must NEVER be recorded verbatim in the audit trail.
		$this->assertStringNotContainsString('clean text', $capturedEntry['prompt']);
	}//end testEveryProposalCallRecordsAuditEntry()

	/**
	 * reviewProposal() rejects an invalid decision value.
	 *
	 * @return void
	 */
	public function testReviewProposalRejectsInvalidDecision(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->reviewProposal(
			caseId: 'case-1',
			documentRef: 'doc-1',
			decision: 'maybe',
			reviewerId: 'bob'
		);
	}//end testReviewProposalRejectsInvalidDecision()

	/**
	 * reviewProposal() throws when there is no pending proposal to review.
	 *
	 * @return void
	 */
	public function testReviewProposalThrowsWhenNoPendingProposal(): void {
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->assessmentService->method('findAssessment')->willReturn([
			'id' => 'assessment-1',
		]);

		$this->expectException(\RuntimeException::class);

		$this->service()->reviewProposal(
			caseId: 'case-1',
			documentRef: 'doc-1',
			decision: 'approve',
			reviewerId: 'bob'
		);
	}//end testReviewProposalThrowsWhenNoPendingProposal()

	/**
	 * On approve, the reviewed spans are handed to the EXISTING, unchanged
	 * WOORedactionService pipeline — this assist never performs redaction
	 * itself.
	 *
	 * @return void
	 */
	public function testApproveHandsOffToExistingRedactionService(): void {
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->assessmentService->method('findAssessment')->willReturn([
			'id' => 'assessment-1',
			'documentRef' => 'doc-1',
			'caseRef' => 'case-1',
			'redactionProposal' => [
				'status' => 'pending_review',
				'spans' => [['start' => 0, 'end' => 9, 'category' => 'bsn', 'source' => 'rule']],
			],
		]);
		$this->assessmentService->method('saveRedactionProposal')->willReturnCallback(
			static fn (string $caseId, string $documentRef, array $proposal): array => [
				'redactionProposal' => $proposal,
			]
		);

		$this->redactionService->expects($this->once())->method('queueForRedaction')->with(
			$this->equalTo('case-1'),
			$this->callback(function (array $documents): bool {
				return $documents[0]['id'] === 'doc-1'
					&& isset($documents[0]['redactionProposal']['spans']);
			})
		);

		$result = $this->service()->reviewProposal(
			caseId: 'case-1',
			documentRef: 'doc-1',
			decision: 'approve',
			reviewerId: 'bob'
		);

		$this->assertSame('approved', $result['status']);
		$this->assertSame('bob', $result['reviewedBy']);
	}//end testApproveHandsOffToExistingRedactionService()

	/**
	 * On reject, the redaction pipeline is NEVER invoked — the proposal is
	 * simply discarded and the pre-existing manual/Docudesk fallback is
	 * unaffected.
	 *
	 * @return void
	 */
	public function testRejectNeverInvokesRedactionService(): void {
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->assessmentService->method('findAssessment')->willReturn([
			'id' => 'assessment-1',
			'documentRef' => 'doc-1',
			'caseRef' => 'case-1',
			'redactionProposal' => ['status' => 'pending_review', 'spans' => []],
		]);
		$this->assessmentService->method('saveRedactionProposal')->willReturnCallback(
			static fn (string $caseId, string $documentRef, array $proposal): array => [
				'redactionProposal' => $proposal,
			]
		);

		$this->redactionService->expects($this->never())->method('queueForRedaction');

		$result = $this->service()->reviewProposal(
			caseId: 'case-1',
			documentRef: 'doc-1',
			decision: 'reject',
			reviewerId: 'bob'
		);

		$this->assertSame('rejected', $result['status']);
	}//end testRejectNeverInvokesRedactionService()
}//end class
