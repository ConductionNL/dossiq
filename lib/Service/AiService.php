<?php

/**
 * Dossiq AI Service
 *
 * Service for AI-assisted case processing. Orchestrates AI model calls
 * via n8n MCP workflows for document classification, data extraction,
 * knowledge base Q&A, summarization, and routing suggestions.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use OCA\Dossiq\Service\Ai\AiModelIdentity;
use OCA\Dossiq\Service\Ai\AiPiiRedactor;
use OCA\Dossiq\Service\Ai\AiPromptFactory;
use OCA\Dossiq\Service\Ai\AiRequest;
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
 * The oversight surface — recording what a human did with a suggestion,
 * recording a conversational assistant exchange, and reading the trail back —
 * lives in {@see \OCA\Dossiq\Service\Ai\AiAuditService}.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
 */
class AiService {
	/**
	 * Per operation: the label of its unavailable message, the log line and the
	 * answer prefix of a failure, the result key recorded as confidence (null:
	 * none), the answer keys with their defaults (null: the whole result as
	 * `suggestion`), and whether the shaped answer is recorded as the
	 * suggestion and the whole subject is logged on failure.
	 */
	private const OPERATIONS = [
		'classification' => [
			'label' => 'AI document classification',
			'log' => 'AI classification failed',
			'fail' => 'AI classification failed',
			'confidence' => 'confidence',
			'answer' => null,
			'logSubject' => true,
		],
		'extraction' => [
			'label' => 'AI data extraction',
			'log' => 'AI extraction failed',
			'fail' => 'AI data extraction failed',
			'confidence' => 'averageConfidence',
			'answer' => ['fields' => []],
		],
		'qa' => [
			'label' => 'AI knowledge base Q&A',
			'log' => 'AI Q&A failed',
			'fail' => 'AI Q&A failed',
			'confidence' => 'confidence',
			'answer' => ['answer' => '', 'sources' => []],
		],
		'summary' => [
			'label' => 'AI summarization',
			'log' => 'AI summarization failed',
			'fail' => 'AI summarization failed',
			'confidence' => null,
			'answer' => ['summary' => ''],
			'suggestionIsAnswer' => true,
		],
		'routing' => [
			'label' => 'AI case routing',
			'log' => 'AI routing suggestion failed',
			'fail' => 'AI routing suggestion failed',
			'confidence' => 'confidence',
			'answer' => ['suggestions' => []],
		],
		'decision_support' => [
			'label' => 'AI decision support',
			'log' => 'AI next-step suggestion failed',
			'fail' => 'AI next-step suggestion failed',
			'confidence' => null,
			'answer' => ['suggestions' => []],
		],
	];

	/**
	 * Constructor for AiService.
	 *
	 * @param IAppConfig $appConfig The app configuration service
	 * @param AiPromptFactory $prompts The prompt templates
	 * @param AiPiiRedactor $pii The PII detector / scrubber
	 * @param AiEndpointGuard $endpointGuard The model-URL SSRF guard
	 * @param AiAuditLog $audit The oversight audit trail
	 * @param AiModelIdentity $modelIdentity The configured model identifier
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private AiPromptFactory $prompts,
		private AiPiiRedactor $pii,
		private AiEndpointGuard $endpointGuard,
		private AiAuditLog $audit,
		private AiModelIdentity $modelIdentity,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check if AI features are globally enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isEnabled(): bool {
		return $this->flagEnabled(key: 'ai_enabled');
	}//end isEnabled()

	/**
	 * Read one stored on/off flag as a boolean.
	 *
	 * The ONE place `'1'` is compared against a stored setting. Every flag in
	 * this subsystem is written by {@see \OCA\Dossiq\Service\SettingsService::updateSettings()}
	 * as `(string)` of a JSON boolean — so `true` becomes `'1'` and `false`
	 * becomes `''` — and every reader must agree on that encoding. It drifted
	 * once already: the admin tab read a differently-shaped response and fell
	 * back to hard-coded `true` defaults, so a stored `''` displayed as ON.
	 *
	 * @param string $key The app-config key.
	 * @param string $default The stored default when the key is unset.
	 *
	 * @return bool True when the flag is stored as enabled.
	 */
	private function flagEnabled(string $key, string $default = ''): bool {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			$key,
			$default
		) === '1';
	}//end flagEnabled()

	/**
	 * Whether the DPIA acknowledgement required before any AI feature runs has
	 * been recorded.
	 *
	 * @return bool True when an administrator has acknowledged the DPIA.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function isDpiaAcknowledged(): bool {
		return $this->flagEnabled(key: 'ai_dpia_acknowledged');
	}//end isDpiaAcknowledged()

	/**
	 * Check if a specific AI feature is enabled.
	 *
	 * Three conditions, all required: AI is on globally, the DPIA has been
	 * acknowledged, and this specific feature is on.
	 *
	 * The DPIA condition is enforced HERE rather than at each call site because
	 * this method is the single chokepoint every AI operation already passes
	 * through. It previously gated nothing at all: `ai_dpia_acknowledged` was
	 * written by the admin tab and read back for display, and no PHP ever
	 * consulted it, while the tab told the administrator "this must be
	 * acknowledged before AI features can be activated". The claim is now true.
	 *
	 * The operational consequence is deliberate and stated in the release notes:
	 * an instance that has AI enabled WITHOUT the acknowledgement stops serving
	 * AI features until an administrator ticks the box. That is one toggle on
	 * the page that already carries the warning, and every blocked call returns
	 * a message naming the reason. It is not grandfathered — recording an
	 * acknowledgement on an administrator's behalf would be the same lie in the
	 * other direction.
	 *
	 * @param string $feature The feature name (classification, extraction, qa, summary, routing, decision_support)
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isFeatureEnabled(string $feature): bool {
		if ($this->isEnabled() === false) {
			return false;
		}

		if ($this->isDpiaAcknowledged() === false) {
			return false;
		}

		return $this->flagEnabled(key: 'ai_feature_' . $feature);
	}//end isFeatureEnabled()

	/**
	 * The reason an AI operation is unavailable, phrased for the caller.
	 *
	 * Separates "the administrator has not acknowledged the DPIA" from "this
	 * feature is switched off", so a blocked call says which toggle to reach
	 * for instead of reporting a generic refusal.
	 *
	 * @param string $label The human-readable operation name.
	 *
	 * @return string The refusal message.
	 */
	private function unavailableMessage(string $label): string {
		if ($this->isEnabled() === true && $this->isDpiaAcknowledged() === false) {
			return 'AI features are unavailable until the DPIA is acknowledged in the Dossiq AI settings';
		}

		return $label . ' is not enabled';
	}//end unavailableMessage()

	/**
	 * Classify a document using AI.
	 *
	 * Sends document content to the configured AI model for classification.
	 * Returns a suggested document type with confidence score.
	 *
	 * @param string $caseId The case ID
	 * @param string $documentId The document ID to classify
	 * @param string $userId The current user ID
	 *
	 * @return array Classification result with suggestion and confidence
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function classifyDocument(string $caseId, string $documentId, string $userId): array {
		return $this->operate(
			request: new AiRequest(
				type: 'classification',
				subject: ['caseId' => $caseId, 'documentId' => $documentId],
				userId: $userId,
				prompt: fn (): string => $this->prompts->classification(caseId: $caseId, documentId: $documentId),
			)
		);
	}//end classifyDocument()

	/**
	 * Extract structured data from case documents using AI.
	 *
	 * @param string $caseId The case ID
	 * @param string|null $documentId Optional specific document ID
	 * @param string $userId The current user ID
	 *
	 * @return array Extraction result with field suggestions and confidence
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extractData(string $caseId, ?string $documentId, string $userId): array {
		return $this->operate(
			request: new AiRequest(
				type: 'extraction',
				subject: ['caseId' => $caseId, 'documentId' => ($documentId ?? '')],
				userId: $userId,
				prompt: fn (): string => $this->prompts->extraction(caseId: $caseId, documentId: $documentId),
			)
		);
	}//end extractData()

	/**
	 * Ask a knowledge base question in case context.
	 *
	 * @param string $caseId The case ID for context
	 * @param string $question The user's question
	 * @param string $userId The current user ID
	 *
	 * @return array Answer with source citations
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function askQuestion(string $caseId, string $question, string $userId): array {
		// The one prompt carrying free text a person typed: the audit entry
		// records it as scrubbed, never the raw question.
		return $this->operate(
			request: new AiRequest(
				type: 'qa',
				subject: ['caseId' => $caseId],
				userId: $userId,
				prompt: fn (): string => $this->prompts->question(caseId: $caseId, question: $question),
			)
		);
	}//end askQuestion()

	/**
	 * Generate a summary for a case, document, or timeline.
	 *
	 * @param string $caseId The case ID
	 * @param string $type Summary type: case, document, or timeline
	 * @param string|null $documentId Optional document ID for document summaries
	 * @param string $userId The current user ID
	 *
	 * @return array Summary text
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function summarize(string $caseId, string $type, ?string $documentId, string $userId): array {
		return $this->operate(
			request: new AiRequest(
				type: 'summary',
				subject: ['caseId' => $caseId, 'documentId' => ($documentId ?? '')],
				userId: $userId,
				prompt: fn (): string => $this->prompts->summary(caseId: $caseId, type: $type, documentId: $documentId),
			)
		);
	}//end summarize()

	/**
	 * Suggest case routing based on expertise and workload.
	 *
	 * @param string $caseId The case ID
	 * @param string $userId The current user ID
	 *
	 * @return array Routing suggestion with recommended case worker
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestRouting(string $caseId, string $userId): array {
		return $this->operate(
			request: new AiRequest(
				type: 'routing',
				subject: ['caseId' => $caseId],
				userId: $userId,
				prompt: fn (): string => $this->prompts->routing(caseId: $caseId),
			)
		);
	}//end suggestRouting()

	/**
	 * Suggest next steps for a case based on current state.
	 *
	 * @param string $caseId The case ID
	 * @param string $userId The current user ID
	 *
	 * @return array Next-step suggestions
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestNextStep(string $caseId, string $userId): array {
		return $this->operate(
			request: new AiRequest(
				type: 'decision_support',
				subject: ['caseId' => $caseId],
				userId: $userId,
				prompt: fn (): string => $this->prompts->nextStep(caseId: $caseId),
			)
		);
	}//end suggestNextStep()

	/**
	 * Test AI model connectivity.
	 *
	 * The result key is `healthy`, and BOTH branches carry a `message`. It used
	 * to be `success`, with a `message` only on the error branch, while the one
	 * caller — the admin tab's connection test — read `healthy` and `message`.
	 * A working model therefore rendered as a red note card with no text in it:
	 * `healthy` was undefined, so the ternary picked `error`, and there was no
	 * message to print. Renamed rather than aliased, because a second key
	 * meaning the same thing is how the two halves drifted apart in the first
	 * place.
	 *
	 * @return array{healthy: bool, status: string, model: string, message: string, responseTimeMs: int}
	 *                                                                                                   Health check result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function testHealth(): array {
		$startTime = microtime(true);

		try {
			// The call itself is the health probe; its payload is irrelevant.
			$this->callAiModel(prompt: 'Respond with "ok" to confirm connectivity.');

			return [
				'healthy' => true,
				'status' => 'connected',
				'model' => $this->getModelIdentifier(),
				'message' => 'Connected to ' . $this->getModelIdentifier(),
				'responseTimeMs' => (int)((microtime(true) - $startTime) * 1000),
			];
		} catch (\Exception $e) {
			return [
				'healthy' => false,
				'status' => 'error',
				'model' => $this->getModelIdentifier(),
				'message' => $e->getMessage(),
				'responseTimeMs' => (int)((microtime(true) - $startTime) * 1000),
			];
		}//end try
	}//end testHealth()

	/**
	 * Get AI settings for the frontend.
	 *
	 * @return array AI settings (without sensitive data like API keys)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAiSettings(): array {
		return [
			'ai_enabled' => $this->isEnabled(),
			'ai_model_type' => $this->appConfig->getValueString(Application::APP_ID, 'ai_model_type', 'local'),
			'ai_model_url' => $this->appConfig->getValueString(Application::APP_ID, 'ai_model_url', ''),
			'ai_model_name' => $this->appConfig->getValueString(Application::APP_ID, 'ai_model_name', ''),
			'ai_api_key_set' => $this->appConfig->getValueString(Application::APP_ID, 'ai_api_key', '') !== '',
			'ai_feature_classification' => $this->flagEnabled(key: 'ai_feature_classification'),
			'ai_feature_extraction' => $this->flagEnabled(key: 'ai_feature_extraction'),
			'ai_feature_qa' => $this->flagEnabled(key: 'ai_feature_qa'),
			'ai_feature_summary' => $this->flagEnabled(key: 'ai_feature_summary'),
			'ai_feature_routing' => $this->flagEnabled(key: 'ai_feature_routing'),
			'ai_feature_decision_support' => $this->flagEnabled(key: 'ai_feature_decision_support'),
			'ai_dpia_acknowledged' => $this->isDpiaAcknowledged(),
			'ai_pii_stripping' => $this->flagEnabled(key: 'ai_pii_stripping', default: '1'),
		];
	}//end getAiSettings()

	/**
	 * Get the configured AI model identifier.
	 *
	 * @return string
	 */
	private function getModelIdentifier(): string {
		return $this->modelIdentity->identifier();
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
	 *                                                                                 Spans sorted by `start`, ascending.
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
	 */
	public function detectDeterministicPiiSpans(string $text): array {
		return $this->pii->detectSpans(text: $text);
	}//end detectDeterministicPiiSpans()

	/**
	 * Strip PII from prompt text if PII stripping is enabled.
	 *
	 * @param string $prompt The prompt text
	 *
	 * @return string The prompt with PII replaced
	 */
	private function stripPiiIfEnabled(string $prompt): string {
		if ($this->flagEnabled(key: 'ai_pii_stripping', default: '1') === false) {
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
	 * than mocking curl — see {@see \OCA\Dossiq\Tests\Unit\Service\AiServiceAuditLoggingCompletenessTest}
	 * which asserts every suggestion-time operation records an audit entry
	 * without making a real HTTP call.
	 *
	 * @param string $prompt The prompt to send
	 *
	 * @return array The AI model response
	 *
	 * @throws \RuntimeException If the AI model call fails
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	protected function callAiModel(string $prompt): array {
		$modelUrl = $this->configuredModelUrl();
		$modelName = $this->aiSetting(key: 'ai_model_name', default: 'llama3.1');
		$modelType = $this->aiSetting(key: 'ai_model_type', default: 'local');
		$payload = $this->generateRequestBody(modelName: $modelName, prompt: $prompt);
		$endpoint = rtrim($modelUrl, '/') . '/api/generate';
		$pinnedAddress = $this->pinnedModelAddress(url: $modelUrl, modelType: $modelType);
		$ch = $this->openGenerateRequest(endpoint: $endpoint, payload: $payload);

		// Connect to the address the guard INSPECTED, not to whatever the name
		// resolves to now. Without this, the guard's answer and curl's own
		// lookup are two separate resolutions of the same name and only the
		// second one carries the request — which is the whole of a DNS
		// rebinding attack. The Host header and TLS SNI still carry the
		// configured name, so certificate validation is unaffected.
		curl_setopt($ch, CURLOPT_RESOLVE, [$this->pinnedResolveEntry(url: $modelUrl, address: $pinnedAddress)]);
		$this->sendCloudApiKey(ch: $ch, modelType: $modelType);

		return $this->readModelAnswer(ch: $ch);
	}//end callAiModel()

	/**
	 * One AI setting of this app, by key.
	 *
	 * @param string $key     The app config key.
	 * @param string $default The value when it is not stored.
	 *
	 * @return string
	 */
	private function aiSetting(string $key, string $default): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			$key,
			$default
		);
	}//end aiSetting()

	/**
	 * The configured model URL.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When no model URL is configured.
	 */
	private function configuredModelUrl(): string {
		$modelUrl = $this->aiSetting(key: 'ai_model_url', default: '');
		if (empty($modelUrl) === true) {
			throw new RuntimeException('AI model URL is not configured');
		}

		return $modelUrl;
	}//end configuredModelUrl()

	/**
	 * The request payload for the Ollama-compatible generate API.
	 *
	 * @param string $modelName The model to ask.
	 * @param string $prompt    The prompt to send.
	 *
	 * @return string|false The JSON body.
	 */
	private function generateRequestBody(string $modelName, string $prompt): string|false {
		return json_encode(
			[
				'model' => $modelName,
				'prompt' => $prompt,
				'stream' => false,
				'format' => 'json',
			]
		);
	}//end generateRequestBody()

	/**
	 * SSRF guard: resolve the configured model URL to the ONE address it may
	 * be connected to, before making any outbound request.
	 *
	 * @param string $url       The configured model URL.
	 * @param string $modelType `local` or `cloud`.
	 *
	 * @return string The address the request is pinned to.
	 *
	 * @throws \RuntimeException When the guard refuses the URL.
	 */
	private function pinnedModelAddress(string $url, string $modelType): string {
		$pinnedAddress = $this->endpointGuard->resolveSafeAddress(url: $url, modelType: $modelType);
		if ($pinnedAddress === null) {
			throw new RuntimeException('AI model URL failed SSRF security check');
		}

		return $pinnedAddress;
	}//end pinnedModelAddress()

	/**
	 * A JSON POST to the generate endpoint, not yet sent.
	 *
	 * @param string       $endpoint The generate endpoint.
	 * @param string|false $payload  The JSON body.
	 *
	 * @return \CurlHandle
	 */
	private function openGenerateRequest(string $endpoint, string|false $payload): \CurlHandle {
		$ch = curl_init($endpoint);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);

		return $ch;
	}//end openGenerateRequest()

	/**
	 * Add the API key for cloud models, when one is stored.
	 *
	 * @param \CurlHandle $ch        The request.
	 * @param string      $modelType `local` or `cloud`.
	 *
	 * @return void
	 */
	private function sendCloudApiKey(\CurlHandle $ch, string $modelType): void {
		if ($modelType !== 'cloud') {
			return;
		}

		$apiKey = $this->aiSetting(key: 'ai_api_key', default: '');
		if (empty($apiKey) === false) {
			curl_setopt(
				$ch,
				CURLOPT_HTTPHEADER,
				[
					'Content-Type: application/json',
					'Authorization: Bearer ' . $apiKey,
				]
			);
		}
	}//end sendCloudApiKey()

	/**
	 * Send the request and decode the model's answer.
	 *
	 * @param \CurlHandle $ch The prepared request.
	 *
	 * @return array The AI model response
	 *
	 * @throws \RuntimeException When the connection fails or the reply is not a 2xx.
	 */
	private function readModelAnswer(\CurlHandle $ch): array {
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false || empty($error) === false) {
			throw new RuntimeException('AI model connection failed: ' . $error);
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			throw new RuntimeException('AI model returned HTTP ' . $httpCode);
		}

		return $this->decodeAiModelResponse(response: $response);
	}//end readModelAnswer()

	/**
	 * Build the `CURLOPT_RESOLVE` entry that pins one host:port to one address.
	 *
	 * The port has to be stated explicitly because `CURLOPT_RESOLVE` matches on
	 * `host:port`, and an entry naming the wrong port is simply ignored — the
	 * pin would silently do nothing and curl would resolve the name itself.
	 * A URL without a port takes the scheme's default.
	 *
	 * @param string $url The configured model URL.
	 * @param string $address The address the guard approved.
	 *
	 * @return string The `host:port:address` entry.
	 */
	private function pinnedResolveEntry(string $url, string $address): string {
		$parsed = parse_url($url);
		$host = strtolower(trim(($parsed['host'] ?? ''), '[]'));

		$port = ($parsed['port'] ?? null);
		if ($port === null) {
			$port = 80;
			if (strtolower($parsed['scheme'] ?? '') === 'https') {
				$port = 443;
			}
		}

		return $host . ':' . $port . ':' . $address;
	}//end pinnedResolveEntry()

	/**
	 * Decode the raw AI model HTTP body into the suggestion array.
	 *
	 * @param string $response The raw response body returned by the model
	 *
	 * @return array The parsed model response
	 *
	 * @throws \RuntimeException If the response body is not valid JSON
	 */
	private function decodeAiModelResponse(string $response): array {
		$decoded = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('AI model returned invalid JSON');
		}

		// Parse the response text as JSON (we requested JSON format).
		$responseText = ($decoded['response'] ?? '');
		$parsed = json_decode($responseText, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			// If not JSON, return as plain text.
			return ['answer' => $responseText, 'confidence' => 0.0];
		}

		return $parsed;
	}//end decodeAiModelResponse()

	/**
	 * Record an AI audit trail entry in OpenRegister.
	 *
	 * @param array $entry The audit entry data
	 *
	 * @return void
	 */
	private function recordAuditEntry(array $entry): void {
		$this->audit->record(entry: $entry);
	}//end recordAuditEntry()

	/**
	 * Run one AI operation: gate it, scrub its prompt, call the model, record
	 * the suggestion or the failure, and answer the caller.
	 *
	 * The prompt is built and scrubbed before the model call, so a failure
	 * entry records what was actually sent.
	 *
	 * @param AiRequest $request The operation as asked.
	 *
	 * @return array<string, mixed> The answer, or `success: false` with a message.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	private function operate(AiRequest $request): array {
		$operation = self::OPERATIONS[$request->type];
		if ($this->isFeatureEnabled(feature: $request->type) === false) {
			return [
				'success' => false,
				'message' => $this->unavailableMessage(label: $operation['label']),
			];
		}

		$exchange = [
			'prompt' => $this->stripPiiIfEnabled(prompt: ($request->prompt)()),
			'startTime' => microtime(true),
		];

		try {
			$exchange['result'] = $this->callAiModel(prompt: $exchange['prompt']);
			$exchange['responseTimeMs'] = $this->elapsedMs(startTime: $exchange['startTime']);
			$this->recordAuditEntry(entry: $this->suggestionEntry(request: $request, exchange: $exchange));

			return ['success' => true] + $this->answerOf(type: $request->type, result: $exchange['result']);
		} catch (\Exception $e) {
			$this->recordFailure(request: $request, exchange: $exchange + ['error' => $e->getMessage()]);

			return [
				'success' => false,
				'message' => $operation['fail'] . ': ' . $e->getMessage(),
			];
		}//end try
	}//end operate()

	/**
	 * What an operation answers for a model result.
	 *
	 * @param string               $type   The operation.
	 * @param array<string, mixed> $result The model result.
	 *
	 * @return array<string, mixed> The answer keys, defaulted when the result lacks them.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	private function answerOf(string $type, array $result): array {
		$shape = self::OPERATIONS[$type]['answer'];
		if ($shape === null) {
			return ['suggestion' => $result];
		}

		$answer = [];
		foreach ($shape as $key => $default) {
			$answer[$key] = ($result[$key] ?? $default);
		}

		return $answer;
	}//end answerOf()

	/**
	 * The audit entry of a suggestion the model made.
	 *
	 * @param AiRequest            $request  The operation as asked.
	 * @param array<string, mixed> $exchange The prompt sent, the result and the response time.
	 *
	 * @return array<string, mixed> The entry, in the trail's field order.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	private function suggestionEntry(AiRequest $request, array $exchange): array {
		$operation = self::OPERATIONS[$request->type];
		$suggestion = $exchange['result'];
		if (($operation['suggestionIsAnswer'] ?? false) === true) {
			$suggestion = $this->answerOf(type: $request->type, result: $suggestion);
		}

		$entry = ['type' => $request->type, 'action' => 'suggestion']
			+ $request->head(model: $this->getModelIdentifier(), prompt: $exchange['prompt'])
			+ ['suggestion' => $suggestion];
		if ($operation['confidence'] !== null) {
			$entry['confidence'] = ($exchange['result'][$operation['confidence']] ?? 0.0);
		}

		return $entry + [
			'userId' => $request->userId,
			'timestamp' => date('c'),
			'responseTimeMs' => $exchange['responseTimeMs'],
		];
	}//end suggestionEntry()

	/**
	 * Milliseconds since a microtime() start.
	 *
	 * @param float $startTime The start.
	 *
	 * @return int The elapsed milliseconds.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	private function elapsedMs(float $startTime): int {
		return (int)((microtime(true) - $startTime) * 1000);
	}//end elapsedMs()

	/**
	 * Log a failed AI attempt AND record it in the oversight trail.
	 *
	 * The trail used to be reachable only from the success path: all six
	 * operations logged the exception and returned, so an `aiAuditEntry` existed
	 * for every call that worked and for none that did not. "What did this thing
	 * try to do" is the question an audit trail exists to answer, and a trail of
	 * successes cannot answer it — a model that refused, timed out or was
	 * unreachable left no record that it had been asked at all.
	 *
	 * The entry carries `action: 'failed'` and the exception message in `error`;
	 * `type`, `userId` and `timestamp` are the schema's required fields, and the
	 * caller supplies the first two. Recording is best-effort by construction:
	 * {@see AiAuditLog::record()} swallows its own failures, so an unavailable
	 * audit sink can never turn a handled AI error into a 500.
	 *
	 * @param AiRequest            $request  The operation as asked.
	 * @param array<string, mixed> $exchange The prompt sent, the start time and the error.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	private function recordFailure(AiRequest $request, array $exchange): void {
		$operation = self::OPERATIONS[$request->type];
		$entry = $request->head(model: $this->getModelIdentifier(), prompt: $exchange['prompt']) + [
			'error' => $exchange['error'],
			'userId' => $request->userId,
			'responseTimeMs' => $this->elapsedMs(startTime: $exchange['startTime']),
		];

		$logContext = ['caseId' => $request->subject['caseId']];
		if (($operation['logSubject'] ?? false) === true) {
			$logContext = $request->subject;
		}

		$this->logger->error($operation['log'], $logContext + ['error' => $exchange['error']]);

		$this->recordAuditEntry(
			entry: array_merge(
				$entry,
				[
					'action' => 'failed',
					'timestamp' => date('c'),
				]
			)
		);
	}//end recordFailure()
}//end class
