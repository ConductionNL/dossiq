<?php

/**
 * Dossiq StUF Controller
 *
 * The admin REST surface of the outbound StUF-ZKN gateway: vrijBericht send,
 * endpoint listing and audit-log query, all JSON. Since decision 193
 * integriq sends the message: the endpoint names an integriq StUF-ZKN source
 * in `integriqSource`, and {@see \OCA\Dossiq\Service\Stuf\StufIntegriqBridge}
 * asks integriq's ZDS case client for it.
 *
 * Integriq owns the wire. A zaaksysteem posts provider requests to integriq's
 * `/api/stuf-zkn/provider/{sourceId}/cases` and `/persons` (decision 199) and
 * confirmations to `/api/stuf-zkn/confirmations`. The forwarders dossiq kept
 * on its old URLs for one release are gone.
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-go-to-integriq-only
 * @spec openspec/specs/stuf-integration/spec.md#requirement-provider-requests-go-to-integriq-only
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
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Admin REST controller for outbound StUF messages.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-go-to-integriq-only
 * @spec openspec/specs/stuf-integration/spec.md#requirement-provider-requests-go-to-integriq-only
 */
class StufController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param StufRegisterAccessInterface $register The StUF endpoint and message rows.
	 * @param StufIntegriqBridgeInterface $bridge Asks integriq to send.
	 * @param IL10N $l10n The localization service.
	 * @param IntegrationStatusServiceInterface $integrationStatus Records the StUF card's health.
	 */
	public function __construct(
		IRequest $request,
		private readonly StufRegisterAccessInterface $register,
		private readonly StufIntegriqBridgeInterface $bridge,
		private readonly IL10N $l10n,
		private readonly IntegrationStatusServiceInterface $integrationStatus,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

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

		try {
			return new JSONResponse($this->bridge->vrijBericht(endpoint: $endpoint, name: $messageName, payload: $payload));
		} catch (StufException $e) {
			return $this->outboundFailure(e: $e);
		}
	}//end outbound()

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
