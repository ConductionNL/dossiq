<?php

/**
 * Dossiq StUF Controller
 *
 * Handles BOTH directions of StUF-ZKN/BG:
 *   - INBOUND server reception: raw XML POST at /api/stuf/{zaken,personen},
 *     parses SOAP envelopes, dispatches per message type (zakLk01/zakLv01/
 *     npsLv01/edcLk01) and returns SOAP XML responses (Bv01/La01/Fo01).
 *   - OUTBOUND (admin REST): vrijBericht send, endpoint listing, audit-log
 *     query — JSON. Since decision 193 integriq sends the message: the
 *     endpoint names an integriq StUF-ZKN source in `integriqSource`, and
 *     {@see \OCA\Dossiq\Service\Stuf\StufIntegriqBridge} asks integriq's
 *     ZDS case client for it.
 *   - The async confirmation URL (`/api/stuf/inbound`, `/api/stuf/inkomend`)
 *     is a wire contract in the zaaksysteem's configuration, so for one
 *     release it forwards the raw envelope to integriq, which authenticates
 *     and records it. Point the zaaksysteem at integriq's
 *     `/api/stuf-zkn/confirmations` before the next release removes it.
 *
 *   - The provider URLs (`/api/stuf/cases`, `/persons`, `/zaken`, `/personen`)
 *     forward the raw envelope to integriq for one release too (decision 199).
 *     Integriq authenticates it, asks dossiq through a typed event
 *     ({@see \OCA\Dossiq\Listener\StufProviderQueryListener}) and answers in
 *     StUF. Point the zaaksysteem at integriq's
 *     `/api/stuf-zkn/provider/{sourceId}/cases` and `/persons`.
 *
 * The controller owns only the HTTP surface.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/stuf-integration/spec.md
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\IntegrationStatusServiceInterface;
use OCA\Dossiq\Kernel\Contracts\StufIntegriqBridgeInterface;
use OCA\Dossiq\Kernel\Contracts\StufRegisterAccessInterface;
use OCA\Dossiq\Service\Stuf\StufException;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for inbound + outbound StUF SOAP messages.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
class StufController extends Controller {

	/**
	 * The case service (zakLv01, zakLk01, edcLk01): integriq's name for it.
	 *
	 * @var string
	 */
	public const SERVICE_CASES = 'cases';

	/**
	 * The person service (npsLv01).
	 *
	 * @var string
	 */
	public const SERVICE_PERSONS = 'persons';

	/**
	 * Finds the sending application in an envelope's stuurgegevens, whatever the prefix.
	 *
	 * @var string
	 */
	private const ZENDER_APPLICATION = '#<(?:\w+:)?zender>.*?<(?:\w+:)?applicatie>([^<]+)</(?:\w+:)?applicatie>.*?</(?:\w+:)?zender>#s';
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param StufRegisterAccessInterface $register The StUF endpoint and message rows.
	 * @param StufIntegriqBridgeInterface $bridge Asks integriq to send and to record confirmations.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 * @param IntegrationStatusServiceInterface $integrationStatus Records the StUF card's health.
	 */
	public function __construct(
		IRequest $request,
		private readonly StufRegisterAccessInterface $register,
		private readonly StufIntegriqBridgeInterface $bridge,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly IntegrationStatusServiceInterface $integrationStatus,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Handle inbound StUF-ZKN SOAP messages for case operations.
	 *
	 * Rate-limit rationale: StUF-ZKN SOAP receivers. The caller is a municipal
	 * middleware component on its own retry schedule, so the ceiling is
	 * generous — dropping a StUF delivery is worse than absorbing a burst.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function cases(): DataDisplayResponse {
		return $this->forwardProviderRequest(service: self::SERVICE_CASES);

	}//end cases()

	/**
	 * Serve the deprecated Dutch URL, /api/stuf/zaken.
	 *
	 * Delegates to cases(). Its own method because the URL is a WIRE CONTRACT
	 * held in the sending zaaksysteem's configuration, and because
	 * openregister's AppHost Routes::standard() rejects duplicate route names
	 * by `name` alone and never reads `postfix` — the two-entries-one-name form
	 * throws at boot and takes the whole app's routing down.
	 *
	 * Remove once every configured sender posts to /api/stuf/cases.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 *
	 * @contract exclude Deprecated path alias for cases(); behaviour covered by the cases() tests.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function casesLegacyPath(): DataDisplayResponse {
		return $this->cases();
	}//end casesLegacyPath()

	/**
	 * Handle inbound StUF-BG SOAP messages for person operations.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function persons(): DataDisplayResponse {
		return $this->forwardProviderRequest(service: self::SERVICE_PERSONS);

	}//end persons()

	/**
	 * Serve the deprecated Dutch URL, /api/stuf/personen.
	 *
	 * Delegates to persons(). Its own method for the same reason
	 * casesLegacyPath() is — see the note there.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 *
	 * @contract exclude Deprecated path alias for persons(); behaviour covered by the persons() tests.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function personsLegacyPath(): DataDisplayResponse {
		return $this->persons();
	}//end personsLegacyPath()

	/**
	 * Send a vrijBericht to the named endpoint (outbound), through integriq.
	 *
	 * Admin-only via #[AuthorizedAdminSetting]. Body: { endpointId, berichtNaam, payload }.
	 * Integriq sends it on the endpoint's `integriqSource` and answers with its
	 * result; an endpoint without one, or integriq refusing, is a 400, and
	 * integriq not answering is a 503.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-free-message-templates
	 *
	 * @contract exclude SOAP vrijBericht proxy needs a seeded endpoint + vault + live peer; covered by PHPUnit + env-gated live-e2e/Newman.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function outbound(): JSONResponse {
		$endpointId = (string)$this->request->getParam(key: 'endpointId', default: '');
		$messageName = (string)$this->request->getParam(key: 'berichtNaam', default: '');
		$payload = (array)$this->request->getParam(key: 'payload', default: []);

		if ($endpointId === '' || $messageName === '') {
			return new JSONResponse(['error' => $this->l10n->t('endpointId and berichtNaam are required')], Http::STATUS_BAD_REQUEST);
		}

		$endpoint = $this->register->findById(schema: StufRegisterAccessInterface::SCHEMA_ENDPOINT, id: $endpointId);
		if ($endpoint === null) {
			return new JSONResponse(['error' => $this->l10n->t('Endpoint not found')], Http::STATUS_NOT_FOUND);
		}

		return $this->sendOutbound(endpoint: $endpoint, messageName: $messageName, payload: $payload);
	}//end outbound()

	/**
	 * Send a vrij bericht through Integriq and answer its result, or the failure.
	 *
	 * @param array $endpoint The StUF endpoint.
	 * @param string $messageName The bericht name.
	 * @param array $payload The bericht payload.
	 *
	 * @return JSONResponse
	 */
	private function sendOutbound(array $endpoint, string $messageName, array $payload): JSONResponse {
		try {
			return new JSONResponse($this->bridge->vrijBericht(endpoint: $endpoint, name: $messageName, payload: $payload));
		} catch (StufException $e) {
			return $this->outboundFailure(e: $e);
		}
	}//end sendOutbound()

	/**
	 * Answer a failed outbound message: 503 when Integriq is unavailable, else 400.
	 *
	 * @param StufException $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function outboundFailure(StufException $e): JSONResponse {
		$status = Http::STATUS_BAD_REQUEST;
		$code   = 'STUF_VALIDATION';
		if ($e->getCode() === Http::STATUS_SERVICE_UNAVAILABLE) {
			$status = Http::STATUS_SERVICE_UNAVAILABLE;
			$code   = 'INTEGRIQ_UNAVAILABLE';
		}

		return new JSONResponse(['error' => $e->getMessage(), 'errorCode' => $code], $status);
	}//end outboundFailure()

	/**
	 * Receive an async confirmation from the zaaksysteem and hand it to integriq.
	 *
	 * Public (no user session): integriq authenticates the caller with the
	 * WS-Security UsernameToken of the integriq source the confirmed message
	 * went out on, records it, and answers with a Bv03 or Fo03. Dossiq only
	 * forwards the raw bytes, for one release, because this URL is a wire
	 * contract; with integriq unavailable the answer is 503 so the zaaksysteem
	 * retries.
	 *
	 * @return DataDisplayResponse Integriq's Bv03/Fo03 with its status, or 503.
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-on-the-old-url-reach-integriq
	 *
	 * @contract exclude Forwards a WSSE SOAP webhook to integriq in process; covered by StufControllerInboundTest and integriq's receiver tests.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): DataDisplayResponse {
		$answer = $this->bridge->forwardConfirmation(rawXml: $this->readRawBody());
		if ($answer === null) {
			$this->logger->warning(message: 'StUF inbound: integriq did not answer; the zaaksysteem is told to retry');
			return new DataDisplayResponse('integriq unavailable', Http::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=utf-8']);
		}

		// Integriq answers 200, 400, 422 or 503; anything else is not a status
		// this URL ever gave, so it reads as a bad gateway.
		$status = match ($answer['statusCode']) {
			Http::STATUS_OK => Http::STATUS_OK,
			Http::STATUS_BAD_REQUEST => Http::STATUS_BAD_REQUEST,
			Http::STATUS_UNPROCESSABLE_ENTITY => Http::STATUS_UNPROCESSABLE_ENTITY,
			Http::STATUS_SERVICE_UNAVAILABLE => Http::STATUS_SERVICE_UNAVAILABLE,
			default => Http::STATUS_BAD_GATEWAY,
		};

		return new DataDisplayResponse($answer['body'], $status, ['Content-Type' => 'text/xml; charset=utf-8']);
	}//end inbound()

	/**
	 * Serve the deprecated Dutch URL, /api/stuf/inkomend.
	 *
	 * Delegates to inbound(). It exists as its own method only because the URL
	 * is a WIRE CONTRACT held in the upstream zaaksysteem's configuration:
	 * renaming the path alone would turn a working webhook into a silent 404 on
	 * somebody else's schedule.
	 *
	 * It cannot be expressed as a second routes.php entry reusing
	 * `stuf#inbound` with a `postfix`, because openregister's AppHost
	 * Routes::standard() rejects duplicates by `name` alone and never reads
	 * `postfix` — that form throws "Duplicate route name" at boot and takes the
	 * whole app's routing down. Measured: it failed dossiq's E2E seed.
	 *
	 * Remove once every configured sender posts to /api/stuf/inbound.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-on-the-old-url-reach-integriq
	 *
	 * @contract exclude Deprecated path alias for inbound(); the behaviour is covered by StufControllerInboundTest.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inboundLegacyPath(): DataDisplayResponse {
		return $this->inbound();
	}//end inboundLegacyPath()

	/**
	 * Forward a StUF provider request to integriq (decision 199, one release).
	 *
	 * The sender's `zender.applicatie` names the stufEndpoint, as it did for the
	 * retired dispatcher; that endpoint's `integriqSource` is the integriq source
	 * whose WS-Security integriq checks. An unknown sender is forwarded with no
	 * source, so integriq refuses it with the same fault as a wrong password.
	 *
	 * @param string $service SERVICE_CASES or SERVICE_PERSONS.
	 *
	 * @return DataDisplayResponse Integriq's SOAP answer, or a 503 when integriq does not answer.
	 */
	private function forwardProviderRequest(string $service): DataDisplayResponse {
		$rawXml   = $this->readRawBody();
		$sourceId = '';
		if (preg_match(self::ZENDER_APPLICATION, $rawXml, $match) === 1) {
			$endpoint = $this->register->findOne(
				schema: StufRegisterAccessInterface::SCHEMA_ENDPOINT,
				filters: ['recipientApplication' => trim($match[1])]
			);
			$sourceId = (string)($endpoint['integriqSource'] ?? '');
		}

		$answer = $this->bridge->forwardProviderRequest(rawXml: $rawXml, sourceId: $sourceId, service: $service);
		if ($answer === null) {
			$this->logger->warning(message: 'StUF provider request: integriq did not answer; the zaaksysteem is told to retry');
			return new DataDisplayResponse('integriq unavailable', Http::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=utf-8']);
		}

		// Integriq answers 200, 400, 401, 413 or 500; anything else reads as a bad gateway.
		$status = match ($answer['statusCode']) {
			Http::STATUS_OK => Http::STATUS_OK,
			Http::STATUS_BAD_REQUEST => Http::STATUS_BAD_REQUEST,
			Http::STATUS_UNAUTHORIZED => Http::STATUS_UNAUTHORIZED,
			Http::STATUS_REQUEST_ENTITY_TOO_LARGE => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			Http::STATUS_INTERNAL_SERVER_ERROR => Http::STATUS_INTERNAL_SERVER_ERROR,
			default => Http::STATUS_BAD_GATEWAY,
		};

		return new DataDisplayResponse($answer['body'], $status, ['Content-Type' => 'text/xml; charset=utf-8']);
	}//end forwardProviderRequest()

	/**
	 * Read the raw request body.
	 *
	 * A seam, and the reason this endpoint had no tests while its two siblings
	 * did. `php://input` cannot be driven from a unit test, so the WSSE refusal
	 * below it could never be watched to refuse — and a guard nobody has
	 * watched refuse is a guard nobody has tested. Overriding this one method
	 * is all a test needs; the production path is unchanged.
	 *
	 * `OCP\IRequest` exposes no raw-body accessor, so this cannot simply read
	 * from the injected request.
	 *
	 * @return string The raw request body, or an empty string when there is none.
	 *
	 * @psalm-return   string
	 * @phpstan-return string
	 */
	protected function readRawBody(): string {
		return (string)file_get_contents(filename: 'php://input');
	}//end readRawBody()

	/**
	 * List all configured StufEndpoint objects (admin REST).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function endpoints(): JSONResponse {
		$items = $this->register->findAll(schema: StufRegisterAccessInterface::SCHEMA_ENDPOINT, filters: [], limit: 500);
		$items = array_map(
			callback: function (array $endpoint): array {
				return $this->enrichEndpointWithHealth(endpoint: $endpoint);
			},
			array: $items
		);

		$this->recordStufHealth(endpoints: $items);

		return new JSONResponse(['items' => $items, 'total' => count(value: $items)]);
	}//end endpoints()

	/**
	 * Write what the endpoint list learned onto the StUF integration card.
	 *
	 * Integriq sends and owns the circuit breaker, so the health dossiq can
	 * still tell is whether every endpoint names an integriq source. One that
	 * does not cannot send at all, and that is an error for the connection as
	 * a whole: the thing an admin needs to see, not an average.
	 *
	 * @param array<int, array<string, mixed>> $endpoints The enriched endpoint rows.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function recordStufHealth(array $endpoints): void {
		if ($endpoints === []) {
			$this->recordStuf(status: 'unconfigured', message: 'Not checked yet');
			return;
		}

		$unlinked = $this->unlinkedNames(endpoints: $endpoints);
		if ($unlinked !== []) {
			$this->recordStuf(status: 'error', message: 'No integriq source on ' . implode(', ', $unlinked));
			return;
		}

		$this->recordStuf(status: 'configured', message: 'Healthy: ' . $this->endpointName(endpoint: $endpoints[0]));
	}//end recordStufHealth()

	/**
	 * Record the StUF card's status.
	 *
	 * @param string $status  The card status.
	 * @param string $message What the card says.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function recordStuf(string $status, string $message): void {
		$this->integrationStatus->record(
			key: 'stuf',
			status: $status,
			message: $message
		);
	}//end recordStuf()

	/**
	 * The names of the endpoints that have no integriq source.
	 *
	 * @param array<int, array<string, mixed>> $endpoints The endpoints with their health.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function unlinkedNames(array $endpoints): array {
		$unlinked = [];
		foreach ($endpoints as $endpoint) {
			if ((string)($endpoint['health']['state'] ?? '') === 'unlinked') {
				$unlinked[] = $this->endpointName(endpoint: $endpoint);
			}
		}

		return $unlinked;
	}//end unlinkedNames()

	/**
	 * An endpoint's name, or its id, or "endpoint".
	 *
	 * @param array<string, mixed> $endpoint The endpoint.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function endpointName(array $endpoint): string {
		return (string)($endpoint['name'] ?? ($endpoint['id'] ?? 'endpoint'));
	}//end endpointName()

	/**
	 * Query the StufMessage audit log (admin REST).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function messages(): JSONResponse {
		$limit = max(1, min(500, (int)$this->request->getParam(key: 'limit', default: 50)));
		$filters = $this->messageFilters();

		$items = $this->register->findAll(schema: StufRegisterAccessInterface::SCHEMA_MESSAGE, filters: $filters, limit: $limit);
		return new JSONResponse(['items' => $items, 'total' => count(value: $items), 'limit' => $limit]);
	}//end messages()

	/**
	 * Collect the optional audit-log filters present on the request.
	 *
	 * @return array<string, string> The non-empty filters.
	 */
	private function messageFilters(): array {
		$filters = [];
		foreach (['endpointId', 'messageKind', 'status'] as $key) {
			$value = (string)$this->request->getParam(key: $key, default: '');
			if ($value !== '') {
				$filters[$key] = $value;
			}
		}

		return $filters;
	}//end messageFilters()

	/**
	 * Enrich an endpoint with its health: linked to an integriq source or not, and its recent messages.
	 *
	 * Integriq owns the circuit breaker now; what dossiq can still tell is
	 * whether the endpoint can send at all.
	 *
	 * @param array $endpoint The raw endpoint row.
	 *
	 * @return array
	 */
	private function enrichEndpointWithHealth(array $endpoint): array {
		$state = 'unlinked';
		if (trim((string)($endpoint['integriqSource'] ?? '')) !== '') {
			$state = 'integriq';
		}

		$recent = $this->register->findAll(
			schema: StufRegisterAccessInterface::SCHEMA_MESSAGE,
			filters: ['endpointId' => (string)($endpoint['id'] ?? '')],
			limit: 5
		);
		$endpoint['health'] = [
			'state' => $state,
			'recentCount' => count(value: $recent),
		];
		return $endpoint;
	}//end enrichEndpointWithHealth()
}//end class
