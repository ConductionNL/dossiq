<?php

/**
 * Dossiq AC (Autorisaties) Controller
 *
 * Controller for serving ZGW Autorisaties API endpoints (applicaties).
 * Uses ConsumerMapper from OpenRegister to manage API consumers as ZGW
 * applicaties. Does NOT use OpenRegister objects directly.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\ApplicatieRepresentation;
use OCA\Dossiq\Controller\Support\ApplicatieRules;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * AC (Autorisaties) API Controller
 *
 * Manages ZGW applicaties backed by OpenRegister ConsumerMapper entries.
 * Completely custom logic — no OpenRegister object storage.
 *
 * Implements VNG AC business rules:
 * - ac-001: ClientId uniqueness across applicaties
 * - ac-002: heeftAlleAutorisaties consistency with autorisaties array
 * - ac-003: Scope-based field requirements per component
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class AcController extends ZgwController {
	/**
	 * The consumer fields an update body may set (L2: no mass-assignment).
	 *
	 * @var array<int, string>
	 */
	private const UPDATABLE_CONSUMER_FIELDS = [
		'name',
		'description',
		'authorizationType',
		'authorizationConfiguration',
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwService $zgwService The shared ZGW service.
	 * @param IL10N $l10n The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Refuse a caller that is not a ZGW consumer holding the given scope.
	 *
	 * THE CREDENTIAL THESE ENDPOINTS AUTHENTICATE WITH is the ZGW consumer's
	 * JSON Web Token, presented as `Authorization: Bearer <token>`.
	 * {@see ZgwService::validateJwtAuth()} reads that header and verifies the
	 * signature against the consumer's secret; {@see
	 * ZgwService::consumerHasScope()} reads the same header again to resolve
	 * the `client_id` and look the consumer's scopes up.
	 *
	 * These four writes are `@PublicPage` because a ZGW consumer has no
	 * Nextcloud session: without it the middleware rejects the caller before
	 * this controller runs, which is exactly what used to happen. So this guard
	 * is the only thing between a caller and the credentials every other ZGW
	 * call authenticates with.
	 *
	 * The body it returns was written out three times, once per write, with
	 * nothing holding the three copies in agreement.
	 *
	 * @param string $scope The ac.* scope this action requires
	 * @param string $title The ZGW error title for this action
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function requireAcScope(string $scope, string $title): ?JSONResponse {
		$presentedToken = str_replace('Bearer ', '', $this->request->getHeader('Authorization'));

		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		if ($this->zgwService->consumerHasScope($this->request, 'ac', $scope) === true) {
			return null;
		}

		return new JSONResponse(
			data: [
				'type' => 'PermissionDenied',
				'code' => 'permission_denied',
				'title' => $title,
				'status' => Http::STATUS_FORBIDDEN,
				'detail' => sprintf(
					'Consumer %s heeft scope %s niet.',
					$this->clientIdOf(presentedToken: $presentedToken),
					$scope
				),
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end requireAcScope()

	/**
	 * Read the client_id out of the token the caller presented.
	 *
	 * Only for the refusal message. A 403 that says nothing but "scope X is
	 * required" leaves an integrator guessing which of their consumers made the
	 * call, and `consumerHasScope()` answers false for four different reasons
	 * without distinguishing them.
	 *
	 * Nothing here is trusted: the signature was already verified by
	 * {@see ZgwService::validateJwtAuth()} before this runs, and a token this
	 * cannot read yields "onbekend" rather than an error.
	 *
	 * @param string $presentedToken The bearer token, without its scheme
	 *
	 * @return string The client_id, or 'onbekend' when it cannot be read
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function clientIdOf(string $presentedToken): string {
		$parts = explode('.', $presentedToken);
		if (count($parts) !== 3) {
			return 'onbekend';
		}

		$payload = json_decode((string)base64_decode($parts[1], true), true);
		if (is_array($payload) === false) {
			return 'onbekend';
		}

		$clientId = ($payload['client_id'] ?? ($payload['iss'] ?? null));

		if (is_string($clientId) === false || $clientId === '') {
			return 'onbekend';
		}

		return $clientId;
	}//end clientIdOf()

	/**
	 * List all applicaties, optionally filtered by clientId.
	 *
	 * Supports both 'clientId' and 'clientIds' query parameters.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function index(): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$unavailable = $this->consumerServiceUnavailable();
		if ($unavailable !== null) {
			return $unavailable;
		}

		try {
			$consumers = $this->listConsumers(filterClientId: $this->requestedClientId());

			return $this->applicatieList(consumers: $consumers);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error('AC list error: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse(
				data: ['detail' => 'Internal server error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end index()

	/**
	 * Answer with consumers as a ZGW list of applicaties.
	 *
	 * @param array $consumers The consumers listed
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function applicatieList(array $consumers): JSONResponse {
		$baseUrl = $this->applicatieBaseUrl();
		$results = [];
		foreach ($consumers as $consumer) {
			$results[] = $this->representation()->toApplicatie(consumer: $consumer, baseUrl: $baseUrl);
		}

		return new JSONResponse(
			data: [
				'count' => count($results),
				'next' => null,
				'previous' => null,
				'results' => $results,
			]
		);
	}//end applicatieList()

	/**
	 * Read the clientId the list is filtered on.
	 *
	 * Both query parameters are read; 'clientId' wins over 'clientIds' and an
	 * empty value counts as absent.
	 *
	 * @return mixed The requested clientId, or null when the list is unfiltered
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function requestedClientId(): mixed {
		$clientId = $this->request->getParam('clientId');
		$clientIds = $this->request->getParam('clientIds');

		if ($clientId !== null && $clientId !== '') {
			return $clientId;
		}

		if ($clientIds !== null && $clientIds !== '') {
			return $clientIds;
		}

		return null;
	}//end requestedClientId()

	/**
	 * Find the consumers the list shows.
	 *
	 * Without a filter every consumer is listed. With one, the primary clientId
	 * (the consumer name) is searched first, and only when nothing matches are
	 * the extra clientIds stored in the authorization configuration searched.
	 *
	 * @param mixed $filterClientId The requested clientId, or null
	 *
	 * @return array The matching consumers
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function listConsumers(mixed $filterClientId): array {
		$consumers = $this->zgwService->getConsumerMapper()->findAll();

		if ($filterClientId === null) {
			return $consumers;
		}

		// Search by name (primary clientId) first.
		$consumers = $this->zgwService->getConsumerMapper()->findAll(
			filters: ['name' => $filterClientId]
		);

		if (count($consumers) > 0) {
			return $consumers;
		}

		return $this->consumersWithExtraClientId(clientId: $filterClientId);
	}//end listConsumers()

	/**
	 * Find the consumers holding a clientId among their extra clientIds.
	 *
	 * @param mixed $clientId The clientId searched for
	 *
	 * @return array The consumers that carry it in their authorization configuration
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function consumersWithExtraClientId(mixed $clientId): array {
		$consumers = [];
		$allConsumers = $this->zgwService->getConsumerMapper()->findAll();
		foreach ($allConsumers as $consumer) {
			$authConfig = $consumer->getAuthorizationConfiguration() ?? [];
			$extraClientIds = $authConfig['clientIds'] ?? [];
			if (in_array($clientId, $extraClientIds, true) === true) {
				$consumers[] = $consumer;
			}
		}

		return $consumers;
	}//end consumersWithExtraClientId()

	/**
	 * Create a new applicatie.
	 *
	 * Validates ac-001 (clientId uniqueness), ac-002 (heeftAlleAutorisaties
	 * consistency), and ac-003 (scope-based field requirements) before saving.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function create(): JSONResponse {
		// C2: Gate writes on the ac.aanmaken scope. The credential, the refusal and
		// the ZGW error body all live in requireAcScope().
		$refused = $this->guardWrite(
			scope: 'ac.aanmaken',
			title: 'U heeft geen toestemming om autorisaties aan te maken.'
		);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$body = $this->zgwService->getRequestBody($this->request);

			// C2: Block setting superuser=true unless the requesting consumer is itself a superuser.
			// Then run the AC business rules validation.
			$invalid = ($this->refuseSuperuserEscalation(body: $body) ?? $this->rules()->validateApplicatieBody(body: $body));
			if ($invalid !== null) {
				return $invalid;
			}

			$consumerData = $this->representation()->toConsumer(body: $body);
			$consumer = $this->zgwService->getConsumerMapper()->createFromArray(object: $consumerData);

			return $this->applicatieResponse(consumer: $consumer, statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->failedWrite(context: 'AC create error: ', exception: $e);
		}//end try
	}//end create()

	/**
	 * Retrieve a single applicatie by UUID.
	 *
	 * If uuid is 'consumer', delegates to index() with clientId filter.
	 *
	 * @param string $uuid The applicatie UUID or 'consumer'.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function show(string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		if ($uuid === 'consumer') {
			return $this->index();
		}

		$unavailable = $this->consumerServiceUnavailable();
		if ($unavailable !== null) {
			return $unavailable;
		}

		try {
			$consumer = $this->findConsumerByUuid(uuid: $uuid);
			if ($consumer === null) {
				return $this->notFound();
			}

			return $this->applicatieResponse(consumer: $consumer, statusCode: Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error('AC show error: ' . $e->getMessage(), ['exception' => $e]);

			return $this->notFound();
		}//end try
	}//end show()

	/**
	 * Full update (PUT) an applicatie by UUID.
	 *
	 * @param string $uuid The applicatie UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function update(string $uuid): JSONResponse {
		// C2: Gate writes on the ac.bijwerken scope. The credential, the refusal and
		// the ZGW error body all live in requireAcScope().
		$refused = $this->guardWrite(
			scope: 'ac.bijwerken',
			title: 'U heeft geen toestemming om autorisaties bij te werken.'
		);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$consumer = $this->findConsumerByUuid(uuid: $uuid);
			if ($consumer === null) {
				return $this->notFound();
			}

			$body = $this->zgwService->getRequestBody($this->request);

			// C1: Block setting heeftAlleAutorisaties=true unless caller has ac.superuser scope.
			$escalation = $this->refuseSuperuserEscalation(body: $body);
			if ($escalation !== null) {
				return $escalation;
			}

			$consumerData = $this->representation()->toConsumer(body: $body);
			$this->applyConsumerFields(consumer: $consumer, consumerData: $consumerData);

			$updated = $this->zgwService->getConsumerMapper()->update(entity: $consumer);

			return $this->applicatieResponse(consumer: $updated, statusCode: Http::STATUS_OK);
		} catch (\Throwable $e) {
			return $this->failedWrite(context: 'AC update error: ', exception: $e);
		}//end try
	}//end update()

	/**
	 * Apply the known consumer fields of an applicatie body to a consumer.
	 *
	 * L2: Only apply setters for known consumer fields to prevent reflection-based
	 * mass-assignment from arbitrary body keys.
	 *
	 * @param object $consumer     The consumer entity being updated
	 * @param array  $consumerData The consumer data built from the body
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function applyConsumerFields(object $consumer, array $consumerData): void {
		foreach ($consumerData as $key => $value) {
			if (in_array($key, self::UPDATABLE_CONSUMER_FIELDS, true) === false) {
				continue;
			}

			$setter = 'set' . ucfirst($key);
			if (method_exists($consumer, $setter) === true) {
				$consumer->$setter($value);
			}
		}
	}//end applyConsumerFields()

	/**
	 * Partial update (PATCH) an applicatie by UUID.
	 *
	 * @param string $uuid The applicatie UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function patch(string $uuid): JSONResponse {
		return $this->update(uuid: $uuid);
	}//end patch()

	/**
	 * Delete an applicatie by UUID.
	 *
	 * @param string $uuid The applicatie UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function destroy(string $uuid): JSONResponse {
		// C2: Gate writes on the ac.verwijderen scope. The credential, the refusal and
		// the ZGW error body all live in requireAcScope().
		$refused = $this->guardWrite(
			scope: 'ac.verwijderen',
			title: 'U heeft geen toestemming om autorisaties te verwijderen.'
		);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$consumer = $this->findConsumerByUuid(uuid: $uuid);
			if ($consumer === null) {
				return $this->notFound();
			}

			$this->zgwService->getConsumerMapper()->delete(entity: $consumer);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			return $this->failedWrite(context: 'AC delete error: ', exception: $e);
		}
	}//end destroy()

	/**
	 * Refuse a write the caller may not make, or that cannot be served.
	 *
	 * The scope check runs first; only a caller holding the scope learns
	 * whether the consumer service is available.
	 *
	 * @param string $scope The ac.* scope this action requires
	 * @param string $title The ZGW error title for this action
	 *
	 * @return JSONResponse|null The refusal, or null when the write may proceed
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function guardWrite(string $scope, string $title): ?JSONResponse {
		return ($this->requireAcScope(scope: $scope, title: $title) ?? $this->consumerServiceUnavailable());
	}//end guardWrite()

	/**
	 * Answer 503 when OpenRegister's consumer mapper is not available.
	 *
	 * @return JSONResponse|null The 503 response, or null when the mapper is there
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function consumerServiceUnavailable(): ?JSONResponse {
		if ($this->zgwService->getConsumerMapper() !== null) {
			return null;
		}

		return new JSONResponse(
			data: ['detail' => 'Consumer service not available'],
			statusCode: Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end consumerServiceUnavailable()

	/**
	 * Refuse heeftAlleAutorisaties=true from a caller without the ac.superuser scope.
	 *
	 * @param array $body The request body
	 *
	 * @return JSONResponse|null The 403 refusal, or null when the body may be applied
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function refuseSuperuserEscalation(array $body): ?JSONResponse {
		if (($body['heeftAlleAutorisaties'] ?? false) !== true
			|| $this->zgwService->consumerHasScope($this->request, 'ac', 'ac.superuser') === true
		) {
			return null;
		}

		return $this->rules()->invalidParams(
			params: [
				[
					'name' => 'heeftAlleAutorisaties',
					'code' => 'permission_denied',
					'reason' => 'Alleen superuser-consumers mogen heeftAlleAutorisaties op true zetten.',
				],
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end refuseSuperuserEscalation()

	/**
	 * The 404 every lookup by UUID answers with.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(
			data: ['detail' => 'Not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()

	/**
	 * Log a failed write and answer 400 with its message.
	 *
	 * @param string     $context   The log message prefix
	 * @param \Throwable $exception The failure
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function failedWrite(string $context, \Throwable $exception): JSONResponse {
		$this->zgwService->getLogger()->error($context . $exception->getMessage(), ['exception' => $exception]);

		return new JSONResponse(
			data: ['detail' => $exception->getMessage()],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end failedWrite()

	/**
	 * The base URL applicatie URLs are built on.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function applicatieBaseUrl(): string {
		return $this->zgwService->buildBaseUrl($this->request, 'autorisaties', 'applicaties');
	}//end applicatieBaseUrl()

	/**
	 * Answer with one consumer as a ZGW applicatie.
	 *
	 * @param object $consumer   The consumer entity
	 * @param int    $statusCode The HTTP status
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function applicatieResponse(object $consumer, int $statusCode): JSONResponse {
		$mapped = $this->representation()->toApplicatie(consumer: $consumer, baseUrl: $this->applicatieBaseUrl());

		return new JSONResponse(data: $mapped, statusCode: $statusCode);
	}//end applicatieResponse()

	/**
	 * The AC business rules an applicatie body is validated against.
	 *
	 * @return ApplicatieRules
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function rules(): ApplicatieRules {
		return new ApplicatieRules(zgwService: $this->zgwService, l10n: $this->l10n);
	}//end rules()

	/**
	 * The mapping between OpenRegister consumers and ZGW applicaties.
	 *
	 * @return ApplicatieRepresentation
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function representation(): ApplicatieRepresentation {
		return new ApplicatieRepresentation();
	}//end representation()

	/**
	 * Find a consumer entity by its UUID.
	 *
	 * @param string $uuid The consumer UUID.
	 *
	 * @return object|null The consumer entity, or null if not found.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function findConsumerByUuid(string $uuid): ?object {
		$consumers = $this->zgwService->getConsumerMapper()->findAll(filters: ['uuid' => $uuid]);
		if (count($consumers) === 0) {
			return null;
		}

		return $consumers[0];
	}//end findConsumerByUuid()

}//end class
