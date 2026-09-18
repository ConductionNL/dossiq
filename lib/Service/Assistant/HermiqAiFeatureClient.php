<?php

/**
 * Dossiq HermiqAiFeatureClient.
 *
 * The read side of hermiq's AI-feature contracts, and the two calls that go with
 * them: which provider each feature will use and where it runs, which prompts the
 * assistant offers on a record, and which group an incoming report belongs to.
 *
 * It mirrors `HermiqAssistantClient` exactly — same service-account credentials,
 * same `http_errors: false` so hermiq's own status and wording survive the
 * transport, same exception type. A second transport style beside that one would
 * be a second place to get the credential handling wrong.
 *
 * What it does NOT do is decide anything. Dossiq declares which features are on
 * and renders what comes back; hermiq keeps the logic and the provider choice. In
 * particular there is no provider, model or residency here to set: a second place
 * to answer "which model saw this case, and in which jurisdiction" is a second
 * answer, and a functionaris gegevensbescherming given two has none.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-provider-and-the-place-are-read-from-hermiq-never-set-in-dossiq-req-aic-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads hermiq's AI-feature register, prompt library and report grouping.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md
 */
class HermiqAiFeatureClient {

	/**
	 * Which provider each registered feature will use, and where that provider
	 * runs (hermiq#896).
	 *
	 * @var string
	 */
	private const RESIDENCY_PATH = '/index.php/apps/hermiq/api/ai-features/residency';

	/**
	 * The prompts the assistant offers on a record type (hermiq#899).
	 *
	 * @var string
	 */
	private const PROMPTS_PATH = '/index.php/apps/hermiq/api/assistant-prompts';

	/**
	 * Which group an incoming report belongs to (hermiq#900).
	 *
	 * @var string
	 */
	private const GROUPING_PATH = '/index.php/apps/hermiq/api/report-similarity/evaluate';

	/**
	 * The same timeout the assistant client uses.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IURLGenerator $urlGenerator Resolves this instance's base URL.
	 * @param IAppConfig $appConfig Holds the hermiq service-account credentials.
	 * @param IAppManager $appManager Tells whether hermiq is there at all.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
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
	 * Whether hermiq is installed and enabled for this user.
	 *
	 * @return bool True when hermiq is available.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-without-hermiq-the-features-read-unavailable-rather-than-local
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser('hermiq');
	}//end isAvailable()

	/**
	 * Which provider each feature will use and where it runs, keyed by feature
	 * slug.
	 *
	 * An absent hermiq returns an empty map rather than throwing, because the
	 * caller's job is to report each declared feature as unavailable. What it must
	 * never do is report it as local: "we could not ask" and "it runs here" are
	 * different answers and only one of them is safe to act on.
	 *
	 * @return array<string, array<string, mixed>> The rows, by feature slug.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-case-type-screen-shows-where-each-feature-runs
	 */
	public function featureResidency(): array {
		if ($this->isAvailable() === false) {
			return [];
		}

		try {
			$decoded = $this->get(path: self::RESIDENCY_PATH);
		} catch (HermiqAssistantException $e) {
			$this->logger->warning(
				'HermiqAiFeatureClient: could not read the AI feature residency',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);

			return [];
		}

		$rows = [];
		foreach (($decoded['results'] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$slug = (string)($row['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$rows[$slug] = $row;
		}

		return $rows;
	}//end featureResidency()

	/**
	 * The prompts hermiq holds for one record type, in the administrator's order.
	 *
	 * Dossiq holds no prompt text of its own for anything the assistant offers on
	 * a case. A locally cached copy would render without a round trip and be a
	 * prompt an administrator cannot edit.
	 *
	 * @param string $scope The record type the surface is open on.
	 *
	 * @return array<int, array<string, mixed>> The prompts.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-prompts-offered-on-a-case-come-from-hermiq
	 */
	public function prompts(string $scope): array {
		if ($this->isAvailable() === false) {
			return [];
		}

		try {
			$decoded = $this->get(path: self::PROMPTS_PATH . '?scope=' . rawurlencode($scope));
		} catch (HermiqAssistantException $e) {
			$this->logger->warning(
				'HermiqAiFeatureClient: could not read the prompt library',
				['app' => Application::APP_ID, 'scope' => $scope, 'error' => $e->getMessage()]
			);

			return [];
		}

		$prompts = ($decoded['results'] ?? []);
		if (is_array($prompts) === false) {
			return [];
		}

		return array_values(array_filter($prompts, static fn (mixed $row): bool => is_array($row)));
	}//end prompts()

	/**
	 * Which group an incoming report belongs to.
	 *
	 * Dossiq asks and renders. It scores no similarity of its own: the
	 * deterministic key it passes is its own knowledge of the report, and
	 * everything else is hermiq's judgement.
	 *
	 * @param string $reportId The report's own identifier.
	 * @param string $reportType The report type, as dossiq names it.
	 * @param string $text What the reporter wrote.
	 * @param string $deterministicKey Dossiq's own key for this report, when it has one.
	 *
	 * @return array<string, mixed> The group, the count and the reasons.
	 *
	 * @throws HermiqAssistantException When hermiq is absent, misconfigured or refuses.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-two-hundred-reports-read-as-one-item-with-a-count
	 */
	public function groupFor(
		string $reportId,
		string $reportType,
		string $text,
		string $deterministicKey = '',
	): array {
		return $this->post(
			path: self::GROUPING_PATH,
			payload: [
				'reportId' => $reportId,
				'reportType' => $reportType,
				'text' => $text,
				'deterministicKey' => $deterministicKey,
			]
		);

	}//end groupFor()

	/**
	 * One GET against hermiq.
	 *
	 * @param string $path The path, already query-encoded.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws HermiqAssistantException On misconfiguration, transport failure or a non-2xx.
	 */
	private function get(string $path): array {
		$response = $this->send(path: $path, payload: null);

		return $this->decode(response: $response);
	}//end get()

	/**
	 * One POST against hermiq.
	 *
	 * @param string $path The path.
	 * @param array<string, mixed> $payload The body.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws HermiqAssistantException On misconfiguration, transport failure or a non-2xx.
	 */
	private function post(string $path, array $payload): array {
		$response = $this->send(path: $path, payload: $payload);

		return $this->decode(response: $response);
	}//end post()

	/**
	 * Send one request, with the service-account credentials the assistant client
	 * already uses.
	 *
	 * @param string $path The path.
	 * @param array<string, mixed>|null $payload The body, or null for a GET.
	 *
	 * @return IResponse The response.
	 *
	 * @throws HermiqAssistantException When hermiq is absent, unconfigured or unreachable.
	 */
	private function send(string $path, ?array $payload): IResponse {
		if ($this->isAvailable() === false) {
			throw new HermiqAssistantException(
				message: 'Hermiq is not installed or enabled on this instance',
				statusCode: 503
			);
		}

		$uid = $this->appConfig->getValueString(Application::APP_ID, 'hermiq_service_uid', '');
		$password = $this->appConfig->getValueString(Application::APP_ID, 'hermiq_service_app_password', '');

		if ($uid === '' || $password === '') {
			throw new HermiqAssistantException(
				message: 'The Hermiq service-account credentials are not configured',
				statusCode: 503
			);
		}

		$url = rtrim($this->urlGenerator->getBaseUrl(), '/') . $path;

		$options = [
			'timeout' => self::TIMEOUT_SECONDS,
			'auth' => [$uid, $password],
			// The body and the status are needed on EVERY response, including a
			// 4xx: hermiq's refusals carry the step that refused, and a transport
			// that swallows them into a generic throw loses the only part a
			// handler can act on.
			'http_errors' => false,
			'headers' => ['Accept' => 'application/json'],
		];

		if ($payload !== null) {
			$options['json'] = $payload;
		}

		try {
			$client = $this->clientService->newClient();

			if ($payload === null) {
				return $client->get($url, $options);
			}

			return $client->post($url, $options);
		} catch (Throwable $e) {
			$this->logger->warning(
				'HermiqAiFeatureClient: request failed',
				['app' => Application::APP_ID, 'url' => $url, 'error' => $e->getMessage()]
			);

			throw new HermiqAssistantException(message: 'hermiq_unreachable', statusCode: 503, previous: $e);
		}//end try

	}//end send()

	/**
	 * Decode a response, keeping hermiq's own wording on a refusal.
	 *
	 * @param IResponse $response The response.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws HermiqAssistantException On a non-2xx status or an undecodable body.
	 */
	private function decode(IResponse $response): array {
		$statusCode = $response->getStatusCode();
		$decoded = json_decode((string)$response->getBody(), true);

		if (is_array($decoded) === false) {
			throw new HermiqAssistantException(message: 'hermiq_invalid_response', statusCode: 502);
		}

		if ($statusCode < 200 || $statusCode >= 300) {
			$errorCode = null;
			// hermiq's pre-call gates name the step that refused: model-policy,
			// residency or redaction. That name is the difference between a
			// handler reading a reason and reading a failure, so it is carried
			// through rather than flattened.
			if (isset($decoded['gate']) === true) {
				$errorCode = (string)$decoded['gate'];
			}

			throw new HermiqAssistantException(
				message: (string)($decoded['error'] ?? $decoded['message'] ?? 'hermiq_api_error'),
				statusCode: $statusCode,
				errorCode: $errorCode
			);
		}

		return $decoded;
	}//end decode()
}//end class
