<?php

/**
 * Dossiq ZGW Service
 *
 * Shared service for ZGW-compliant API operations. Provides mapping,
 * authentication, pagination, and OpenRegister integration used by
 * all register-specific ZGW controllers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-1
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\OpenRegister\Db\Mapping;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Dossiq\Service\Zgw\ZgwParentStateResolver;
use OCA\Dossiq\Service\Zgw\ZgwPatchMerger;
use Psr\Log\LoggerInterface;

/**
 * Shared ZGW API service.
 *
 * Contains all shared utility methods extracted from the monolithic ZgwController.
 * Register-specific controllers delegate common operations to this service.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwService {
	/**
	 * Map of ZGW API + resource to the config key suffix used in Dossiq.
	 *
	 * EVERY VALUE HERE IS A MAPPING KEY, NOT A SCHEMA NAME. It is the suffix of
	 * the `zgw_mapping_<key>` appconfig entry that `LoadDefaultZgwMappings`
	 * writes, so a value this repair step never writes resolves to no mapping
	 * at all and the endpoint answers 404 "No ZGW mapping configured".
	 *
	 * Two values used to be schema names instead: `zaken/zaken` said `case` and
	 * `documenten/verzendingen` said `dispatch`, while the repair step writes
	 * `zgw_mapping_zaak` and `zgw_mapping_verzending`. Nothing compared the two
	 * lists, so the whole ZRC zaken surface — the largest folder in both VNG
	 * contract collections — 404ed on every request and took its setUp cascade
	 * with it. ZgwResourceMapConsistencyTest now holds the two sides together.
	 *
	 * Requests per minute a ZGW consumer may spend on a plain read.
	 *
	 * The ZGW APIs are machine to machine. Nextcloud sees a JWT-authenticated
	 * consumer as anonymous, so `#[AnonRateLimit]` is the only throttle on
	 * them, and it buckets by remote address: behind a municipal reverse proxy
	 * every consumer shares one bucket.
	 *
	 * The old ceilings were 120 reads and 30 writes a minute. Measured on this
	 * tree, the two VNG contract collections peak at 180 writes and 35 reads
	 * inside one 60 second window, so the write tier was exceeded four times
	 * over by an ordinary conformance run. 133 of 646 business-rules requests
	 * came back as a bare 429, and a probe of 45 posts to one endpoint answered
	 * 30 times and then 429 fifteen times, exactly at the declared limit.
	 *
	 * These numbers keep a real ceiling, 20 reads and 10 writes a second, while
	 * admitting the traffic a single integration actually makes.
	 *
	 * PER-CONSUMER KEYING IS THE REAL ANSWER and this is not it. `AnonRateLimit`
	 * cannot key on the JWT client_id, so one noisy consumer still spends the
	 * budget of every consumer sharing its address. Tracked in #2460.
	 */
	public const RATE_LIMIT_READ = 1200;

	/**
	 * Requests per minute a ZGW consumer may spend on a write.
	 *
	 * Also covers the two reads that cost like a write: `zaken/_zoek` runs a
	 * full search, and the document download streams a file.
	 *
	 * See {@see self::RATE_LIMIT_READ} for the measurement behind both numbers.
	 */
	public const RATE_LIMIT_WRITE = 600;

	/**
	 * @var array<string, array<string, string>>
	 */
	public const RESOURCE_MAP = [
		'zaken' => [
			'zaken' => 'zaak',
			'statussen' => 'status',
			'resultaten' => 'result',
			'rollen' => 'role',
			'zaakeigenschappen' => 'zaakeigenschap',
			'zaakinformatieobjecten' => 'zaakinformatieobject',
			'zaakobjecten' => 'zaakobject',
			'klantcontacten' => 'klantcontact',
		],
		'catalogi' => [
			'catalogussen' => 'catalogus',
			'zaaktypen' => 'caseType',
			'statustypen' => 'statustype',
			'resultaattypen' => 'resultaattype',
			'roltypen' => 'roltype',
			'eigenschappen' => 'eigenschap',
			'informatieobjecttypen' => 'informatieobjecttype',
			'besluittypen' => 'besluittype',
			'zaaktype-informatieobjecttypen' => 'zaaktypeinformatieobjecttype',
		],
		'besluiten' => [
			'besluiten' => 'decision',
			'besluittypen' => 'besluittype',
			'besluitinformatieobjecten' => 'besluitinformatieobject',
		],
		'autorisaties' => [
			'applicaties' => 'applicatie',
		],
		'documenten' => [
			'enkelvoudiginformatieobjecten' => 'enkelvoudiginformatieobject',
			'objectinformatieobjecten' => 'objectinformatieobject',
			'gebruiksrechten' => 'gebruiksrechten',
			'verzendingen' => 'verzending',
		],
		'notificaties' => [
			'kanaal' => 'kanaal',
			'abonnement' => 'abonnement',
		],
	];

	/**
	 * The OpenRegister MappingService (loaded dynamically).
	 *
	 * @var object|null
	 */
	private $mappingService = null;

	/**
	 * The OpenRegister ObjectService (loaded dynamically).
	 *
	 * @var object|null
	 */
	private $objectService = null;

	/**
	 * The parent zaak / zaaktype resolver, built on first use.
	 *
	 * @var ZgwParentStateResolver|null
	 */
	private ?ZgwParentStateResolver $parentStateResolver = null;

	/**
	 * Cached request body to avoid re-reading php://input.
	 *
	 * @var array|null
	 */
	private ?array $cachedRequestBody = null;

	/**
	 * The OpenRegister ConsumerMapper (loaded dynamically).
	 *
	 * @var object|null
	 */
	private $consumerMapper = null;

	/**
	 * Constructor.
	 *
	 * @param ZgwMappingService $zgwMappingService The ZGW mapping service
	 * @param ZgwPaginationHelper $paginationHelper The pagination helper
	 * @param ZgwDocumentService $documentService The document storage service
	 * @param NotificatieService $notificationService The notification service
	 * @param ZgwBusinessRulesService $businessRulesService The business rules service
	 * @param ZgwJwtValidator $jwtValidator The ZGW JWT validator
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwMappingService $zgwMappingService,
		private readonly ZgwPaginationHelper $paginationHelper,
		private readonly ZgwDocumentService $documentService,
		private readonly NotificatieService $notificationService,
		private readonly ZgwBusinessRulesService $businessRulesService,
		private readonly ZgwJwtValidator $jwtValidator,
		private readonly LoggerInterface $logger,
	) {
		$container = \OC::$server;

		try {
			$this->mappingService = $container->get(
				'OCA\OpenRegister\Service\MappingService'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ZgwService: MappingService not available',
				['exception' => $e->getMessage()]
			);
		}

		try {
			$this->objectService = $container->get(
				'OCA\OpenRegister\Service\ObjectService'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ZgwService: ObjectService not available',
				['exception' => $e->getMessage()]
			);
		}

		try {
			$this->consumerMapper = $container->get(
				'OCA\OpenRegister\Db\ConsumerMapper'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ZgwService: Auth services not available',
				['exception' => $e->getMessage()]
			);
		}
	}//end __construct()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getObjectService(): ?object {
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the OpenRegister ConsumerMapper.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getConsumerMapper(): ?object {
		return $this->consumerMapper;
	}//end getConsumerMapper()

	/**
	 * Get the ZGW mapping service.
	 *
	 * @return ZgwMappingService
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getZgwMappingService(): ZgwMappingService {
		return $this->zgwMappingService;
	}//end getZgwMappingService()

	/**
	 * Get the pagination helper.
	 *
	 * @return ZgwPaginationHelper
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getPaginationHelper(): ZgwPaginationHelper {
		return $this->paginationHelper;
	}//end getPaginationHelper()

	/**
	 * Get the document service.
	 *
	 * @return ZgwDocumentService
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getDocumentService(): ZgwDocumentService {
		return $this->documentService;
	}//end getDocumentService()

	/**
	 * Get the business rules service.
	 *
	 * @return ZgwBusinessRulesService
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getBusinessRulesService(): ZgwBusinessRulesService {
		return $this->businessRulesService;
	}//end getBusinessRulesService()

	/**
	 * Get the logger.
	 *
	 * @return LoggerInterface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getLogger(): LoggerInterface {
		return $this->logger;
	}//end getLogger()

	/**
	 * Load ZGW mapping configuration.
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return array|null The mapping configuration or null if not found
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadMappingConfig(string $zgwApi, string $resource): ?array {
		$resourceKey = self::RESOURCE_MAP[$zgwApi][$resource] ?? null;
		if ($resourceKey === null) {
			return null;
		}

		return $this->zgwMappingService->getMapping(resourceKey: $resourceKey);
	}//end loadMappingConfig()

	/**
	 * Translate ZGW query parameters to OpenRegister filter parameters.
	 *
	 * @param array $params The request query parameters
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return array Translated filter parameters
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function translateQueryParams(array $params, array $mappingConfig): array {
		$queryMapping = $mappingConfig['queryParameterMapping'] ?? [];
		$filters = [];

		$reserved = [
			'page',
			'pageSize',
			'_route',
			'zgwApi',
			'resource',
			'uuid',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $reserved, true) === true) {
				continue;
			}

			if (isset($queryMapping[$key]) === true) {
				$mapped = $queryMapping[$key];
				$field = $mapped['field'] ?? $key;
				$operator = $mapped['operator'] ?? null;

				if (($mapped['extractUuid'] ?? false) === true
					&& is_string($value) === true
				) {
					$parts = explode('/', rtrim($value, '/'));
					$value = end($parts);
				}

				$filterKey = $field;
				if ($operator !== null) {
					$filterKey = $field . '.' . $operator;
				}

				$filters[$filterKey] = $value;
			}
		}//end foreach

		return $filters;
	}//end translateQueryParams()

	/**
	 * Create a Mapping object for outbound (English to Dutch) transformation.
	 *
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return Mapping The outbound mapping entity
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createOutboundMapping(array $mappingConfig): object {
		$mapping = new Mapping();
		$mappingData = [
			'name' => 'zgw-outbound-' . ($mappingConfig['zgwResource'] ?? 'unknown'),
			'mapping' => $mappingConfig['propertyMapping'] ?? [],
			'unset' => $mappingConfig['unset'] ?? [],
			'cast' => $mappingConfig['cast'] ?? [],
			'passThrough' => false,
		];
		$mapping->hydrate(object: $mappingData);

		return $mapping;
	}//end createOutboundMapping()

	/**
	 * Create a Mapping object for inbound (Dutch to English) transformation.
	 *
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return Mapping The inbound mapping entity
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createInboundMapping(array $mappingConfig): object {
		$mapping = new Mapping();
		$mappingData = [
			'name' => 'zgw-inbound-' . ($mappingConfig['zgwResource'] ?? 'unknown'),
			'mapping' => $mappingConfig['reverseMapping'] ?? [],
			'unset' => $mappingConfig['reverseUnset'] ?? [],
			'cast' => $mappingConfig['reverseCast'] ?? [],
			'passThrough' => false,
		];
		$mapping->hydrate(object: $mappingData);

		return $mapping;
	}//end createInboundMapping()

	/**
	 * Apply outbound mapping (English to Dutch) to an object.
	 *
	 * @param array $objectData The English-language object data
	 * @param object $mapping The outbound mapping entity
	 * @param array $mappingConfig The ZGW mapping configuration
	 * @param string $baseUrl The base URL for ZGW URL references
	 *
	 * @return array The mapped Dutch-language object
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyOutboundMapping(
		array $objectData,
		object $mapping,
		array $mappingConfig,
		string $baseUrl,
	): array {
		$objectData['_baseUrl'] = $baseUrl;
		$objectData['_valueMappings'] = $mappingConfig['valueMapping'] ?? [];
		$selfMeta = $objectData['@self'] ?? [];
		$objectData['_uuid'] = $objectData['id'] ?? ($selfMeta['id'] ?? '');
		$objectData['_created'] = $selfMeta['created'] ?? '';
		$objectData['_updated'] = $selfMeta['updated'] ?? '';

		$zgwResource = $mappingConfig['zgwResource'] ?? '';
		if ($zgwResource === 'enkelvoudiginformatieobject'
			&& $objectData['_uuid'] !== ''
		) {
			$objectData['_downloadUrl'] = $baseUrl . '/' . $objectData['_uuid'] . '/download';
		}

		$mapped = $this->mappingService->executeMapping(
			mapping: $mapping,
			input: $objectData
		);

		$nullableFields = $mappingConfig['nullableFields'] ?? [];
		foreach ($nullableFields as $field) {
			if (array_key_exists($field, $mapped) === true && $mapped[$field] === '') {
				$mapped[$field] = null;
			}
		}

		return $mapped;
	}//end applyOutboundMapping()

	/**
	 * Apply inbound mapping (Dutch to English) to request data.
	 *
	 * @param array $body The Dutch-language request body
	 * @param object $mapping The inbound mapping entity
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return array The mapped English-language data
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyInboundMapping(
		array $body,
		object $mapping,
		array $mappingConfig,
	): array {
		$body['_valueMappings'] = $mappingConfig['valueMapping'] ?? [];
		unset($body['_route'], $body['zgwApi'], $body['resource'], $body['uuid']);

		$mapped = $this->mappingService->executeMapping(
			mapping: $mapping,
			input: $body
		);

		// Remove empty-string values for nullable/date fields to prevent OpenRegister
		// from storing "" in date fields (which converts to today's date).
		$nullableKeys = $mappingConfig['inboundNullable'] ?? [
			'endDate',
			'plannedEndDate',
			'deadline',
			'archiveNomination',
			'archiveActionDate',
			'paymentIndication',
			'lastPaymentDate',
			'communicationChannel',
			'archiveStatus',
			'parentCase',
		];
		foreach ($nullableKeys as $key) {
			if (isset($mapped[$key]) === true && $mapped[$key] === '') {
				unset($mapped[$key]);
			}
		}

		return $mapped;
	}//end applyInboundMapping()

	/**
	 * Get the request body, falling back to raw body parsing for malformed JSON.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return array The parsed request body
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getRequestBody(IRequest $request): array {
		// Return cached result if already parsed for this request.
		if ($this->cachedRequestBody !== null) {
			return $this->cachedRequestBody;
		}

		$routeKeys = ['_route', 'zgwApi', 'resource', 'uuid'];

		// Read php://input directly — Nextcloud's getParams() parser may
		// flatten/drop JSON array values (e.g. `["uuid"]` → `[]`).
		$rawBody = file_get_contents('php://input');
		if ($rawBody !== false && $rawBody !== '') {
			$decoded = json_decode($rawBody, true);
			// Do not attempt to repair malformed JSON — it risks data corruption
			// and may be used to smuggle crafted values through the regex transform.
			if ($decoded !== null) {
				// Merge route params so they remain available downstream.
				$routeParams = $request->getParams();
				foreach ($routeKeys as $key) {
					if (isset($routeParams[$key]) === true) {
						$decoded[$key] = $routeParams[$key];
					}
				}

				$this->cachedRequestBody = $decoded;

				return $decoded;
			}
		}//end if

		// Fallback: use getParams() for non-JSON requests (multipart, form-encoded).
		$this->cachedRequestBody = $request->getParams();

		return $this->cachedRequestBody;
	}//end getRequestBody()

	/**
	 * Extract the UUID from the request URL path.
	 *
	 * Nextcloud's controller argument injection merges JSON body params into
	 * getParam(), so a body "uuid" field overrides the route's {uuid} param.
	 * This method extracts the UUID directly from the URL path to avoid that.
	 *
	 * @param IRequest $request The request object
	 * @param string $uuid The controller-injected UUID (potentially wrong)
	 *
	 * @return string The correct UUID from the URL path, or the fallback
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolvePathUuid(IRequest $request, string $uuid): string {
		$uri = $request->getRequestUri();
		if (preg_match('/\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $uri, $matches) === 1) {
			return $matches[1];
		}

		return $uuid;
	}//end resolvePathUuid()

	/**
	 * Update a field in the cached request body.
	 *
	 * Used when pre-processing resolves a value (e.g., IOT omschrijving → UUID).
	 *
	 * @param string $key The field name
	 * @param mixed $value The new value
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function updateCachedBodyField(string $key, mixed $value): void {
		if ($this->cachedRequestBody !== null) {
			$this->cachedRequestBody[$key] = $value;
		}
	}//end updateCachedBodyField()

	/**
	 * Build the base URL for ZGW API responses.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return string The base URL
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function buildBaseUrl(IRequest $request, string $zgwApi, string $resource): string {
		$serverHost = $request->getServerHost();
		$scheme = $request->getServerProtocol();

		return $scheme . '://' . $serverHost . '/index.php/apps/dossiq/api/zgw/' . $zgwApi . '/v1/' . $resource;
	}//end buildBaseUrl()

	/**
	 * Validate JWT-ZGW authentication from the Authorization header.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return JSONResponse|null 401 response on failure, null on success
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validateJwtAuth(IRequest $request): ?JSONResponse {
		$authHeader = $request->getHeader('Authorization');

		if ($authHeader === '') {
			return new JSONResponse(
				data: [
					'type' => 'NotAuthenticated',
					'code' => 'not_authenticated',
					'title' => 'Authenticatiegegevens zijn niet opgegeven.',
					'status' => 401,
					'detail' => 'Authenticatiegegevens zijn niet opgegeven.',
				],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$this->jwtValidator->validate(authorization: $authHeader);
		} catch (\Throwable $e) {
			// M3: Log detail server-side but never surface internal JWT validation
			// messages in the HTTP response — they aid algorithm/issuer enumeration.
			$this->logger->warning(
				'ZGW JWT validation failed: ' . $e->getMessage(),
				['app' => 'dossiq']
			);

			return new JSONResponse(
				data: [
					'type' => 'NotAuthenticated',
					'code' => 'not_authenticated',
					'title' => 'Authenticatiegegevens zijn niet geldig.',
					'status' => 403,
					'detail' => 'Authenticatiegegevens zijn niet geldig.',
				],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}//end try

		return null;
	}//end validateJwtAuth()

	/**
	 * Check if the current JWT consumer has a specific scope.
	 *
	 * @param IRequest $request The request object
	 * @param string $component The ZGW component (e.g. 'zrc', 'ztc', 'brc', 'drc')
	 * @param string $scope The required scope
	 *
	 * @return bool True if the consumer has the scope or heeftAlleAutorisaties
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function consumerHasScope(IRequest $request, string $component, string $scope): bool {
		try {
			// H2: Fail closed — no mapper, a malformed JWT, no client_id/iss or no matching
			// consumer all deny, never grant.
			$authConfig = $this->getConsumerAuthConfig(request: $request);
			if ($authConfig === null) {
				return false;
			}

			if (($authConfig['superuser'] ?? false) === true) {
				return true;
			}

			foreach (($authConfig['scopes'] ?? []) as $auth) {
				if (($auth['component'] ?? '') === $component && in_array($scope, $auth['scopes'] ?? [], true) === true) {
					return true;
				}
			}

			return false;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not check consumer scope: ' . $e->getMessage()
			);
			// Fail closed (CWE-863): on error, deny the scope rather than grant it.
			return false;
		}//end try
	}//end consumerHasScope()

	/**
	 * Get the consumer's authorization details for a component (for zrc-006).
	 *
	 * Returns the authorization entries (autorisaties) for the given component,
	 * or null if the consumer has full access (superuser / no restrictions).
	 *
	 * @param IRequest $request The request object
	 * @param string $component The ZGW component (e.g. 'zrc')
	 *
	 * @return array|null Array of autorisatie entries, or null if unrestricted
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getConsumerAuthorisaties(IRequest $request, string $component): ?array {
		try {
			// H2: Fail closed — no mapper, a malformed JWT, no client_id/iss or no matching
			// consumer all return the empty set (restricted), never null (unrestricted).
			$authConfig = $this->getConsumerAuthConfig(request: $request);
			if ($authConfig === null) {
				return [];
			}

			if (($authConfig['superuser'] ?? false) === true) {
				return null;
			}

			$result = [];
			foreach (($authConfig['scopes'] ?? []) as $auth) {
				if (($auth['component'] ?? '') === $component) {
					$result[] = $auth;
				}
			}

			return $result;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not get consumer autorisaties: ' . $e->getMessage()
			);
			// Fail closed (CWE-863): on error, return an empty authorisation set
			// (caller treats [] as "no scopes granted" → deny) rather than null,
			// which the caller treats as "unrestricted / allow all".
			return [];
		}//end try
	}//end getConsumerAuthorisaties()

	/**
	 * The authorisation configuration of the consumer a request's bearer JWT names.
	 *
	 * Errors from the mapper propagate, so each caller keeps its own fail-closed answer.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return array|null The configuration (empty when the consumer declares none), or null when
	 *                    the mapper is unavailable, the JWT is malformed, it names no client, or
	 *                    no consumer matches
	 */
	private function getConsumerAuthConfig(IRequest $request): ?array {
		if ($this->consumerMapper === null) {
			return null;
		}

		$token = str_replace('Bearer ', '', $request->getHeader('Authorization'));
		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			return null;
		}

		$payload = json_decode(base64_decode($parts[1]), true);
		$clientId = $payload['client_id'] ?? ($payload['iss'] ?? null);
		if ($clientId === null) {
			return null;
		}

		$consumers = $this->consumerMapper->findAll(filters: ['name' => $clientId]);
		if (empty($consumers) === true) {
			return null;
		}

		$consumer = $consumers[0];
		if (method_exists($consumer, 'getAuthorizationConfiguration') === false) {
			return [];
		}

		return $consumer->getAuthorizationConfiguration() ?? [];
	}//end getConsumerAuthConfig()

	/**
	 * Publish a ZGW notification (non-blocking).
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $resourceUrl The resource URL
	 * @param string $action The action (create, update, destroy)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publishNotification(
		string $zgwApi,
		string $resource,
		string $resourceUrl,
		string $action,
	): void {
		$resourceKey = self::RESOURCE_MAP[$zgwApi][$resource] ?? $resource;

		$this->notificationService->publish(
			channel: $zgwApi,
			hoofdObject: $resourceUrl,
			resource: $resourceKey,
			resourceUrl: $resourceUrl,
			action: $action
		);
	}//end publishNotification()

	/**
	 * Build the error response data from a validation result.
	 *
	 * @param array $ruleResult The validation result from ZgwBusinessRulesService
	 *
	 * @return array The error response data with detail and optional invalidParams
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function buildValidationError(array $ruleResult): array {
		$data = ['detail' => $ruleResult['detail']];
		if (isset($ruleResult['code']) === true) {
			$data['code'] = $ruleResult['code'];
		}

		if (empty($ruleResult['invalidParams']) === false) {
			$data['invalidParams'] = $ruleResult['invalidParams'];
		}

		return $data;
	}//end buildValidationError()

	/**
	 * Return an "OpenRegister unavailable" error response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function unavailableResponse(): JSONResponse {
		return new JSONResponse(
			data: ['detail' => 'OpenRegister is not available'],
			statusCode: Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end unavailableResponse()

	/**
	 * Return a "mapping not found" error response.
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mappingNotFoundResponse(string $zgwApi, string $resource): JSONResponse {
		return new JSONResponse(
			data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end mappingNotFoundResponse()

	/**
	 * Generic index (list) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleIndex(IRequest $request, string $zgwApi, string $resource): JSONResponse {
		if ($this->objectService === null) {
			return $this->unavailableResponse();
		}

		$mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
		if ($mappingConfig === null) {
			return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
		}

		if (($mappingConfig['enabled'] ?? true) === false) {
			return new JSONResponse(
				data: ['detail' => "ZGW mapping for {$zgwApi}/{$resource} is disabled"],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$params = $request->getParams();
			$filters = $this->translateQueryParams(params: $params, mappingConfig: $mappingConfig);

			$page = max(1, (int)($params['page'] ?? 1));
			$pageSize = max(1, min(100, (int)($params['pageSize'] ?? 20)));

			$searchParams = array_merge(
				$filters,
				[
					'_limit' => $pageSize,
					'_offset' => (($page - 1) * $pageSize),
				]
			);

			$query = $this->objectService->buildSearchQuery(
				requestParams: $searchParams,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$result = $this->objectService->searchObjectsPaginated(
				query: $query
			);

			$objects = $result['results'] ?? [];
			$totalCount = $result['total'] ?? count($objects);
			$baseUrl = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);

			$outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = [];
			foreach ($objects as $object) {
				$objectData = $object;
				if (is_array($object) === false) {
					$objectData = $object->jsonSerialize();
				}

				$mapped[] = $this->applyOutboundMapping(
					objectData: $objectData,
					mapping: $outboundMapping,
					mappingConfig: $mappingConfig,
					baseUrl: $baseUrl
				);
			}

			$paginatedResult = $this->paginationHelper->wrapResults(
				mappedObjects: $mapped,
				totalCount: $totalCount,
				page: $page,
				pageSize: $pageSize,
				baseUrl: $baseUrl,
				queryParams: $params
			);

			return new JSONResponse(data: $paginatedResult);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW list error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Internal server error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end handleIndex()

	/**
	 * Generic create operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param bool $caseClosed Whether the parent zaak is closed (zrc-007)
	 * @param bool $hasForceer Whether the consumer has geforceerd-bijwerken scope
	 * @param bool $parentCaseTypeDraft Whether parent zaaktype is draft (ztc-010)
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   — ZGW scope flags from middleware
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleCreate(
		IRequest $request,
		string $zgwApi,
		string $resource,
		?bool $caseClosed = null,
		bool $hasForceer = true,
		?bool $parentCaseTypeDraft = null,
	): JSONResponse {
		if ($this->objectService === null) {
			return $this->unavailableResponse();
		}

		$mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
		if ($mappingConfig === null) {
			return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
		}

		try {
			$body = $this->getRequestBody(request: $request);

			$ruleResult = $this->businessRulesService->validate(
				zgwApi: $zgwApi,
				resource: $resource,
				action: 'create',
				body: $body,
				objectService: $this->objectService,
				mappingConfig: $mappingConfig,
				parentCaseTypeDraft: $parentCaseTypeDraft,
				caseClosed: $caseClosed,
				hasGeforceerd: $hasForceer
			);
			if ($ruleResult['valid'] === false) {
				return $this->ruleRefusal(ruleResult: $ruleResult);
			}

			// Map to English; direct OpenRegister fields (array fields like documentTypes/caseTypes
			// that Twig cannot handle) bypass the inbound mapping.
			$englishData = $this->mapInbound(enrichedBody: $ruleResult['enrichedBody'], mappingConfig: $mappingConfig)['data'];

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
			if (is_array($englishData) === false) {
				return new JSONResponse(
					data: ['detail' => 'Invalid mapping result'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			$object = $this->objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData
			);

			$outbound = $this->mapOutbound(request: $request, zgwApi: $zgwApi, resource: $resource, mappingConfig: $mappingConfig, object: $object);
			$objectUuid = $outbound['objectData']['id'] ?? ($outbound['objectData']['@self']['id'] ?? '');
			$this->publishNotification(
				zgwApi: $zgwApi,
				resource: $resource,
				resourceUrl: $outbound['baseUrl'] . '/' . $objectUuid,
				action: 'create'
			);

			return new JSONResponse(data: $outbound['mapped'], statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW create error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end handleCreate()

	/**
	 * Generic show (get single) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleShow(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
	): JSONResponse {
		if ($this->objectService === null) {
			return $this->unavailableResponse();
		}

		$mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
		if ($mappingConfig === null) {
			return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
		}

		try {
			$object = $this->objectService->find(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				id: $uuid
			);

			$baseUrl = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
			$outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);

			$objectData = $object;
			if (is_array($object) === false) {
				$objectData = $object->jsonSerialize();
			}

			$mapped = $this->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);

			return new JSONResponse(data: $mapped);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW show error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}//end try
	}//end handleShow()

	/**
	 * Generic update (PUT/PATCH) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param bool $partial Whether this is a partial update (PATCH)
	 * @param bool $parentZtDraft Whether parent zaaktype is draft (ztc-010)
	 * @param bool $caseClosed Whether the parent zaak is closed (zrc-007)
	 * @param bool $hasForceer Whether consumer has geforceerd-bijwerken
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   — ZGW scope flags from middleware
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleUpdate(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		bool $partial = false,
		?bool $parentZtDraft = null,
		?bool $caseClosed = null,
		bool $hasForceer = true,
	): JSONResponse {
		// Resolve UUID from URL path — Nextcloud's getParam() merges JSON body
		// into controller args, so a body "uuid" field can override the route's {uuid}.
		$uuid = $this->resolvePathUuid(request: $request, uuid: $uuid);

		if ($this->objectService === null) {
			return $this->unavailableResponse();
		}

		$mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
		if ($mappingConfig === null) {
			return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
		}

		try {
			$body = $this->getRequestBody(request: $request);

			$action = 'update';
			if ($partial === true) {
				$action = 'patch';
			}

			$ruleResult = $this->businessRulesService->validate(
				zgwApi: $zgwApi,
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $this->findSerialized(uuid: $uuid, mappingConfig: $mappingConfig),
				objectService: $this->objectService,
				mappingConfig: $mappingConfig,
				parentCaseTypeDraft: $parentZtDraft,
				caseClosed: $caseClosed,
				hasGeforceerd: $hasForceer
			);
			if ($ruleResult['valid'] === false) {
				return $this->ruleRefusal(ruleResult: $ruleResult);
			}

			// Map to English; direct OpenRegister fields bypass the Twig inbound mapping.
			$inbound = $this->mapInbound(enrichedBody: $ruleResult['enrichedBody'], mappingConfig: $mappingConfig);
			$englishData = $inbound['data'];
			$englishData['id'] = $uuid;

			// For partial updates (PATCH), merge with existing object data.
			if ($partial === true) {
				$existing = $this->objectService->find(
					$uuid,
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema']
				);
				$englishData = (new ZgwPatchMerger())->merge(
					existingData: $existing->jsonSerialize(),
					body: $body,
					englishData: $englishData,
					mappingConfig: $mappingConfig
				);
			}

			// Apply _directFields after PATCH merge to ensure they override correctly.
			if (empty($inbound['directFields']) === false) {
				$englishData = array_merge($englishData, $inbound['directFields']);
			}

			$object = $this->objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData,
				uuid: $uuid
			);

			$outbound = $this->mapOutbound(request: $request, zgwApi: $zgwApi, resource: $resource, mappingConfig: $mappingConfig, object: $object);
			$this->publishNotification(
				zgwApi: $zgwApi,
				resource: $resource,
				resourceUrl: $outbound['baseUrl'] . '/' . $uuid,
				action: 'update'
			);

			return new JSONResponse(data: $outbound['mapped']);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW update error (' . $resource . ' ' . $uuid . '): ' . $e->getMessage(),
				['exception' => $e, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end handleUpdate()

	/**
	 * The response for a business-rule refusal.
	 *
	 * @param array $ruleResult The failed rule result (`status`, `detail`, `invalidParams`)
	 *
	 * @return JSONResponse The ZGW validation error
	 */
	private function ruleRefusal(array $ruleResult): JSONResponse {
		return new JSONResponse(
			data: $this->buildValidationError(ruleResult: $ruleResult),
			statusCode: $ruleResult['status']
		);
	}//end ruleRefusal()

	/**
	 * Find a stored object and serialise it.
	 *
	 * @param string $uuid The object uuid
	 * @param array $mappingConfig The ZGW mapping (`sourceRegister`, `sourceSchema`)
	 *
	 * @return mixed The object as an array (or whatever the ObjectService returned as one)
	 */
	private function findSerialized(string $uuid, array $mappingConfig): mixed {
		$existingObj = $this->objectService->find(
			$uuid,
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema']
		);
		if (is_array($existingObj) === false) {
			return $existingObj->jsonSerialize();
		}

		return $existingObj;
	}//end findSerialized()

	/**
	 * Map a validated ZGW body to English field names.
	 *
	 * `_directFields` bypass the Twig inbound mapping (array fields Twig drops) and are merged
	 * over the mapped data.
	 *
	 * @param array $enrichedBody The body the business rules returned
	 * @param array $mappingConfig The ZGW mapping
	 *
	 * @return array{data: array, directFields: array} The mapped data and the direct fields
	 */
	private function mapInbound(array $enrichedBody, array $mappingConfig): array {
		$directFields = $enrichedBody['_directFields'] ?? [];
		unset($enrichedBody['_directFields']);

		$data = $this->applyInboundMapping(
			body: $enrichedBody,
			mapping: $this->createInboundMapping(mappingConfig: $mappingConfig),
			mappingConfig: $mappingConfig
		);
		if (empty($directFields) === false) {
			$data = array_merge($data, $directFields);
		}

		return ['data' => $data, 'directFields' => $directFields];
	}//end mapInbound()

	/**
	 * Map a saved object back to its ZGW shape.
	 *
	 * @param IRequest $request The request (for the base URL)
	 * @param string $zgwApi The ZGW API
	 * @param string $resource The ZGW resource
	 * @param array $mappingConfig The ZGW mapping
	 * @param mixed $object The saved object
	 *
	 * @return array{mapped: array, baseUrl: string, objectData: mixed} The ZGW object, its
	 *                                                                  collection URL and the
	 *                                                                  serialised object
	 */
	private function mapOutbound(IRequest $request, string $zgwApi, string $resource, array $mappingConfig, mixed $object): array {
		$objectData = $object;
		if (is_array($object) === false) {
			$objectData = $object->jsonSerialize();
		}

		$baseUrl = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
		$mapped = $this->applyOutboundMapping(
			objectData: $objectData,
			mapping: $this->createOutboundMapping(mappingConfig: $mappingConfig),
			mappingConfig: $mappingConfig,
			baseUrl: $baseUrl
		);

		return ['mapped' => $mapped, 'baseUrl' => $baseUrl, 'objectData' => $objectData];
	}//end mapOutbound()

	/**
	 * Generic destroy (delete) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param bool $parentZtDraft Whether parent zaaktype is draft (ztc-010)
	 * @param bool $caseClosed Whether the parent zaak is closed (zrc-007)
	 * @param bool $hasForceer Whether consumer has geforceerd-bijwerken
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — ZGW scope flags from middleware
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleDestroy(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		?bool $parentZtDraft = null,
		?bool $caseClosed = null,
		bool $hasForceer = true,
	): JSONResponse {
		if ($this->objectService === null) {
			return $this->unavailableResponse();
		}

		$mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
		if ($mappingConfig === null) {
			return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
		}

		try {
			$existingObj = $this->objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);

			$existingData = $existingObj;
			if (is_array($existingObj) === false) {
				$existingData = $existingObj->jsonSerialize();
			}

			$ruleResult = $this->businessRulesService->validate(
				zgwApi: $zgwApi,
				resource: $resource,
				action: 'destroy',
				body: [],
				existingObject: $existingData,
				objectService: $this->objectService,
				mappingConfig: $mappingConfig,
				parentCaseTypeDraft: $parentZtDraft,
				caseClosed: $caseClosed,
				hasGeforceerd: $hasForceer
			);
			if ($ruleResult['valid'] === false) {
				return new JSONResponse(
					data: $this->buildValidationError(ruleResult: $ruleResult),
					statusCode: $ruleResult['status']
				);
			}

			$this->objectService->deleteObject(uuid: $uuid);

			$baseUrl = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
			$this->publishNotification(
				zgwApi: $zgwApi,
				resource: $resource,
				resourceUrl: $baseUrl . '/' . $uuid,
				action: 'destroy'
			);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW delete error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}//end try
	}//end handleDestroy()

	/**
	 * Handle audit trail index — proxies to OpenRegister's audit trail.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleAudittrailIndex(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
	): JSONResponse {
		$resourceUrl = $this->buildBaseUrl(
			request: $request,
			zgwApi: $zgwApi,
			resource: $resource
		) . '/' . $uuid;

		// Fetch real audit trail from OpenRegister.
		$entries = [];
		if ($this->objectService !== null) {
			try {
				$logs = $this->objectService->getLogs($uuid, [], false, false);
				foreach ($logs as $log) {
					$entries[] = $this->mapAuditTrailToZgw(
						log: $log,
						resourceUrl: $resourceUrl,
						resource: $resource
					);
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Failed to fetch audit trail for ' . $uuid . ': ' . $e->getMessage()
				);
			}
		}

		// If no entries found, return a synthetic creation entry.
		if (empty($entries) === true) {
			$entries[] = [
				'uuid' => $uuid . '-audit-1',
				// `bron` and `applicatieId` are FROZEN at `procest`. They are the
				// ZGW audit-trail SOURCE identifiers this app writes into the
				// external zaaksysteem's audit trail; every entry already written
				// carries them and consumers filter on them, so renaming would
				// split one system's history into two. `applicatieWeergave` is a
				// display label and does follow the brand.
				'bron' => 'procest',
				'applicatieId' => 'procest',
				'applicatieWeergave' => 'Dossiq',
				'action' => 'create',
				'actieWeergave' => 'Object aangemaakt',
				'result' => 200,
				'hoofdObject' => $resourceUrl,
				'resource' => $resource,
				'resourceUrl' => $resourceUrl,
				'resourceWeergave' => $resource,
				'aanmaakdatum' => date('c'),
			];
		}

		return new JSONResponse(data: $entries);
	}//end handleAudittrailIndex()

	/**
	 * Handle audit trail show — proxies to OpenRegister's audit trail.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param string $auditUuid The audit trail entry UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleAudittrailShow(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		string $auditUuid,
	): JSONResponse {
		$resourceUrl = $this->buildBaseUrl(
			request: $request,
			zgwApi: $zgwApi,
			resource: $resource
		) . '/' . $uuid;

		// Try to find the specific audit trail entry from OpenRegister.
		if ($this->objectService !== null) {
			try {
				$logs = $this->objectService->getLogs($uuid, [], false, false);
				foreach ($logs as $log) {
					$logData = $log;
					if (is_array($log) === false) {
						$logData = $log->jsonSerialize();
					}

					if (($logData['uuid'] ?? '') === $auditUuid) {
						return new JSONResponse(
							data: $this->mapAuditTrailToZgw(log: $log, resourceUrl: $resourceUrl, resource: $resource)
						);
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Failed to fetch audit trail entry ' . $auditUuid . ': ' . $e->getMessage()
				);
			}
		}//end if

		// Fallback: return a synthetic entry with the requested UUID.
		return new JSONResponse(
			data: [
				'uuid' => $auditUuid,
				// `bron` and `applicatieId` are FROZEN at `procest`. They are the
				// ZGW audit-trail SOURCE identifiers this app writes into the
				// external zaaksysteem's audit trail; every entry already written
				// carries them and consumers filter on them, so renaming would
				// split one system's history into two. `applicatieWeergave` is a
				// display label and does follow the brand.
				'bron' => 'procest',
				'applicatieId' => 'procest',
				'applicatieWeergave' => 'Dossiq',
				'action' => 'create',
				'actieWeergave' => 'Object aangemaakt',
				'result' => 200,
				'hoofdObject' => $resourceUrl,
				'resource' => $resource,
				'resourceUrl' => $resourceUrl,
				'resourceWeergave' => $resource,
				'aanmaakdatum' => date('c'),
			]
		);
	}//end handleAudittrailShow()

	/**
	 * Map an OpenRegister AuditTrail entry to ZGW audittrail format.
	 *
	 * @param object|array $log The OpenRegister audit trail entry
	 * @param string $resourceUrl The ZGW resource URL
	 * @param string $resource The ZGW resource name
	 *
	 * @return array ZGW-formatted audit trail entry
	 */
	private function mapAuditTrailToZgw(
		object|array $log,
		string $resourceUrl,
		string $resource,
	): array {
		$logData = $log;
		if (is_array($log) === false) {
			$logData = $log->jsonSerialize();
		}

		// Map OpenRegister action names to ZGW actie names.
		$actionMap = [
			'save' => 'create',
			'create' => 'create',
			'update' => 'update',
			'patch' => 'partial_update',
			'delete' => 'destroy',
			'lock' => 'create',
			'unlock' => 'destroy',
			'publish' => 'update',
			'depublish' => 'update',
			'referential_integrity.cascade_delete' => 'destroy',
		];

		$actionDisplayMap = [
			'create' => 'Object aangemaakt',
			'update' => 'Object bijgewerkt',
			'partial_update' => 'Object deels bijgewerkt',
			'destroy' => 'Object verwijderd',
			'list' => 'Objecten opgevraagd',
			'retrieve' => 'Object opgevraagd',
		];

		$orAction = $logData['action'] ?? 'create';
		$zgwAction = $actionMap[$orAction] ?? $orAction;
		$weergave = $actionDisplayMap[$zgwAction] ?? ucfirst($orAction);

		return [
			'uuid' => $logData['uuid'] ?? '',
			'bron' => 'procest',
			'applicatieId' => $logData['user'] ?? 'procest',
			'applicatieWeergave' => $logData['userName'] ?? 'Dossiq',
			'action' => $zgwAction,
			'actieWeergave' => $weergave,
			'result' => 200,
			'hoofdObject' => $resourceUrl,
			'resource' => $resource,
			'resourceUrl' => $resourceUrl,
			'resourceWeergave' => $resource,
			'aanmaakdatum' => $logData['created'] ?? date('c'),
		];
	}//end mapAuditTrailToZgw()

	/**
	 * Resolve whether a zaak is closed (has einddatum set).
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The existing object data
	 *
	 * @return bool|null True if closed, false if open, null if N/A
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveZaakClosed(string $resource, array $existingData): ?bool {
		return $this->getParentStateResolver()->resolveZaakClosed(
			objectService: $this->objectService,
			resource: $resource,
			existingData: $existingData
		);
	}//end resolveZaakClosed()

	/**
	 * Resolve whether a zaak is closed from a request body (for sub-resource creation).
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $body The request body
	 *
	 * @return bool|null True if closed, false if open, null if N/A
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveZaakClosedFromBody(string $resource, array $body): ?bool {
		return $this->getParentStateResolver()->resolveZaakClosedFromBody(
			objectService: $this->objectService,
			resource: $resource,
			body: $body
		);
	}//end resolveZaakClosedFromBody()

	/**
	 * Resolve whether the parent zaaktype is in draft (concept) state.
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The existing sub-resource object data
	 *
	 * @return bool|null True if draft, false if published, null if N/A
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveParentZaaktypeDraft(string $resource, array $existingData): ?bool {
		return $this->getParentStateResolver()->resolveParentZaaktypeDraft(
			objectService: $this->objectService,
			resource: $resource,
			existingData: $existingData
		);
	}//end resolveParentZaaktypeDraft()

	/**
	 * Resolve parent zaaktype draft status from a request body (for sub-resource creation).
	 *
	 * Extracts the zaaktype URL/UUID from the body and looks up whether
	 * the zaaktype is still in draft (concept) state.
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $body The request body (Dutch field names)
	 *
	 * @return bool|null True if draft, false if published, null if N/A
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveParentZaaktypeDraftFromBody(string $resource, array $body): ?bool {
		return $this->getParentStateResolver()->resolveParentZaaktypeDraftFromBody(
			objectService: $this->objectService,
			resource: $resource,
			body: $body
		);
	}//end resolveParentZaaktypeDraftFromBody()

	/**
	 * The resolver for the parent zaak and parent zaaktype answers, built on first use.
	 *
	 * @return ZgwParentStateResolver
	 */
	private function getParentStateResolver(): ZgwParentStateResolver {
		if ($this->parentStateResolver === null) {
			$this->parentStateResolver = new ZgwParentStateResolver(
				zgwMappingService: $this->zgwMappingService,
				logger: $this->logger
			);
		}

		return $this->parentStateResolver;
	}//end getParentStateResolver()
}//end class
