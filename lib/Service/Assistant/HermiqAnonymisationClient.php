<?php

/**
 * Dossiq Hermiq Anonymisation Client.
 *
 * The single thin HTTP boundary for every outbound call this app makes to
 * Hermiq's structured PII-span-detection surface
 * (`POST /api/assistant/detect-pii`, woo-llm-anonymisation). Hermiq is
 * called as a local peer app on the same Nextcloud instance via
 * OCP\Http\Client\IClientService — the SAME pattern `HermiqAssistantClient`
 * already establishes for the case-assistant-surface.
 *
 * FLEET RULE: AI functionality lives in Hermiq. This client carries NO
 * prompt-building, model selection, or LLM-calling logic of its own — it
 * only forwards an already-authorized document text + context to Hermiq's
 * endpoint and relays the result. `WOOAnonymisationAssistService` is the
 * ONLY caller; it merges this client's proposed spans with
 * `AiService::detectDeterministicPiiSpans()`'s deterministic "rules floor"
 * and never lets the LLM component remove a rule-detected span.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for Hermiq's local structured PII-detection API.
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
 */
class HermiqAnonymisationClient {
	/**
	 * Hermiq's structured, tool-free PII-span-detection endpoint
	 * (woo-llm-anonymisation, hermiq side).
	 *
	 * @var string
	 */
	private const DETECT_PII_PATH = '/index.php/apps/hermiq/api/assistant/detect-pii';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IURLGenerator $urlGenerator Resolves this Nextcloud instance's own base URL.
	 * @param IAppConfig $appConfig App config (service-account credentials — SAME
	 *                              `hermiq_service_uid`/`hermiq_service_app_password`
	 *                              pair `HermiqAssistantClient` uses).
	 * @param IAppManager $appManager Resolves whether Hermiq is installed+enabled.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether Hermiq is installed and enabled — the gate
	 * `WOOAnonymisationAssistService` checks before attempting an LLM-assisted
	 * proposal; absent means "fall back to rules-only", never an error.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser('hermiq');
	}//end isAvailable()

	/**
	 * Run one structured PII-span detection call against Hermiq.
	 *
	 * @param string $text The document text to scan (already length-capped by the caller).
	 * @param array $context `{app, objectType, objectRef}` — same shape `HermiqAssistantClient`
	 *                       forwards.
	 *
	 * @return array{spans: array<int, array<string, mixed>>, usage: array<string, int|float>}
	 *
	 * @throws HermiqAssistantException On misconfiguration, transport failure, or any non-2xx
	 *                                  response from Hermiq (reused — same coded-failure shape
	 *                                  `HermiqAssistantClient` already throws).
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function detectPii(string $text, array $context): array {
		$call = new HermiqServiceCall(logger: $this->logger, caller: 'HermiqAnonymisationClient');
		$call->requireAvailable(available: $this->isAvailable());
		$credentials = $call->credentialsFrom(appConfig: $this->appConfig);
		$call->requireCredentials(credentials: $credentials);

		$url = rtrim($this->urlGenerator->getBaseUrl(), '/') . self::DETECT_PII_PATH;
		$payload = ['text' => $text, 'context' => $context];
		$post = new HermiqPost(url: $url, options: $call->postOptions(credentials: $credentials, json: $payload));

		return $this->decodeResponse(call: $call, response: $call->send(clients: $this->clientService, post: $post));
	}//end detectPii()

	/**
	 * Decode a Hermiq response, translating a non-2xx status into a coded exception.
	 *
	 * @param HermiqServiceCall          $call     The call the response answers.
	 * @param \OCP\Http\Client\IResponse $response The HTTP response.
	 *
	 * @return array{spans: array<int, array<string, mixed>>, usage: array<string, int|float>}
	 *
	 * @throws HermiqAssistantException On a non-2xx status or an undecodable body.
	 */
	private function decodeResponse(HermiqServiceCall $call, \OCP\Http\Client\IResponse $response): array {
		$decoded = $call->decoded(response: $response);

		return [
			'spans' => $call->listOf(decoded: $decoded, key: 'spans'),
			'usage' => $call->listOf(decoded: $decoded, key: 'usage'),
		];
	}//end decodeResponse()
}//end class
