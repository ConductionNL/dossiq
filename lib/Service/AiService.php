<?php

/**
 * Procest AI Service
 *
 * Service for AI-assisted case processing. Orchestrates AI model calls
 * via n8n MCP workflows for document classification, data extraction,
 * knowledge base Q&A, summarization, and routing suggestions.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Ai\AiAuditLog;
use OCA\Procest\Service\Ai\AiEndpointGuard;
use OCA\Procest\Service\Ai\AiPiiRedactor;
use OCA\Procest\Service\Ai\AiPromptFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for AI-assisted case processing.
 *
 * All AI interactions follow the human-in-the-loop principle:
 * AI suggests, humans confirm. Every interaction is recorded
 * in the audit trail for Algoritmeregister compliance.
 *
 * This class is the orchestration layer only: it decides WHETHER a feature is
 * enabled, asks {@see AiPromptFactory} for the prompt, scrubs it through
 * {@see AiPiiRedactor}, makes the one outbound model call (guarded by
 * {@see AiEndpointGuard}) and records the result via {@see AiAuditLog}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
 */
class AiService
{
    /**
     * Constructor for AiService.
     *
     * @param IAppConfig      $appConfig     The app configuration service
     * @param AiPromptFactory $prompts       The prompt templates
     * @param AiPiiRedactor   $pii           The PII detector / scrubber
     * @param AiEndpointGuard $endpointGuard The model-URL SSRF guard
     * @param AiAuditLog      $audit         The oversight audit trail
     * @param LoggerInterface $logger        The logger interface
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private AiPromptFactory $prompts,
        private AiPiiRedactor $pii,
        private AiEndpointGuard $endpointGuard,
        private AiAuditLog $audit,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if AI features are globally enabled.
     *
     * @return bool

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function isEnabled(): bool
    {
        return $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_enabled',
            ''
        ) === '1';
    }//end isEnabled()

    /**
     * Check if a specific AI feature is enabled.
     *
     * @param string $feature The feature name (classification, extraction, qa, summary, routing, decision_support)
     *
     * @return bool

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function isFeatureEnabled(string $feature): bool
    {
        if ($this->isEnabled() === false) {
            return false;
        }

        return $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_feature_'.$feature,
            ''
        ) === '1';
    }//end isFeatureEnabled()

    /**
     * Classify a document using AI.
     *
     * Sends document content to the configured AI model for classification.
     * Returns a suggested document type with confidence score.
     *
     * @param string $caseId     The case ID
     * @param string $documentId The document ID to classify
     * @param string $userId     The current user ID
     *
     * @return array Classification result with suggestion and confidence

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function classifyDocument(string $caseId, string $documentId, string $userId): array
    {
        if ($this->isFeatureEnabled(feature: 'classification') === false) {
            return [
                'success' => false,
                'message' => 'AI document classification is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->prompts->classification(caseId: $caseId, documentId: $documentId);
            $prompt = $this->stripPiiIfEnabled(prompt: $prompt);

            $result = $this->callAiModel(prompt: $prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    entry: [
                        'type'           => 'classification',
                        'action'         => 'suggestion',
                        'caseId'         => $caseId,
                        'documentId'     => $documentId,
                        'model'          => $this->getModelIdentifier(),
                        'prompt'         => $prompt,
                        'suggestion'     => $result,
                        'confidence'     => ($result['confidence'] ?? 0.0),
                        'userId'         => $userId,
                        'timestamp'      => date('c'),
                        'responseTimeMs' => $responseTimeMs,
                    ]
                    );

            return [
                'success'    => true,
                'suggestion' => $result,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'AI classification failed',
                ['caseId' => $caseId, 'documentId' => $documentId, 'error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'AI classification failed: '.$e->getMessage(),
            ];
        }//end try
    }//end classifyDocument()

    /**
     * Extract structured data from case documents using AI.
     *
     * @param string      $caseId     The case ID
     * @param string|null $documentId Optional specific document ID
     * @param string      $userId     The current user ID
     *
     * @return array Extraction result with field suggestions and confidence

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function extractData(string $caseId, ?string $documentId, string $userId): array
    {
        if ($this->isFeatureEnabled(feature: 'extraction') === false) {
            return [
                'success' => false,
                'message' => 'AI data extraction is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->prompts->extraction(caseId: $caseId, documentId: $documentId);
            $prompt = $this->stripPiiIfEnabled(prompt: $prompt);

            $result = $this->callAiModel(prompt: $prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    entry: [
                        'type'           => 'extraction',
                        'action'         => 'suggestion',
                        'caseId'         => $caseId,
                        'documentId'     => ($documentId ?? ''),
                        'model'          => $this->getModelIdentifier(),
                        'prompt'         => $prompt,
                        'suggestion'     => $result,
                        'confidence'     => ($result['averageConfidence'] ?? 0.0),
                        'userId'         => $userId,
                        'timestamp'      => date('c'),
                        'responseTimeMs' => $responseTimeMs,
                    ]
                    );

            return [
                'success' => true,
                'fields'  => ($result['fields'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'AI extraction failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'AI data extraction failed: '.$e->getMessage(),
            ];
        }//end try
    }//end extractData()

    /**
     * Ask a knowledge base question in case context.
     *
     * @param string $caseId   The case ID for context
     * @param string $question The user's question
     * @param string $userId   The current user ID
     *
     * @return array Answer with source citations

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function askQuestion(string $caseId, string $question, string $userId): array
    {
        if ($this->isFeatureEnabled(feature: 'qa') === false) {
            return [
                'success' => false,
                'message' => 'AI knowledge base Q&A is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->prompts->question(caseId: $caseId, question: $question);

            $result = $this->callAiModel(prompt: $prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    entry: [
                        'type'           => 'qa',
                        'action'         => 'suggestion',
                        'caseId'         => $caseId,
                        'model'          => $this->getModelIdentifier(),
                        'prompt'         => $question,
                        'suggestion'     => $result,
                        'confidence'     => ($result['confidence'] ?? 0.0),
                        'userId'         => $userId,
                        'timestamp'      => date('c'),
                        'responseTimeMs' => $responseTimeMs,
                    ]
                    );

            return [
                'success' => true,
                'answer'  => ($result['answer'] ?? ''),
                'sources' => ($result['sources'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'AI Q&A failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'AI Q&A failed: '.$e->getMessage(),
            ];
        }//end try
    }//end askQuestion()

    /**
     * Generate a summary for a case, document, or timeline.
     *
     * @param string      $caseId     The case ID
     * @param string      $type       Summary type: case, document, or timeline
     * @param string|null $documentId Optional document ID for document summaries
     * @param string      $userId     The current user ID
     *
     * @return array Summary text

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function summarize(string $caseId, string $type, ?string $documentId, string $userId): array
    {
        if ($this->isFeatureEnabled(feature: 'summary') === false) {
            return [
                'success' => false,
                'message' => 'AI summarization is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->prompts->summary(caseId: $caseId, type: $type, documentId: $documentId);
            $prompt = $this->stripPiiIfEnabled(prompt: $prompt);

            $result = $this->callAiModel(prompt: $prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    entry: [
                        'type'           => 'summary',
                        'action'         => 'suggestion',
                        'caseId'         => $caseId,
                        'documentId'     => ($documentId ?? ''),
                        'model'          => $this->getModelIdentifier(),
                        'prompt'         => $prompt,
                        'suggestion'     => ['summary' => ($result['summary'] ?? '')],
                        'userId'         => $userId,
                        'timestamp'      => date('c'),
                        'responseTimeMs' => $responseTimeMs,
                    ]
                    );

            return [
                'success' => true,
                'summary' => ($result['summary'] ?? ''),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'AI summarization failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'AI summarization failed: '.$e->getMessage(),
            ];
        }//end try
    }//end summarize()

    /**
     * Suggest case routing based on expertise and workload.
     *
     * @param string $caseId The case ID
     * @param string $userId The current user ID
     *
     * @return array Routing suggestion with recommended case worker

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function suggestRouting(string $caseId, string $userId): array
    {
        if ($this->isFeatureEnabled(feature: 'routing') === false) {
            return [
                'success' => false,
                'message' => 'AI case routing is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->prompts->routing(caseId: $caseId);

            $result = $this->callAiModel(prompt: $prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    entry: [
                        'type'           => 'routing',
                        'action'         => 'suggestion',
                        'caseId'         => $caseId,
                        'model'          => $this->getModelIdentifier(),
                        'prompt'         => $prompt,
                        'suggestion'     => $result,
                        'confidence'     => ($result['confidence'] ?? 0.0),
                        'userId'         => $userId,
                        'timestamp'      => date('c'),
                        'responseTimeMs' => $responseTimeMs,
                    ]
                    );

            return [
                'success'     => true,
                'suggestions' => ($result['suggestions'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'AI routing suggestion failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'AI routing suggestion failed: '.$e->getMessage(),
            ];
        }//end try
    }//end suggestRouting()

    /**
     * Suggest next steps for a case based on current state.
     *
     * @param string $caseId The case ID
     * @param string $userId The current user ID
     *
     * @return array Next-step suggestions

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function suggestNextStep(string $caseId, string $userId): array
    {
        if ($this->isFeatureEnabled(feature: 'decision_support') === false) {
            return [
                'success' => false,
                'message' => 'AI decision support is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->prompts->nextStep(caseId: $caseId);

            $result = $this->callAiModel(prompt: $prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    entry: [
                        'type'           => 'decision_support',
                        'action'         => 'suggestion',
                        'caseId'         => $caseId,
                        'model'          => $this->getModelIdentifier(),
                        'prompt'         => $prompt,
                        'suggestion'     => $result,
                        'userId'         => $userId,
                        'timestamp'      => date('c'),
                        'responseTimeMs' => $responseTimeMs,
                    ]
                    );

            return [
                'success'     => true,
                'suggestions' => ($result['suggestions'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'AI next-step suggestion failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'AI next-step suggestion failed: '.$e->getMessage(),
            ];
        }//end try
    }//end suggestNextStep()

    /**
     * Record a user action on an AI suggestion (accept, reject, modify).
     *
     * @param string      $caseId      The case ID
     * @param string      $type        AI type (classification, extraction, etc.)
     * @param string      $userAction  User action (accepted, rejected, modified)
     * @param array       $suggestion  The original suggestion
     * @param array|null  $actualValue The value actually applied
     * @param string|null $reason      Reason for rejection/modification
     * @param string      $userId      The current user ID
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — audit entries need full context

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function recordUserAction(
        string $caseId,
        string $type,
        string $userAction,
        array $suggestion,
        ?array $actualValue,
        ?string $reason,
        string $userId,
    ): array {
        $this->recordAuditEntry(
                entry: [
                    'type'        => $type,
                    'action'      => $userAction,
                    'caseId'      => $caseId,
                    'model'       => $this->getModelIdentifier(),
                    'suggestion'  => $suggestion,
                    'userAction'  => $userAction,
                    'actualValue' => ($actualValue ?? []),
                    'reason'      => ($reason ?? ''),
                    'userId'      => $userId,
                    'timestamp'   => date('c'),
                ]
                );

        return ['success' => true];
    }//end recordUserAction()

    /**
     * List recorded AI audit entries from OpenRegister, newest first.
     *
     * Reads the same audit sink {@see AiAuditLog::record()} writes to. Degrades
     * gracefully (empty result, warning logged, no throw) when AI audit storage
     * is not configured or the OpenRegister lookup fails, so a misconfigured
     * instance never 500s the oversight surface.
     *
     * @param array<string, mixed> $filters Optional filters: 'caseId', 'type'.
     * @param int                  $limit   Page size (clamped to 1-200, default 50).
     * @param int                  $offset  Paging offset (clamped to >= 0).
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int|null, limit: int, offset: int}
     *
     * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
     */
    public function listAuditEntries(array $filters=[], int $limit=50, int $offset=0): array
    {
        return $this->audit->list(filters: $filters, limit: $limit, offset: $offset);
    }//end listAuditEntries()

    /**
     * Test AI model connectivity.
     *
     * @return array Health check result

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function testHealth(): array
    {
        $startTime = microtime(true);

        try {
            // The call itself is the health probe; its payload is irrelevant.
            $this->callAiModel(prompt: 'Respond with "ok" to confirm connectivity.');

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success'        => true,
                'status'         => 'connected',
                'model'          => $this->getModelIdentifier(),
                'responseTimeMs' => $responseTimeMs,
            ];
        } catch (\Exception $e) {
            return [
                'success'        => false,
                'status'         => 'error',
                'model'          => $this->getModelIdentifier(),
                'message'        => $e->getMessage(),
                'responseTimeMs' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }//end try
    }//end testHealth()

    /**
     * Get AI settings for the frontend.
     *
     * @return array AI settings (without sensitive data like API keys)

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getAiSettings(): array
    {
        return [
            'ai_enabled'                  => $this->appConfig->getValueString(Application::APP_ID, 'ai_enabled', ''),
            'ai_model_type'               => $this->appConfig->getValueString(Application::APP_ID, 'ai_model_type', 'local'),
            'ai_model_url'                => $this->appConfig->getValueString(Application::APP_ID, 'ai_model_url', ''),
            'ai_model_name'               => $this->appConfig->getValueString(Application::APP_ID, 'ai_model_name', ''),
            'ai_api_key_set'              => $this->appConfig->getValueString(Application::APP_ID, 'ai_api_key', '') !== '',
            'ai_feature_classification'   => $this->appConfig->getValueString(Application::APP_ID, 'ai_feature_classification', ''),
            'ai_feature_extraction'       => $this->appConfig->getValueString(Application::APP_ID, 'ai_feature_extraction', ''),
            'ai_feature_qa'               => $this->appConfig->getValueString(Application::APP_ID, 'ai_feature_qa', ''),
            'ai_feature_summary'          => $this->appConfig->getValueString(Application::APP_ID, 'ai_feature_summary', ''),
            'ai_feature_routing'          => $this->appConfig->getValueString(Application::APP_ID, 'ai_feature_routing', ''),
            'ai_feature_decision_support' => $this->appConfig->getValueString(Application::APP_ID, 'ai_feature_decision_support', ''),
            'ai_dpia_acknowledged'        => $this->appConfig->getValueString(Application::APP_ID, 'ai_dpia_acknowledged', ''),
            'ai_pii_stripping'            => $this->appConfig->getValueString(Application::APP_ID, 'ai_pii_stripping', '1'),
        ];
    }//end getAiSettings()

    /**
     * Get the configured AI model identifier.
     *
     * @return string
     */
    private function getModelIdentifier(): string
    {
        $type = $this->appConfig->getValueString(Application::APP_ID, 'ai_model_type', 'local');
        $name = $this->appConfig->getValueString(Application::APP_ID, 'ai_model_name', 'unknown');

        return $type.'/'.$name;
    }//end getModelIdentifier()

    /**
     * Deterministically detect PII spans in free text using the SAME regex set
     * {@see AiPiiRedactor::strip()} uses to scrub prompts before they leave this
     * app. Returns character offsets rather than scrubbing, so callers (e.g.
     * `WOOAnonymisationAssistService`) can present the exact matched ranges for
     * human review and treat them as an immutable "rules floor" that an
     * LLM-assisted proposal is layered on top of, never allowed to remove
     * (woo-llm-anonymisation design.md).
     *
     * Pure (no I/O, no config lookups). The pattern set itself lives in
     * {@see AiPiiRedactor}, which is the ONE place it is defined — detection and
     * scrubbing can never drift apart on WHICH patterns count as PII.
     *
     * @param string $text The text to scan.
     *
     * @return array<int, array{start: int, end: int, category: string, text: string}>
     *         Spans sorted by `start`, ascending.
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
     */
    public function detectDeterministicPiiSpans(string $text): array
    {
        return $this->pii->detectSpans(text: $text);
    }//end detectDeterministicPiiSpans()

    /**
     * Strip PII from prompt text if PII stripping is enabled.
     *
     * @param string $prompt The prompt text
     *
     * @return string The prompt with PII replaced
     */
    private function stripPiiIfEnabled(string $prompt): string
    {
        $piiEnabled = $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_pii_stripping',
            '1'
        );

        if ($piiEnabled !== '1') {
            return $prompt;
        }

        return $this->pii->strip(prompt: $prompt);
    }//end stripPiiIfEnabled()

    /**
     * Call the AI model with a prompt.
     *
     * Routes through n8n MCP workflow or directly to the model
     * depending on configuration.
     *
     * Visibility is `protected` (not `private`) so PHPUnit tests can stub
     * this single outbound-network seam via an anonymous subclass, rather
     * than mocking curl — see {@see \OCA\Procest\Tests\Unit\Service\AiServiceAuditLoggingCompletenessTest}
     * which asserts every suggestion-time operation records an audit entry
     * without making a real HTTP call.
     *
     * @param string $prompt The prompt to send
     *
     * @return array The AI model response
     *
     * @throws \RuntimeException If the AI model call fails
     */
    protected function callAiModel(string $prompt): array
    {
        $modelUrl = $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_model_url',
            ''
        );

        if (empty($modelUrl) === true) {
            throw new RuntimeException('AI model URL is not configured');
        }

        $modelName = $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_model_name',
            'llama3.1'
        );

        $modelType = $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_model_type',
            'local'
        );

        // Build the request payload for Ollama-compatible API.
        $payload = json_encode(
                [
                    'model'  => $modelName,
                    'prompt' => $prompt,
                    'stream' => false,
                    'format' => 'json',
                ]
                );

        $endpoint = rtrim($modelUrl, '/').'/api/generate';

        // SSRF guard: validate the configured model URL before making outbound requests.
        if ($this->endpointGuard->isSafeUrl(url: $modelUrl, modelType: $modelType) === false) {
            throw new RuntimeException('AI model URL failed SSRF security check');
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        // Add API key for cloud models.
        if ($modelType === 'cloud') {
            $apiKey = $this->appConfig->getValueString(
                Application::APP_ID,
                'ai_api_key',
                ''
            );
            if (empty($apiKey) === false) {
                curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        [
                            'Content-Type: application/json',
                            'Authorization: Bearer '.$apiKey,
                        ]
                        );
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || empty($error) === false) {
            throw new RuntimeException('AI model connection failed: '.$error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('AI model returned HTTP '.$httpCode);
        }

        return $this->decodeAiModelResponse(response: $response);
    }//end callAiModel()

    /**
     * Decode the raw AI model HTTP body into the suggestion array.
     *
     * @param string $response The raw response body returned by the model
     *
     * @return array The parsed model response
     *
     * @throws \RuntimeException If the response body is not valid JSON
     */
    private function decodeAiModelResponse(string $response): array
    {
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('AI model returned invalid JSON');
        }

        // Parse the response text as JSON (we requested JSON format).
        $responseText = ($decoded['response'] ?? '');
        $parsed       = json_decode($responseText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If not JSON, return as plain text.
            return ['answer' => $responseText, 'confidence' => 0.0];
        }

        return $parsed;
    }//end decodeAiModelResponse()

    /**
     * Record an audit entry for a case-assistant-via-hermiq conversational
     * exchange, using the SAME audit sink (register/schema, append-only
     * OpenRegister write) every discrete AI operation in this class already
     * writes to — so the existing AI oversight trail
     * (`listAuditEntries()`/`AiAuditExportController`) covers the
     * conversational surface too, with no second audit mechanism.
     *
     * A thin public forwarder is needed (rather than widening
     * `recordAuditEntry()` itself to public) because the case-assistant
     * surface lives in `AssistantController`/`HermiqAssistantClient` — a
     * separate class per the fleet rule that AI functionality/LLM calls live
     * in Hermiq, not in this class. This method carries no LLM logic; it only
     * forwards an already-built entry to the existing writer.
     *
     * @param array $entry The audit entry data — same shape as the other
     *                     `recordAuditEntry()` call sites (`type`, `action`,
     *                     `caseId`, `model`, `prompt`, `suggestion`,
     *                     `confidence`, `userId`, `timestamp`, `responseTimeMs`).
     *
     * @return void
     *
     * @spec openspec/specs/case-assistant-via-hermiq/spec.md
     */
    public function recordAssistantAuditEntry(array $entry): void
    {
        $this->recordAuditEntry(entry: $entry);
    }//end recordAssistantAuditEntry()

    /**
     * Record an AI audit trail entry in OpenRegister.
     *
     * @param array $entry The audit entry data
     *
     * @return void
     */
    private function recordAuditEntry(array $entry): void
    {
        $this->audit->record(entry: $entry);
    }//end recordAuditEntry()
}//end class
