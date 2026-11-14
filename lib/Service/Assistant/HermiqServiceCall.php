<?php

/**
 * Dossiq HermiqServiceCall.
 *
 * The steps every Hermiq client of this app takes around one call with the
 * shared service account: refuse when Hermiq is absent, refuse (and log) when
 * the service account is not configured, post and refuse (and log) an
 * unreachable Hermiq, and decode the reply, refusing an undecodable or
 * non-2xx one with Hermiq's own message and error code.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One call to Hermiq on behalf of a named client, built by that client.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
final class HermiqServiceCall {
	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Start a call for a client.
	 *
	 * @param LoggerInterface $logger The client's logger.
	 * @param string          $caller The client's name, as its log lines start.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $caller,
	) {
	}//end __construct()

	/**
	 * Read the configured service-account credentials (one service account for
	 * every outbound Hermiq call this app makes).
	 *
	 * @param IAppConfig $appConfig The app config.
	 *
	 * @return array{0: string, 1: string} `[uid, appPassword]`.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function credentialsFrom(IAppConfig $appConfig): array {
		$uid = $appConfig->getValueString(AppIdentity::APP_ID, 'hermiq_service_uid', '');
		$pwd = $appConfig->getValueString(AppIdentity::APP_ID, 'hermiq_service_app_password', '');

		return [$uid, $pwd];
	}//end credentialsFrom()

	/**
	 * The options of a POST with the service account and a JSON body.
	 *
	 * @param array{0: string, 1: string} $credentials `[uid, appPassword]`.
	 * @param array<string, mixed>        $json        The body.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function postOptions(array $credentials, array $json): array {
		return [
			'timeout' => self::TIMEOUT_SECONDS,
			'auth' => [$credentials[0], $credentials[1]],
			'json' => $json,
			// We need the body + status on EVERY response (including 4xx/5xx)
			// to relay Hermiq's specific error mapping (400/403/404/422/502/503)
			// — never let the transport layer swallow it into a generic throw.
			'http_errors' => false,
			'headers' => ['Accept' => 'application/json'],
		];
	}//end postOptions()

	/**
	 * Refuse the call when Hermiq is not installed or enabled.
	 *
	 * @param bool $available Whether Hermiq is installed and enabled.
	 *
	 * @return void
	 *
	 * @throws HermiqAssistantException When it is not.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function requireAvailable(bool $available): void {
		if ($available === false) {
			throw new HermiqAssistantException(
				message: 'Hermiq is not installed or enabled on this instance',
				statusCode: 503
			);
		}
	}//end requireAvailable()

	/**
	 * Refuse the call, and log it, when the service account is not configured.
	 *
	 * @param array{0: string, 1: string} $credentials `[uid, appPassword]`.
	 *
	 * @return void
	 *
	 * @throws HermiqAssistantException When either half is empty.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function requireCredentials(array $credentials): void {
		if ($credentials[0] === '' || $credentials[1] === '') {
			$this->logger->warning(
				$this->caller . ': service-account credentials are not configured',
				['app' => AppIdentity::APP_ID]
			);
			throw new HermiqAssistantException(
				message: 'The Hermiq service-account credentials are not configured',
				statusCode: 503
			);
		}
	}//end requireCredentials()

	/**
	 * Send the POST; a transport failure is logged and refused as unreachable.
	 *
	 * @param IClientService $clients The HTTP client factory.
	 * @param HermiqPost     $post    The URL and options.
	 *
	 * @return IResponse
	 *
	 * @throws HermiqAssistantException When Hermiq cannot be reached.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function send(IClientService $clients, HermiqPost $post): IResponse {
		try {
			return $clients->newClient()->post($post->url, $post->options);
		} catch (Throwable $e) {
			throw $this->unreachable(url: $post->url, failure: $e);
		}
	}//end send()

	/**
	 * Log a transport failure and return the refusal to throw for it.
	 *
	 * @param string    $url     The URL the request went to.
	 * @param Throwable $failure What went wrong.
	 *
	 * @return HermiqAssistantException The `hermiq_unreachable` refusal.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function unreachable(string $url, Throwable $failure): HermiqAssistantException {
		$this->logger->warning(
			$this->caller . ': request failed',
			['app' => AppIdentity::APP_ID, 'url' => $url, 'error' => $failure->getMessage()]
		);

		return new HermiqAssistantException(message: 'hermiq_unreachable', statusCode: 503, previous: $failure);
	}//end unreachable()

	/**
	 * Decode a reply, translating a non-2xx status into a coded exception.
	 *
	 * @param IResponse $response The HTTP response.
	 *
	 * @return array<string, mixed> The decoded body of a 2xx reply.
	 *
	 * @throws HermiqAssistantException On a non-2xx status or an undecodable body.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function decoded(IResponse $response): array {
		$statusCode = $response->getStatusCode();
		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false) {
			throw new HermiqAssistantException(message: 'hermiq_invalid_response', statusCode: 502);
		}

		if ($statusCode < 200 || $statusCode >= 300) {
			throw new HermiqAssistantException(
				message: (string)($decoded['message'] ?? $decoded['error'] ?? 'hermiq_api_error'),
				statusCode: $statusCode,
				errorCode: $this->errorCodeOf(decoded: $decoded)
			);
		}

		return $decoded;
	}//end decoded()

	/**
	 * The list a decoded reply carries under a key, or [] when it carries none.
	 *
	 * @param array<string, mixed> $decoded The decoded reply.
	 * @param string               $key     The key (`usage`, `spans`).
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function listOf(array $decoded, string $key): array {
		if (is_array($decoded[$key] ?? null) === true) {
			return $decoded[$key];
		}

		return [];
	}//end listOf()

	/**
	 * Hermiq's stable error code on a refusal, when it sent one.
	 *
	 * @param array<string, mixed> $decoded The decoded refusal.
	 *
	 * @return string|null
	 */
	private function errorCodeOf(array $decoded): ?string {
		if (isset($decoded['errorCode']) === true) {
			return (string)$decoded['errorCode'];
		}

		return null;
	}//end errorCodeOf()
}//end class
