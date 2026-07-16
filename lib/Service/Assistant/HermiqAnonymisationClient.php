<?php

/**
 * Procest Hermiq Anonymisation Client.
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
 * @package  OCA\Procest\Service\Assistant
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Assistant;

use OCA\Procest\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin HTTP client for Hermiq's local structured PII-detection API.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
 */
class HermiqAnonymisationClient
{
    /**
     * Hermiq's structured, tool-free PII-span-detection endpoint
     * (woo-llm-anonymisation, hermiq side).
     *
     * @var string
     */
    private const DETECT_PII_PATH = '/index.php/apps/hermiq/api/assistant/detect-pii';

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService HTTP client factory.
     * @param IURLGenerator   $urlGenerator  Resolves this Nextcloud instance's own base URL.
     * @param IAppConfig      $appConfig     App config (service-account credentials — SAME
     *                                       `hermiq_service_uid`/`hermiq_service_app_password`
     *                                       pair `HermiqAssistantClient` uses).
     * @param IAppManager     $appManager    Resolves whether Hermiq is installed+enabled.
     * @param LoggerInterface $logger        Structured logger.
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
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
     */
    public function isAvailable(): bool
    {
        return $this->appManager->isEnabledForUser('hermiq');
    }//end isAvailable()

    /**
     * Run one structured PII-span detection call against Hermiq.
     *
     * @param string $text    The document text to scan (already length-capped by the caller).
     * @param array  $context `{app, objectType, objectRef}` — same shape `HermiqAssistantClient`
     *                        forwards.
     *
     * @return array{spans: array<int, array<string, mixed>>, usage: array<string, int|float>}
     *
     * @throws HermiqAssistantException On misconfiguration, transport failure, or any non-2xx
     *                                  response from Hermiq (reused — same coded-failure shape
     *                                  `HermiqAssistantClient` already throws).
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
     */
    public function detectPii(string $text, array $context): array
    {
        if ($this->isAvailable() === false) {
            throw new HermiqAssistantException(
                message: 'Hermiq is not installed or enabled on this instance',
                statusCode: 503
            );
        }

        [$serviceUid, $serviceAppPassword] = $this->serviceCredentials();
        if ($serviceUid === '' || $serviceAppPassword === '') {
            $this->logger->warning(
                'HermiqAnonymisationClient: service-account credentials are not configured',
                ['app' => Application::APP_ID]
            );
            throw new HermiqAssistantException(
                message: 'The Hermiq service-account credentials are not configured',
                statusCode: 503
            );
        }

        $url = rtrim($this->urlGenerator->getBaseUrl(), '/').self::DETECT_PII_PATH;

        $options = [
            'timeout'     => self::TIMEOUT_SECONDS,
            'auth'        => [$serviceUid, $serviceAppPassword],
            'json'        => ['text' => $text, 'context' => $context],
            // We need the body + status on EVERY response (including 4xx/5xx)
            // to relay Hermiq's specific error mapping (400/422/502/503) —
            // never let the transport layer swallow it into a generic throw.
            'http_errors' => false,
            'headers'     => ['Accept' => 'application/json'],
        ];

        try {
            $response = $this->clientService->newClient()->post($url, $options);
        } catch (Throwable $e) {
            $this->logger->warning(
                'HermiqAnonymisationClient: request failed',
                ['app' => Application::APP_ID, 'url' => $url, 'error' => $e->getMessage()]
            );
            throw new HermiqAssistantException(message: 'hermiq_unreachable', statusCode: 503, previous: $e);
        }

        return $this->decodeResponse(response: $response);
    }//end detectPii()

    /**
     * Decode a Hermiq response, translating a non-2xx status into a coded exception.
     *
     * @param \OCP\Http\Client\IResponse $response The HTTP response.
     *
     * @return array{spans: array<int, array<string, mixed>>, usage: array<string, int|float>}
     *
     * @throws HermiqAssistantException On a non-2xx status or an undecodable body.
     */
    private function decodeResponse(\OCP\Http\Client\IResponse $response): array
    {
        $statusCode = $response->getStatusCode();
        $decoded    = json_decode((string) $response->getBody(), true);
        if (is_array($decoded) === false) {
            throw new HermiqAssistantException(message: 'hermiq_invalid_response', statusCode: 502);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorCode = null;
            if (isset($decoded['errorCode']) === true) {
                $errorCode = (string) $decoded['errorCode'];
            }

            throw new HermiqAssistantException(
                message: (string) ($decoded['message'] ?? $decoded['error'] ?? 'hermiq_api_error'),
                statusCode: $statusCode,
                errorCode: $errorCode
            );
        }

        $spans = [];
        if (is_array($decoded['spans'] ?? null) === true) {
            $spans = $decoded['spans'];
        }

        $usage = [];
        if (is_array($decoded['usage'] ?? null) === true) {
            $usage = $decoded['usage'];
        }

        return ['spans' => $spans, 'usage' => $usage];
    }//end decodeResponse()

    /**
     * Read the configured service-account credentials — the SAME app-config
     * keys `HermiqAssistantClient` reads (one service account for every
     * outbound Hermiq call this app makes).
     *
     * @return array{0: string, 1: string} `[uid, appPassword]`.
     */
    private function serviceCredentials(): array
    {
        $uid = $this->appConfig->getValueString(Application::APP_ID, 'hermiq_service_uid', '');
        $pwd = $this->appConfig->getValueString(Application::APP_ID, 'hermiq_service_app_password', '');

        return [$uid, $pwd];
    }//end serviceCredentials()
}//end class
