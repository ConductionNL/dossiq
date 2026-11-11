<?php

/**
 * Dossiq WOO Anonymisation Assist Service
 *
 * LLM-ASSISTED redaction-span proposal for WOO document disclosure review.
 * This service is an ASSIST: it never marks anything "anonymised" itself
 * and never publishes anything. The redacted copy is written by the
 * redaction panel ({@see \OCA\Dossiq\Service\Redaction\DocumentRedactionService}),
 * which takes the same merged spans as entities. It:
 *
 *   1. Runs `AiService::detectDeterministicPiiSpans()` — the deterministic
 *      regex "rules floor" — over the caller-supplied document text.
 *   2. When Hermiq is available, ALSO asks Hermiq's structured PII-detection
 *      surface (`HermiqAnonymisationClient`, woo-llm-anonymisation) for
 *      additional proposed spans (names, addresses, medical/financial
 *      mentions — categories the regex floor cannot catch).
 *   3. Merges the two by UNION: every rule-detected span is ALWAYS present
 *      in the merged result, unchanged, regardless of what the LLM
 *      returned or whether it failed — the LLM can only ADD spans, never
 *      remove or shrink a rule-detected one (`mergeSpansRulesFloor()`).
 *   4. Persists the merged proposal on the document's existing wooAssessment
 *      record with `status: 'pending_review'` — a human MUST review and
 *      explicitly approve/reject it (`reviewProposal()`). Nothing here is
 *      auto-applied or auto-published (EU AI Act Art.14 human-in-the-loop
 *      posture dossiq already takes for every other AI suggestion).
 *
 * FLEET RULE: AI functionality lives in Hermiq. This class contains NO
 * prompt-building, model selection, or LLM-calling logic — that lives
 * entirely behind `HermiqAnonymisationClient`/Hermiq's `detectPii()`
 * endpoint. This class is context assembly, the rules-floor merge
 * invariant, human-review gating, and audit plumbing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Ai\AiAuditService;
use OCA\Dossiq\Service\Assistant\HermiqAnonymisationClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service orchestrating LLM-assisted, human-reviewed redaction proposals.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
 */
class WOOAnonymisationAssistService {
	/**
	 * Maximum accepted document text length (characters) — bounded to keep
	 * latency/cost predictable and mirrors the cap Hermiq's endpoint itself
	 * enforces (`AssistantService::MAX_DETECT_TEXT_LENGTH`).
	 *
	 * @var int
	 */
	private const MAX_TEXT_LENGTH = 12000;

	/**
	 * Valid `reviewProposal()` decisions.
	 *
	 * @var string[]
	 */
	private const VALID_DECISIONS = ['approve', 'reject'];

	/**
	 * Constructor.
	 *
	 * @param AiService $aiService Deterministic PII rules floor.
	 * @param AiAuditService $auditService AI oversight audit sink.
	 * @param HermiqAnonymisationClient $hermiqClient Thin HTTP client to Hermiq's
	 *                                                detect-pii surface.
	 * @param WOODocumentAssessmentService $assessmentService Reads/writes the wooAssessment
	 *                                                        record a proposal attaches to.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly AiService $aiService,
		private readonly AiAuditService $auditService,
		private readonly HermiqAnonymisationClient $hermiqClient,
		private readonly WOODocumentAssessmentService $assessmentService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the LLM-assist component is currently available. When false,
	 * `proposeSpans()` still runs (rules-only) — this is purely informational
	 * for the UI to explain why no LLM spans were proposed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
	 */
	public function isLlmAssistAvailable(): bool {
		return $this->hermiqClient->isAvailable();
	}//end isLlmAssistAvailable()

	/**
	 * Propose redaction spans for a document, merging the deterministic
	 * rules floor with an optional LLM-assisted layer, and persist the
	 * result as a `pending_review` proposal on the document's assessment.
	 *
	 * FAIL-CLOSED: an LLM error/timeout/guardrail-block never blocks this
	 * call — it degrades to a rules-only proposal with a clear
	 * `llmAvailable`/`llmError` signal, and the assessment is NEVER marked
	 * anything but `pending_review` (never "anonymised", never published).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 * @param string $text The document text to scan (caller is responsible for having
	 *                     authorization to read the underlying document — this method
	 *                     does no file access of its own).
	 * @param string $userId The requesting user id (audit + `proposedBy`).
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable, llmError?, status}`.
	 *
	 * @throws InvalidArgumentException (400) When `text` is empty or over the length cap.
	 * @throws RuntimeException When the document has not yet been assessed (assess-first rule).
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
	 */
	public function proposeSpans(string $caseId, string $documentRef, string $text, string $userId): array {
		$this->validateText(text: $text);

		$request = new SpanProposalRequest();
		$request->caseId = $caseId;
		$request->documentRef = $documentRef;
		$request->userId = $userId;

		$proposal = $this->pendingProposalFor(request: $request, text: $text);
		$this->reportSupersededProposal(request: $request);

		$saved = $this->assessmentService->saveRedactionProposal(
			caseId: $caseId,
			documentRef: $documentRef,
			proposal: $proposal
		);

		$this->auditService->recordAssistantAuditEntry(entry: $this->proposalAuditEntry(request: $request, proposal: $proposal));

		return ($saved['redactionProposal'] ?? $proposal);
	}//end proposeSpans()

	/**
	 * Detect the spans of a text and shape them as a proposal pending review.
	 *
	 * Records on the request how long detection took, how long the text was
	 * and how many spans the rules found, for the audit entry.
	 *
	 * @param SpanProposalRequest $request The case, document and person asking.
	 * @param string              $text    The document text.
	 *
	 * @return array<string, mixed> The proposal, pending review.
	 */
	private function pendingProposalFor(SpanProposalRequest $request, string $text): array {
		$startTime = microtime(true);
		$ruleSpans = $this->aiService->detectDeterministicPiiSpans(text: $text);
		$proposal = $this->buildProposal(ruleSpans: $ruleSpans, text: $text, context: $this->documentContext(documentRef: $request->documentRef));
		$request->responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
		$request->textLength = strlen($text);
		$request->ruleSpanCount = count($ruleSpans);

		$proposal['proposedBy'] = $request->userId;
		$proposal['proposedAt'] = date('c');
		$proposal['status'] = 'pending_review';

		return $proposal;
	}//end pendingProposalFor()

	/**
	 * The context the detection is asked in: this app, a document, and which one.
	 *
	 * @param string $documentRef The document.
	 *
	 * @return array<string, string>
	 */
	private function documentContext(string $documentRef): array {
		return [
			'app' => Application::APP_ID,
			'objectType' => 'document',
			'objectRef' => $documentRef,
		];
	}//end documentContext()

	/**
	 * The audit entry of a proposal: what was asked and what came back, without the text itself.
	 *
	 * @param SpanProposalRequest  $request  The request.
	 * @param array<string, mixed> $proposal The proposal.
	 *
	 * @return array<string, mixed>
	 */
	private function proposalAuditEntry(SpanProposalRequest $request, array $proposal): array {
		return [
			'type' => 'anonymisation',
			'action' => 'proposal',
			'caseId' => $request->caseId,
			'documentId' => $request->documentRef,
			'model' => 'hermiq',
			'prompt' => '[' . $request->textLength . ' chars of document text — not recorded verbatim]',
			'suggestion' => [
				'ruleSpanCount' => $request->ruleSpanCount,
				'mergedSpanCount' => count($proposal['spans']),
				'source' => $proposal['source'],
				'llmAvailable' => $proposal['llmAvailable'],
			],
			'confidence' => 0.0,
			'userId' => $request->userId,
			'timestamp' => date('c'),
			'responseTimeMs' => $request->responseTimeMs,
		];
	}//end proposalAuditEntry()

	/**
	 * Record a human reviewer's decision on a pending proposal.
	 *
	 * On `approve`, the proposal is marked `approved` with the reviewed spans;
	 * on `reject`, it is marked `rejected`. Neither writes a redacted copy:
	 * that is the redaction panel's, after an officer checked the entities
	 * filinq will act on.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 * @param string $decision `'approve'` or `'reject'`.
	 * @param string $reviewerId The reviewing user id.
	 * @param array|null $editedSpans Optional reviewer-edited span list (approve only) —
	 *                                when omitted, the full merged proposal is approved
	 *                                as-is.
	 *
	 * @return array<string, mixed> The updated proposal record.
	 *
	 * @throws InvalidArgumentException (400) On an invalid `decision`.
	 * @throws RuntimeException When no `pending_review` proposal exists for this document.
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
	 */
	public function reviewProposal(
		string $caseId,
		string $documentRef,
		string $decision,
		string $reviewerId,
		?array $editedSpans = null,
	): array {
		$this->assertValidDecision(decision: $decision);

		$review = new ProposalReview();
		$review->caseId = $caseId;
		$review->documentRef = $documentRef;
		$review->reviewerId = $reviewerId;
		$review->proposal = $this->pendingProposal(review: $review);

		if ($decision === 'reject') {
			return $this->settleReview(review: $review, userAction: $this->reject(review: $review));
		}

		return $this->settleReview(review: $review, userAction: $this->approve(review: $review, editedSpans: $editedSpans));
	}//end reviewProposal()

	/**
	 * Refuse a review decision that is neither approve nor reject.
	 *
	 * @param string $decision The decision asked for.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException (400) On an invalid `decision`.
	 */
	private function assertValidDecision(string $decision): void {
		if (in_array($decision, self::VALID_DECISIONS, true) === false) {
			throw new InvalidArgumentException(
				'decision must be one of: ' . implode(', ', self::VALID_DECISIONS)
			);
		}
	}//end assertValidDecision()

	/**
	 * Mark the proposal rejected by the reviewer.
	 *
	 * @param ProposalReview $review The review.
	 *
	 * @return string 'rejected'.
	 */
	private function reject(ProposalReview $review): string {
		$review->proposal['status'] = 'rejected';
		$review->proposal['reviewedBy'] = $review->reviewerId;
		$review->proposal['reviewedAt'] = date('c');

		return 'rejected';
	}//end reject()

	/**
	 * The proposal pending review on the document, or a refusal when there is none.
	 *
	 * @param ProposalReview $review The review.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When no proposal is pending.
	 */
	private function pendingProposal(ProposalReview $review): array {
		$existing = $this->assessmentService->findAssessment(caseId: $review->caseId, documentRef: $review->documentRef);
		$proposal = ($existing['redactionProposal'] ?? null);
		if (is_array($proposal) === false || ($proposal['status'] ?? null) !== 'pending_review') {
			throw new RuntimeException(
				'No pending redaction proposal found for document ' . $review->documentRef . ' in case ' . $review->caseId
			);
		}

		return $proposal;
	}//end pendingProposal()

	/**
	 * Approve the proposal with the reviewer's spans, or its own; say whether they changed it.
	 *
	 * @param ProposalReview         $review      The review.
	 * @param array<int, mixed>|null $editedSpans The reviewer's spans, or null to keep the proposal's.
	 *
	 * @return string 'accepted' or 'modified'.
	 */
	private function approve(ProposalReview $review, ?array $editedSpans): string {
		$approvedSpans = ($editedSpans ?? $review->proposal['spans']);
		$userAction = 'accepted';
		if ($approvedSpans !== $review->proposal['spans']) {
			$userAction = 'modified';
		}

		$review->proposal['status'] = 'approved';
		$review->proposal['approvedSpans'] = $approvedSpans;
		$review->proposal['reviewedBy'] = $review->reviewerId;
		$review->proposal['reviewedAt'] = date('c');

		return $userAction;
	}//end approve()

	/**
	 * Save the reviewed proposal and report the decision to oversight.
	 *
	 * @param ProposalReview $review     The review.
	 * @param string         $userAction What the reviewer did.
	 *
	 * @return array<string, mixed> The proposal as saved.
	 */
	private function settleReview(ProposalReview $review, string $userAction): array {
		$saved = $this->assessmentService->saveRedactionProposal(
			caseId: $review->caseId,
			documentRef: $review->documentRef,
			proposal: $review->proposal
		);

		$this->reportDecision(review: $review, decision: new OversightDecision(userAction: $userAction));

		return ($saved['redactionProposal'] ?? $review->proposal);
	}//end settleReview()

	/**
	 * Report a person's decision on a proposal to the AI oversight trail.
	 *
	 * @param ProposalReview    $review   The case, document, person and proposal as decided.
	 * @param OversightDecision $decision What the person did, and why when it went unused.
	 *
	 * @return void
	 */
	private function reportDecision(ProposalReview $review, OversightDecision $decision): void {
		$this->auditService->recordUserAction(
			caseId: $review->caseId,
			type: 'extraction',
			userAction: $decision->userAction,
			suggestion: ['spans' => ($review->proposal['spans'] ?? [])],
			actualValue: $this->actualValue(review: $review, decision: $decision),
			reason: null,
			userId: $review->reviewerId,
			context: $this->oversightContext(review: $review, decision: $decision),
		);
	}//end reportDecision()

	/**
	 * The spans actually used, reported only when the person changed the proposal.
	 *
	 * @param ProposalReview    $review   The proposal as decided.
	 * @param OversightDecision $decision What the person did.
	 *
	 * @return array{spans: array<int, mixed>}|null The spans used, or null.
	 */
	private function actualValue(ProposalReview $review, OversightDecision $decision): ?array {
		if ($decision->userAction !== 'modified') {
			return null;
		}

		return ['spans' => ($review->proposal['approvedSpans'] ?? [])];
	}//end actualValue()

	/**
	 * The oversight context of a decision: why unused, the model, document and suggestion.
	 *
	 * @param ProposalReview    $review   The proposal as decided.
	 * @param OversightDecision $decision What the person did.
	 *
	 * @return array<string, string> The context.
	 */
	private function oversightContext(ProposalReview $review, OversightDecision $decision): array {
		return [
			'unusedBecause' => $decision->because,
			'model' => 'hermiq',
			'documentId' => $review->documentRef,
			'suggestionId' => $review->documentRef . '@' . (string)($review->proposal['proposedAt'] ?? ''),
		];
	}//end oversightContext()

	/**
	 * Report a still-pending proposal that a new one is about to replace.
	 *
	 * It is reported for the person it was proposed by, else the person asking anew.
	 *
	 * @param SpanProposalRequest $request The case, document and person asking for the new proposal.
	 *
	 * @return void
	 */
	private function reportSupersededProposal(SpanProposalRequest $request): void {
		$existing = $this->assessmentService->findAssessment(caseId: $request->caseId, documentRef: $request->documentRef);
		$previous = ($existing['redactionProposal'] ?? null);
		if (is_array($previous) === false || ($previous['status'] ?? null) !== 'pending_review') {
			return;
		}

		$review = new ProposalReview();
		$review->caseId = $request->caseId;
		$review->documentRef = $request->documentRef;
		$review->reviewerId = (string)($previous['proposedBy'] ?? $request->userId);
		$review->proposal = $previous;

		$this->reportDecision(review: $review, decision: new OversightDecision(userAction: 'ignored', because: 'superseded'));
	}//end reportSupersededProposal()

	/**
	 * The rules floor and Hermiq's spans for a document's text, merged, without saving a proposal.
	 *
	 * The redaction panel asks this for the names and other personal data the
	 * entity detector behind filinq may miss. Text past the length cap is not
	 * scanned. Fails closed to the rules floor exactly as a proposal does.
	 *
	 * @param string $text        The document text.
	 * @param string $documentRef The document, for Hermiq's audit context.
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable, llmError?}`.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function spansFor(string $text, string $documentRef): array {
		$text = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
		if (trim($text) === '') {
			return ['spans' => [], 'source' => 'rules_only', 'llmAvailable' => $this->isLlmAssistAvailable()];
		}

		return $this->buildProposal(
			ruleSpans: $this->aiService->detectDeterministicPiiSpans(text: $text),
			text: $text,
			context: ['app' => Application::APP_ID, 'objectType' => 'document', 'objectRef' => $documentRef]
		);
	}//end spansFor()

	/**
	 * Validate the `text` field.
	 *
	 * @param string $text The document text.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException (400) When empty or over the length cap.
	 */
	private function validateText(string $text): void {
		if (trim($text) === '') {
			throw new InvalidArgumentException('text is required');
		}

		if (strlen($text) > self::MAX_TEXT_LENGTH) {
			throw new InvalidArgumentException(
				'text exceeds the maximum length of ' . self::MAX_TEXT_LENGTH . ' characters'
			);
		}
	}//end validateText()

	/**
	 * Build the merged proposal: rules floor ALWAYS present, LLM spans
	 * layered on top when available, fail-closed to rules-only on any
	 * Hermiq failure.
	 *
	 * @param array<int, array<string, mixed>> $ruleSpans The deterministic rule-detected spans.
	 * @param string $text The document text (forwarded to Hermiq).
	 * @param array<string, mixed> $context `{app, objectType, objectRef}`.
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable, llmError?}`.
	 */
	private function buildProposal(array $ruleSpans, string $text, array $context): array {
		$taggedRuleSpans = $this->taggedAsRules(spans: $ruleSpans);

		if ($this->hermiqClient->isAvailable() === false) {
			return [
				'spans' => $taggedRuleSpans,
				'source' => 'rules_only',
				'llmAvailable' => false,
			];
		}

		try {
			$llmResult = $this->hermiqClient->detectPii(text: $text, context: $context);
		} catch (HermiqAssistantException $e) {
			$this->logger->warning(
				'WOOAnonymisationAssistService: LLM-assisted detection failed, falling back to rules-only',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);

			return [
				'spans' => $taggedRuleSpans,
				'source' => 'rules_only_fallback',
				'llmAvailable' => true,
				'llmError' => $e->getMessage(),
			];
		}

		return [
			'spans' => $this->mergeSpansRulesFloor(ruleSpans: $taggedRuleSpans, llmSpans: $llmResult['spans']),
			'source' => 'rules_plus_llm',
			'llmAvailable' => true,
		];
	}//end buildProposal()

	/**
	 * The spans, each tagged as found by the rules.
	 *
	 * @param array<int, array<string, mixed>> $spans The rule spans.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function taggedAsRules(array $spans): array {
		return array_map(
			static function (array $span): array {
				$span['source'] = 'rule';
				return $span;
			},
			$spans
		);
	}//end taggedAsRules()

	/**
	 * Merge rule-detected spans with LLM-proposed spans by UNION.
	 *
	 * INVARIANT (asserted directly by a pinned unit test): every span in
	 * `$ruleSpans` is present, byte-for-byte unchanged, in the returned
	 * array — regardless of the contents of `$llmSpans`. The LLM layer can
	 * only ADD spans (skipping any that exactly duplicate a rule span's
	 * `[start, end, category)` triple); it can never remove, shrink, or
	 * override a rule-detected span. This is what makes the rules floor a
	 * FLOOR rather than a suggestion (woo-llm-anonymisation design.md).
	 *
	 * @param array<int, array<string, mixed>> $ruleSpans Rule-detected spans, already tagged `source: 'rule'`.
	 * @param array<int, array<string, mixed>> $llmSpans Raw spans from Hermiq's detect-pii response.
	 *
	 * @return array<int, array<string, mixed>> The merged, start-sorted span list.
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-3
	 */
	private function mergeSpansRulesFloor(array $ruleSpans, array $llmSpans): array {
		$merged = $ruleSpans;
		foreach ($this->llmAdditions(llmSpans: $llmSpans, ruleKeys: $this->spanKeys(spans: $ruleSpans)) as $span) {
			$merged[] = $span;
		}

		usort($merged, static fn (array $a, array $b): int => ($a['start'] <=> $b['start']));

		return $merged;
	}//end mergeSpansRulesFloor()

	/**
	 * The dedup keys of the rule spans.
	 *
	 * @param array<int, array<string, mixed>> $spans The rule spans.
	 *
	 * @return array<string, true> Key to true.
	 */
	private function spanKeys(array $spans): array {
		$keys = [];
		foreach ($spans as $span) {
			$keys[$this->spanKey(span: $span)] = true;
		}

		return $keys;
	}//end spanKeys()

	/**
	 * The LLM spans that are well-formed and not already a rule span, tagged `source: 'llm'`.
	 *
	 * @param array<int, mixed>   $llmSpans Raw spans from Hermiq's detect-pii response.
	 * @param array<string, true> $ruleKeys The dedup keys of the rule spans.
	 *
	 * @return array<int, array<string, mixed>> The spans to add, in Hermiq's order.
	 */
	private function llmAdditions(array $llmSpans, array $ruleKeys): array {
		$added = [];
		foreach ($llmSpans as $span) {
			// Malformed/out-of-range span — never trusted, never merged in.
			// An exact duplicate of a rule span is already covered — skip the noise.
			if ($this->isValidLlmSpan(span: $span) === false || isset($ruleKeys[$this->spanKey(span: $span)]) === true) {
				continue;
			}

			$span['source'] = 'llm';
			$added[] = $span;
		}

		return $added;
	}//end llmAdditions()

	/**
	 * Whether a raw LLM-returned span has the minimum shape/range required
	 * to be trusted at all: an array with integer `start`/`end`, a string
	 * `category`, a non-negative `start`, and `end` strictly after `start`.
	 * Split out of `mergeSpansRulesFloor()` to keep that method's own
	 * cyclomatic complexity readable — this predicate carries no state.
	 *
	 * @param mixed $span The raw span value (untyped — comes from a decoded
	 *                    Hermiq JSON response this method does not trust).
	 *
	 * @return bool
	 */
	private function isValidLlmSpan(mixed $span): bool {
		if ($this->hasSpanShape(span: $span) === false) {
			return false;
		}

		return ($span['start'] >= 0 && $span['end'] > $span['start']);
	}//end isValidLlmSpan()

	/**
	 * Whether a raw value is an array with integer `start`/`end` and a string `category`.
	 *
	 * @param mixed $span The raw span value.
	 *
	 * @return bool
	 */
	private function hasSpanShape(mixed $span): bool {
		return (is_array($span) === true
			&& is_int($span['start'] ?? null) === true
			&& is_int($span['end'] ?? null) === true
			&& is_string($span['category'] ?? null) === true);
	}//end hasSpanShape()

	/**
	 * Build the dedup key for a span: `start:end:category`.
	 *
	 * @param array<string, mixed> $span The span.
	 *
	 * @return string
	 */
	private function spanKey(array $span): string {
		return $span['start'] . ':' . $span['end'] . ':' . $span['category'];
	}//end spanKey()
}//end class
