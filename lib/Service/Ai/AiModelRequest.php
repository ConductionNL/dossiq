<?php

/**
 * Dossiq AiModelRequest.
 *
 * One outbound call to the configured AI model: the model settings, the
 * Ollama-compatible body, the endpoint guard's pinned address, the POST and
 * its options, the cloud API key, and the reply's status checks. Built by
 * AiService for each call, which keeps the call itself and the decoding.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\IAppConfig;
use RuntimeException;

/**
 * The steps of one call to the AI model.
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
final class AiModelRequest {
	/**
	 * Start a call with the app's settings and the endpoint guard.
	 *
	 * @param IAppConfig      $appConfig     The app config holding the model settings.
	 * @param AiEndpointGuard $endpointGuard The SSRF guard for the model URL.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AiEndpointGuard $endpointGuard,
	) {
	}//end __construct()

	/**
	 * One AI setting of this app, by key.
	 *
	 * @param string $key     The app config key.
	 * @param string $default The value when it is not stored.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function setting(string $key, string $default): string {
		return $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			$key,
			$default
		);
	}//end setting()

	/**
	 * The configured model URL.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When no model URL is configured.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function configuredModelUrl(): string {
		$modelUrl = $this->setting(key: 'ai_model_url', default: '');
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
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function generateRequestBody(string $modelName, string $prompt): string|false {
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
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function pinnedModelAddress(string $url, string $modelType): string {
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
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function openGenerateRequest(string $endpoint, string|false $payload): \CurlHandle {
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
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function sendCloudApiKey(\CurlHandle $ch, string $modelType): void {
		if ($modelType !== 'cloud') {
			return;
		}

		$apiKey = $this->setting(key: 'ai_api_key', default: '');
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
	 * Send the request and return the model's raw answer body.
	 *
	 * @param \CurlHandle $ch The prepared request.
	 *
	 * @return string The raw response body
	 *
	 * @throws \RuntimeException When the connection fails or the reply is not a 2xx.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function modelAnswer(\CurlHandle $ch): string {
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

		return $response;
	}//end modelAnswer()

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
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function pinnedResolveEntry(string $url, string $address): string {
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
}//end class
