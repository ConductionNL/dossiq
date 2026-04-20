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
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for AI-assisted case processing.
 *
 * All AI interactions follow the human-in-the-loop principle:
 * AI suggests, humans confirm. Every interaction is recorded
 * in the audit trail for Algoritmeregister compliance.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class AiService
{
    /**
     * Regex patterns for PII detection and stripping.
     *
     * @var array<string, string>
     */
    private const PII_PATTERNS = [
        'bsn'      => '/\b\d{9}\b/',
        'iban'     => '/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}\b/',
        'phone'    => '/\b(0\d{9}|\+31\d{9})\b/',
        'postcode' => '/\b\d{4}\s?[A-Z]{2}\b/',
    ];

    /**
     * Constructor for AiService.
     *
     * @param IAppConfig         $appConfig The app configuration service
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger interface
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if AI features are globally enabled.
     *
     * @return bool
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
     */
    public function classifyDocument(string $caseId, string $documentId, string $userId): array
    {
        if ($this->isFeatureEnabled('classification') === false) {
            return [
                'success' => false,
                'message' => 'AI document classification is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->buildClassificationPrompt($caseId, $documentId);
            $prompt = $this->stripPiiIfEnabled($prompt);

            $result = $this->callAiModel($prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    [
                        'type'           => 'classification',
                        'action'         => 'suggested',
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
     */
    public function extractData(string $caseId, ?string $documentId, string $userId): array
    {
        if ($this->isFeatureEnabled('extraction') === false) {
            return [
                'success' => false,
                'message' => 'AI data extraction is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->buildExtractionPrompt($caseId, $documentId);
            $prompt = $this->stripPiiIfEnabled($prompt);

            $result = $this->callAiModel($prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    [
                        'type'           => 'extraction',
                        'action'         => 'suggested',
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
     */
    public function askQuestion(string $caseId, string $question, string $userId): array
    {
        if ($this->isFeatureEnabled('qa') === false) {
            return [
                'success' => false,
                'message' => 'AI knowledge base Q&A is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->buildQaPrompt($caseId, $question);

            $result = $this->callAiModel($prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    [
                        'type'           => 'qa',
                        'action'         => 'suggested',
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
     */
    public function summarize(string $caseId, string $type, ?string $documentId, string $userId): array
    {
        if ($this->isFeatureEnabled('summary') === false) {
            return [
                'success' => false,
                'message' => 'AI summarization is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->buildSummaryPrompt($caseId, $type, $documentId);
            $prompt = $this->stripPiiIfEnabled($prompt);

            $result = $this->callAiModel($prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    [
                        'type'           => 'summary',
                        'action'         => 'suggested',
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
     */
    public function suggestRouting(string $caseId, string $userId): array
    {
        if ($this->isFeatureEnabled('routing') === false) {
            return [
                'success' => false,
                'message' => 'AI case routing is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->buildRoutingPrompt($caseId);

            $result = $this->callAiModel($prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    [
                        'type'           => 'routing',
                        'action'         => 'suggested',
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
     */
    public function suggestNextStep(string $caseId, string $userId): array
    {
        if ($this->isFeatureEnabled('decision_support') === false) {
            return [
                'success' => false,
                'message' => 'AI decision support is not enabled',
            ];
        }

        $startTime = microtime(true);

        try {
            $prompt = $this->buildNextStepPrompt($caseId);

            $result = $this->callAiModel($prompt);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->recordAuditEntry(
                    [
                        'type'           => 'decision_support',
                        'action'         => 'suggested',
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
                [
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
     * Test AI model connectivity.
     *
     * @return array Health check result
     */
    public function testHealth(): array
    {
        $startTime = microtime(true);

        try {
            $result = $this->callAiModel('Respond with "ok" to confirm connectivity.');

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

        foreach (self::PII_PATTERNS as $type => $pattern) {
            $prompt = preg_replace($pattern, '['.strtoupper($type).'_REMOVED]', $prompt);
        }

        return $prompt;
    }//end stripPiiIfEnabled()

    /**
     * Call the AI model with a prompt.
     *
     * Routes through n8n MCP workflow or directly to the model
     * depending on configuration.
     *
     * @param string $prompt The prompt to send
     *
     * @return array The AI model response
     *
     * @throws \RuntimeException If the AI model call fails
     */
    private function callAiModel(string $prompt): array
    {
        $modelUrl = $this->appConfig->getValueString(
            Application::APP_ID,
            'ai_model_url',
            ''
        );

        if (empty($modelUrl) === true) {
            throw new \RuntimeException('AI model URL is not configured');
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
            throw new \RuntimeException('AI model connection failed: '.$error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('AI model returned HTTP '.$httpCode);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('AI model returned invalid JSON');
        }

        // Parse the response text as JSON (we requested JSON format).
        $responseText = ($decoded['response'] ?? '');
        $parsed       = json_decode($responseText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If not JSON, return as plain text.
            return ['answer' => $responseText, 'confidence' => 0.0];
        }

        return $parsed;
    }//end callAiModel()

    /**
     * Record an AI audit trail entry in OpenRegister.
     *
     * @param array $entry The audit entry data
     *
     * @return void
     */
    private function recordAuditEntry(array $entry): void
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $registerId = $this->appConfig->getValueString(
                Application::APP_ID,
                'register',
                ''
            );
            $schemaId   = $this->appConfig->getValueString(
                Application::APP_ID,
                'ai_audit_entry_schema',
                ''
            );

            if (empty($registerId) === true || empty($schemaId) === true) {
                $this->logger->warning('AI audit: register or schema ID not configured');
                return;
            }

            $objectService->saveObject(
                register: $registerId,
                schema: $schemaId,
                object: $entry,
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to record AI audit entry',
                ['error' => $e->getMessage()]
            );
        }//end try
    }//end recordAuditEntry()

    /**
     * Build a classification prompt for the AI model.
     *
     * @param string $caseId     The case ID
     * @param string $documentId The document ID
     *
     * @return string The classification prompt
     */
    private function buildClassificationPrompt(string $caseId, string $documentId): string
    {
        return 'Classify the following document for case '.$caseId
            .'. Document ID: '.$documentId
            .'. Return JSON with fields: documentType (string), confidence (number 0-1), '
            .'metadata (object with date, sender, subject).';
    }//end buildClassificationPrompt()

    /**
     * Build a data extraction prompt for the AI model.
     *
     * @param string      $caseId     The case ID
     * @param string|null $documentId Optional document ID
     *
     * @return string The extraction prompt
     */
    private function buildExtractionPrompt(string $caseId, ?string $documentId): string
    {
        $prompt = 'Extract structured data from documents in case '.$caseId.'.';
        if ($documentId !== null) {
            $prompt .= ' Focus on document '.$documentId.'.';
        }

        $prompt .= ' Return JSON with fields: array of {name, value, confidence (0-1), source}.';

        return $prompt;
    }//end buildExtractionPrompt()

    /**
     * Build a Q&A prompt with case context.
     *
     * @param string $caseId   The case ID
     * @param string $question The user's question
     *
     * @return string The Q&A prompt
     */
    private function buildQaPrompt(string $caseId, string $question): string
    {
        return 'Answer the following question in the context of case '.$caseId
            .'. Question: '.$question
            .'. Return JSON with fields: answer (string), sources (array of {document, page, quote}), '
            .'confidence (number 0-1). '
            .'If no relevant information is found, return: '
            .'{"answer": "Geen relevante informatie gevonden in de kennisbank", "sources": [], "confidence": 0}.';
    }//end buildQaPrompt()

    /**
     * Build a summarization prompt.
     *
     * @param string      $caseId     The case ID
     * @param string      $type       Summary type
     * @param string|null $documentId Optional document ID
     *
     * @return string The summary prompt
     */
    private function buildSummaryPrompt(string $caseId, string $type, ?string $documentId): string
    {
        $prompt = 'Generate a '.$type.' summary for case '.$caseId.'.';
        if ($type === 'document' && $documentId !== null) {
            $prompt .= ' Summarize document '.$documentId.'.';
        }

        $prompt .= ' Return JSON with field: summary (string, 3-5 sentences in Dutch).';

        return $prompt;
    }//end buildSummaryPrompt()

    /**
     * Build a routing suggestion prompt.
     *
     * @param string $caseId The case ID
     *
     * @return string The routing prompt
     */
    private function buildRoutingPrompt(string $caseId): string
    {
        return 'Suggest the best case worker for case '.$caseId
            .' based on expertise and current workload. '
            .'Return JSON with fields: suggestions (array of {userId, name, reason, confidence}).';
    }//end buildRoutingPrompt()

    /**
     * Build a next-step suggestion prompt.
     *
     * @param string $caseId The case ID
     *
     * @return string The next-step prompt
     */
    private function buildNextStepPrompt(string $caseId): string
    {
        return 'Analyze the current state of case '.$caseId
            .' and suggest what the case worker should do next. '
            .'Return JSON with fields: suggestions (array of {action, reason, priority}).';
    }//end buildNextStepPrompt()
}//end class
