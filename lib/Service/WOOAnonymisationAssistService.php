<?php

/**
 * Procest WOO Anonymisation Assist Service
 *
 * LLM-ASSISTED redaction-span proposal for WOO document disclosure review.
 * This service is an ASSIST layered on top of the existing, unchanged
 * `WOORedactionService` (Docudesk hand-off / manual fallback) — it never
 * replaces it, never marks anything "anonymised" itself, and never publishes
 * anything. It:
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
 *      explicitly approve/reject it (`reviewProposal()`) before it can flow
 *      into `WOORedactionService::queueForRedaction()`. Nothing here is
 *      auto-applied or auto-published (EU AI Act Art.14 human-in-the-loop
 *      posture procest already takes for every other AI suggestion).
 *
 * FLEET RULE: AI functionality lives in Hermiq. This class contains NO
 * prompt-building, model selection, or LLM-calling logic — that lives
 * entirely behind `HermiqAnonymisationClient`/Hermiq's `detectPii()`
 * endpoint. This class is context assembly, the rules-floor merge
 * invariant, human-review gating, and audit plumbing.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Assistant\HermiqAnonymisationClient;
use OCA\Procest\Service\Assistant\HermiqAssistantException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service orchestrating LLM-assisted, human-reviewed redaction proposals.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
 */
class WOOAnonymisationAssistService
{
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
     * @param AiService                    $aiService         Deterministic PII rules floor.
     * @param HermiqAnonymisationClient    $hermiqClient      Thin HTTP client to Hermiq's
     *                                                        detect-pii surface.
     * @param WOODocumentAssessmentService $assessmentService Reads/writes the wooAssessment
     *                                                        record a proposal attaches to.
     * @param WOORedactionService          $redactionService  The EXISTING, unchanged
     *                                                        Docudesk/manual
     *                                                        redaction hand-off this
     *                                                        assist feeds an
     *                                                        approved proposal into.
     * @param LoggerInterface              $logger            Structured logger.
     */
    public function __construct(
        private readonly AiService $aiService,
        private readonly HermiqAnonymisationClient $hermiqClient,
        private readonly WOODocumentAssessmentService $assessmentService,
        private readonly WOORedactionService $redactionService,
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
    public function isLlmAssistAvailable(): bool
    {
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
     * @param string $caseId      The case UUID.
     * @param string $documentRef The document UUID.
     * @param string $text        The document text to scan (caller is responsible for having
     *                            authorization to read the underlying document — this method
     *                            does no file access of its own).
     * @param string $userId      The requesting user id (audit + `proposedBy`).
     *
     * @return array<string, mixed> `{spans, source, llmAvailable, llmError?, status}`.
     *
     * @throws InvalidArgumentException (400) When `text` is empty or over the length cap.
     * @throws RuntimeException When the document has not yet been assessed (assess-first rule).
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
     */
    public function proposeSpans(string $caseId, string $documentRef, string $text, string $userId): array
    {
        $this->validateText(text: $text);

        $startTime = microtime(true);
        $ruleSpans = $this->aiService->detectDeterministicPiiSpans(text: $text);

        $proposal = $this->buildProposal(
                ruleSpans: $ruleSpans,
                text: $text,
                context: [
                    'app'        => Application::APP_ID,
                    'objectType' => 'document',
                    'objectRef'  => $documentRef,
                ]
                );

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $proposal['proposedBy'] = $userId;
        $proposal['proposedAt'] = date('c');
        $proposal['status']     = 'pending_review';

        $saved = $this->assessmentService->saveRedactionProposal(
            caseId: $caseId,
            documentRef: $documentRef,
            proposal: $proposal
        );

        $this->aiService->recordAssistantAuditEntry(
            entry: [
                'type'           => 'anonymisation',
                'action'         => 'proposal',
                'caseId'         => $caseId,
                'documentId'     => $documentRef,
                'model'          => 'hermiq',
                'prompt'         => '['.strlen($text).' chars of document text — not recorded verbatim]',
                'suggestion'     => [
                    'ruleSpanCount'   => count($ruleSpans),
                    'mergedSpanCount' => count($proposal['spans']),
                    'source'          => $proposal['source'],
                    'llmAvailable'    => $proposal['llmAvailable'],
                ],
                'confidence'     => 0.0,
                'userId'         => $userId,
                'timestamp'      => date('c'),
                'responseTimeMs' => $responseTimeMs,
            ]
        );

        return ($saved['redactionProposal'] ?? $proposal);
    }//end proposeSpans()

    /**
     * Record a human reviewer's decision on a pending proposal.
     *
     * On `approve`, the proposal's spans are handed to the EXISTING,
     * UNCHANGED `WOORedactionService::queueForRedaction()` as guidance
     * metadata — the actual redaction execution (Docudesk pipeline or
     * manual upload) is entirely unaffected by this feature; this assist
     * only informs it. On `reject`, the proposal is marked `rejected` and
     * discarded — the pre-existing manual/Docudesk fallback proceeds exactly
     * as it always has.
     *
     * @param string     $caseId      The case UUID.
     * @param string     $documentRef The document UUID.
     * @param string     $decision    `'approve'` or `'reject'`.
     * @param string     $reviewerId  The reviewing user id.
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
        ?array $editedSpans=null
    ): array {
        if (in_array($decision, self::VALID_DECISIONS, true) === false) {
            throw new InvalidArgumentException(
                'decision must be one of: '.implode(', ', self::VALID_DECISIONS)
            );
        }

        $existing = $this->assessmentService->findAssessment(caseId: $caseId, documentRef: $documentRef);
        $proposal = ($existing['redactionProposal'] ?? null);
        if (is_array($proposal) === false || ($proposal['status'] ?? null) !== 'pending_review') {
            throw new RuntimeException(
                'No pending redaction proposal found for document '.$documentRef.' in case '.$caseId
            );
        }

        if ($decision === 'reject') {
            $proposal['status']     = 'rejected';
            $proposal['reviewedBy'] = $reviewerId;
            $proposal['reviewedAt'] = date('c');

            $saved = $this->assessmentService->saveRedactionProposal(
                caseId: $caseId,
                documentRef: $documentRef,
                proposal: $proposal
            );

            return ($saved['redactionProposal'] ?? $proposal);
        }

        $approvedSpans = ($editedSpans ?? $proposal['spans']);

        $proposal['status']        = 'approved';
        $proposal['approvedSpans'] = $approvedSpans;
        $proposal['reviewedBy']    = $reviewerId;
        $proposal['reviewedAt']    = date('c');

        $saved = $this->assessmentService->saveRedactionProposal(
            caseId: $caseId,
            documentRef: $documentRef,
            proposal: $proposal
        );

        // Hand off to the EXISTING, unchanged redaction pipeline — this assist
        // only carries the approved spans along as guidance; it never performs
        // the redaction itself and never marks the document "anonymised".
        $this->redactionService->queueForRedaction(
            caseId: $caseId,
            documents: [
                [
                    'id'                => $documentRef,
                    'redactionProposal' => ['spans' => $approvedSpans, 'reviewedBy' => $reviewerId],
                ],
            ]
        );

        return ($saved['redactionProposal'] ?? $proposal);
    }//end reviewProposal()

    /**
     * Validate the `text` field.
     *
     * @param string $text The document text.
     *
     * @return void
     *
     * @throws InvalidArgumentException (400) When empty or over the length cap.
     */
    private function validateText(string $text): void
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('text is required');
        }

        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(
                'text exceeds the maximum length of '.self::MAX_TEXT_LENGTH.' characters'
            );
        }
    }//end validateText()

    /**
     * Build the merged proposal: rules floor ALWAYS present, LLM spans
     * layered on top when available, fail-closed to rules-only on any
     * Hermiq failure.
     *
     * @param array<int, array<string, mixed>> $ruleSpans The deterministic rule-detected spans.
     * @param string                           $text      The document text (forwarded to Hermiq).
     * @param array<string, mixed>             $context   `{app, objectType, objectRef}`.
     *
     * @return array<string, mixed> `{spans, source, llmAvailable, llmError?}`.
     */
    private function buildProposal(array $ruleSpans, string $text, array $context): array
    {
        $taggedRuleSpans = array_map(
            static function (array $span): array {
                $span['source'] = 'rule';
                return $span;
            },
            $ruleSpans
        );

        if ($this->hermiqClient->isAvailable() === false) {
            return [
                'spans'        => $taggedRuleSpans,
                'source'       => 'rules_only',
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
                'spans'        => $taggedRuleSpans,
                'source'       => 'rules_only_fallback',
                'llmAvailable' => true,
                'llmError'     => $e->getMessage(),
            ];
        }

        return [
            'spans'        => $this->mergeSpansRulesFloor(ruleSpans: $taggedRuleSpans, llmSpans: $llmResult['spans']),
            'source'       => 'rules_plus_llm',
            'llmAvailable' => true,
        ];
    }//end buildProposal()

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
     * @param array<int, array<string, mixed>> $llmSpans  Raw spans from Hermiq's detect-pii response.
     *
     * @return array<int, array<string, mixed>> The merged, start-sorted span list.
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-3
     */
    private function mergeSpansRulesFloor(array $ruleSpans, array $llmSpans): array
    {
        $ruleKeys = [];
        foreach ($ruleSpans as $span) {
            $ruleKeys[$this->spanKey(span: $span)] = true;
        }

        $merged = $ruleSpans;

        foreach ($llmSpans as $span) {
            if ($this->isValidLlmSpan(span: $span) === false) {
                // Malformed/out-of-range span — never trusted, never merged in.
                continue;
            }

            $key = $this->spanKey(span: $span);
            if (isset($ruleKeys[$key]) === true) {
                // Exact duplicate of a rule span — already covered, skip the noise.
                continue;
            }

            $span['source'] = 'llm';
            $merged[]       = $span;
        }//end foreach

        usort($merged, static fn (array $a, array $b): int => ($a['start'] <=> $b['start']));

        return $merged;
    }//end mergeSpansRulesFloor()

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
    private function isValidLlmSpan(mixed $span): bool
    {
        if (is_array($span) === false
            || is_int($span['start'] ?? null) === false
            || is_int($span['end'] ?? null) === false
            || is_string($span['category'] ?? null) === false
        ) {
            return false;
        }

        return ($span['start'] >= 0 && $span['end'] > $span['start']);
    }//end isValidLlmSpan()

    /**
     * Build the dedup key for a span: `start:end:category`.
     *
     * @param array<string, mixed> $span The span.
     *
     * @return string
     */
    private function spanKey(array $span): string
    {
        return $span['start'].':'.$span['end'].':'.$span['category'];
    }//end spanKey()
}//end class
