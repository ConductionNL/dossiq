<?php

/**
 * Dossiq Hermiq Assistant Client.
 *
 * The single thin HTTP boundary for every outbound call this app makes to
 * Hermiq's case-assistant surface (`POST /api/assistant/converse`). Hermiq is
 * called as a local peer app on the same Nextcloud instance via
 * OCP\Http\Client\IClientService — the same way LibresignApiClient calls
 * LibreSign and KvkApiAdapter/HaalCentraalBrpAdapter call third-party HTTP
 * APIs elsewhere in this app.
 *
 * FLEET RULE: AI functionality lives in Hermiq. This client carries NO
 * prompt-building, model selection, or LLM-calling logic of its own — it only
 * forwards an already-authorized message + context to Hermiq's endpoint and
 * relays the result. Dossiq's own `AiService` (classify/extract/ask/
 * summarize) is a separate, pre-existing discrete-operation surface and is
 * unaffected by this change.
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
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
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
 * Thin HTTP client for Hermiq's local case-assistant-surface API.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
class HermiqAssistantClient {
	/**
	 * Hermiq's minimal, tool-free conversational endpoint (case-assistant-surface).
	 *
	 * @var string
	 */
	private const CONVERSE_PATH = '/index.php/apps/hermiq/api/assistant/converse';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IURLGenerator $urlGenerator Resolves this Nextcloud instance's own base URL.
	 * @param IAppConfig $appConfig App config (service-account credentials).
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
	 * Whether Hermiq is installed and enabled for the current user — the
	 * gate the case-assistant UI panel is hidden behind when false (absent →
	 * hidden, not a broken/erroring panel).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser('hermiq');
	}//end isAvailable()

	/**
	 * Run one conversational turn against Hermiq's case-assistant surface.
	 *
	 * @param string|null $sessionId Hermiq conversation UUID from a prior turn, or null to start one.
	 * @param string $message The user's message text (already length-capped by the caller).
	 * @param array $context `{app, objectType, objectRef, contextData}` — the caller is
	 *                       responsible for ensuring `contextData` only contains fields
	 *                       the requesting user is authorized to see.
	 *
	 * @return array{sessionId: string, reply: string, usage: array<string, int|float>}
	 *
	 * @throws HermiqAssistantException On misconfiguration, transport failure, or any non-2xx
	 *                                  response from Hermiq (`getStatusCode()`/`getErrorCode()`
	 *                                  carry the mapped detail).
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function converse(?string $sessionId, string $message, array $context): array {
		$call = new HermiqServiceCall(logger: $this->logger, caller: 'HermiqAssistantClient');
		$call->requireAvailable(available: $this->isAvailable());
		$credentials = $call->credentialsFrom(appConfig: $this->appConfig);
		$call->requireCredentials(credentials: $credentials);

		$url = rtrim($this->urlGenerator->getBaseUrl(), '/') . self::CONVERSE_PATH;
		$payload = ['message' => $message, 'context' => $context];
		if ($sessionId !== null && $sessionId !== '') {
			$payload['sessionId'] = $sessionId;
		}
		$post = new HermiqPost(url: $url, options: $call->postOptions(credentials: $credentials, json: $payload));

		return $this->decodeResponse(call: $call, response: $call->send(clients: $this->clientService, post: $post));
	}//end converse()

	/**
	 * Decode a Hermiq response, translating a non-2xx status into a coded exception.
	 *
	 * @param HermiqServiceCall          $call     The call the response answers.
	 * @param \OCP\Http\Client\IResponse $response The HTTP response.
	 *
	 * @return array{sessionId: string, reply: string, usage: array<string, int|float>}
	 *
	 * @throws HermiqAssistantException On a non-2xx status or an undecodable body.
	 */
	private function decodeResponse(HermiqServiceCall $call, \OCP\Http\Client\IResponse $response): array {
		$decoded = $call->decoded(response: $response);

		return [
			'sessionId' => (string)($decoded['sessionId'] ?? ''),
			'reply' => (string)($decoded['reply'] ?? ''),
			'usage' => $call->listOf(decoded: $decoded, key: 'usage'),
		];
	}//end decodeResponse()
}//end class
