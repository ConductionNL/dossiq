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

use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Service\Support\CallerIdentity;
use OCA\Dossiq\Service\Zgw\ResourceRoute;
use OCA\Dossiq\Service\Zgw\ResourceWrite;
use OCA\Dossiq\Service\Zgw\RuleFlags;
use OCA\Dossiq\Service\Zgw\StoreFailureAnswer;
use OCA\Dossiq\Service\Zgw\ZgwAuditedResource;
use OCA\Dossiq\Service\Zgw\ZgwInboundMapping;
use OCA\Dossiq\Service\Zgw\ZgwOutboundMapping;
use OCA\Dossiq\Service\Zgw\ZgwParentStateResolver;
use OCA\Dossiq\Service\Zgw\ZgwPatchMerger;
use OCA\OpenRegister\Db\Mapping;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwService implements ZgwServiceInterface {

	/**
	 * The fields an inbound mapping drops when they come back empty, unless the mapping names its own.
	 *
	 * @var array<int, string>
	 */
	private const INBOUND_NULLABLE = [
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

	/**
	 * OpenRegister audit trail actions by the ZGW actie they map to.
	 *
	 * @var array<string, string>
	 */
	private const AUDIT_ACTIONS = [
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

	/**
	 * How each ZGW actie is shown.
	 *
	 * @var array<string, string>
	 */
	private const AUDIT_ACTION_DISPLAY = [
		'create' => 'Object aangemaakt',
		'update' => 'Object bijgewerkt',
		'partial_update' => 'Object deels bijgewerkt',
		'destroy' => 'Object verwijderd',
		'list' => 'Objecten opgevraagd',
		'retrieve' => 'Object opgevraagd',
	];

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
	 * Sorts a failed store call into its answer (built on first use).
	 *
	 * @var StoreFailureAnswer|null
	 */
	private ?StoreFailureAnswer $storeFailures = null;

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
	 * @param CommunicationChannel|null $channels Maps a zaak's communicatiekanaal onto a channel slug (decision 171)
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
		private readonly ?CommunicationChannel $channels = null,
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
	 * The answer for a store call that failed: 404 not found, 403 not allowed, or 503 not reached.
	 *
	 * Every ZGW read, update and delete of a stored object answers its failures through here,
	 * so a store that was down never reads as an object that is not there (REQ-ZBR-023).
	 *
	 * @param \Throwable $error The failure.
	 * @param string $operation What was asked of the store (`ZGW delete`).
	 * @param string $object Which object (`besluiten <uuid>`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function storeFailureResponse(\Throwable $error, string $operation, string $object): JSONResponse {
		return $this->storeFailures()->respond(error: $error, operation: $operation, object: $object);
	}//end storeFailureResponse()

	/**
	 * The answer for an object the store does not hold.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notFoundResponse(): JSONResponse {
		return $this->storeFailures()->notFound();
	}//end notFoundResponse()

	/**
	 * The store failure sorter, built on first use.
	 *
	 * @return StoreFailureAnswer
	 */
	private function storeFailures(): StoreFailureAnswer {
		if ($this->storeFailures === null) {
			$this->storeFailures = new StoreFailureAnswer(logger: $this->logger);
		}

		return $this->storeFailures;
	}//end storeFailures()

	/**
	 * Load ZGW mapping configuration.
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return array|null The mapping configuration or null if not found
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyOutboundMapping(
		array $objectData,
		object $mapping,
		array $mappingConfig,
		string $baseUrl,
	): array {
		$outbound = new ZgwOutboundMapping(mapping: $mapping, mappingConfig: $mappingConfig, baseUrl: $baseUrl);

		return $this->mappedToZgw(objectData: $objectData, outbound: $outbound);
	}//end applyOutboundMapping()

	/**
	 * Map a stored object to its ZGW shape, as applyOutboundMapping() does.
	 *
	 * @param array              $objectData The English-language object data
	 * @param ZgwOutboundMapping $outbound   The mapping entity, configuration and collection URL
	 *
	 * @return array The mapped Dutch-language data
	 */
	private function mappedToZgw(array $objectData, ZgwOutboundMapping $outbound): array {
		$input = $this->outboundInput(objectData: $objectData, outbound: $outbound);
		$mapped = $this->mappingService->executeMapping(
			mapping: $outbound->mapping,
			input: $input
		);
		$mapped = $this->nullEmptyFields(mapped: $mapped, fields: ($outbound->mappingConfig['nullableFields'] ?? []));

		// Decision 171: the case holds a slug, a zaak answers with a URL.
		if (($outbound->mappingConfig['zgwResource'] ?? '') === 'zaak') {
			$mapped = $this->channels()->outbound(case: $input, mapped: $mapped);
		}

		return $mapped;
	}//end mappedToZgw()

	/**
	 * The object as the outbound mapping reads it: the markers it maps from.
	 *
	 * @param array              $objectData The English-language object data
	 * @param ZgwOutboundMapping $outbound   The mapping configuration and collection URL
	 *
	 * @return array The object with `_baseUrl`, `_valueMappings`, `_uuid`, `_created`, `_updated` and, for a document, `_downloadUrl`
	 */
	private function outboundInput(array $objectData, ZgwOutboundMapping $outbound): array {
		$objectData['_baseUrl'] = $outbound->baseUrl;
		$objectData['_valueMappings'] = $outbound->mappingConfig['valueMapping'] ?? [];
		$objectData = $this->withSelfMarkers(objectData: $objectData);

		if (($outbound->mappingConfig['zgwResource'] ?? '') === 'enkelvoudiginformatieobject' && $objectData['_uuid'] !== '') {
			$objectData['_downloadUrl'] = $outbound->baseUrl . '/' . $objectData['_uuid'] . '/download';
		}

		return $objectData;
	}//end outboundInput()

	/**
	 * The object with its uuid and its created and updated moments, from the object or its @self.
	 *
	 * @param array $objectData The object data
	 *
	 * @return array The object with `_uuid`, `_created` and `_updated`
	 */
	private function withSelfMarkers(array $objectData): array {
		$selfMeta = $objectData['@self'] ?? [];
		$objectData['_uuid'] = $objectData['id'] ?? ($selfMeta['id'] ?? '');
		$objectData['_created'] = $selfMeta['created'] ?? '';
		$objectData['_updated'] = $selfMeta['updated'] ?? '';

		return $objectData;
	}//end withSelfMarkers()

	/**
	 * The mapped object with these fields null where the mapping left them empty.
	 *
	 * @param array             $mapped The mapped data
	 * @param array<int, mixed> $fields The nullable fields
	 *
	 * @return array The mapped data
	 */
	private function nullEmptyFields(array $mapped, array $fields): array {
		foreach ($fields as $field) {
			if (array_key_exists($field, $mapped) === true && $mapped[$field] === '') {
				$mapped[$field] = null;
			}
		}

		return $mapped;
	}//end nullEmptyFields()

	/**
	 * Apply inbound mapping (Dutch to English) to request data.
	 *
	 * @param array $body The Dutch-language request body
	 * @param object $mapping The inbound mapping entity
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return array The mapped English-language data
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyInboundMapping(
		array $body,
		object $mapping,
		array $mappingConfig,
	): array {
		return $this->mappedToEnglish(body: $body, inbound: new ZgwInboundMapping(mapping: $mapping, mappingConfig: $mappingConfig));
	}//end applyInboundMapping()

	/**
	 * Map a Dutch request body to English, as applyInboundMapping() does.
	 *
	 * @param array             $body    The Dutch-language request body
	 * @param ZgwInboundMapping $inbound The mapping entity and configuration
	 *
	 * @return array The mapped English-language data
	 */
	private function mappedToEnglish(array $body, ZgwInboundMapping $inbound): array {
		$body['_valueMappings'] = $inbound->mappingConfig['valueMapping'] ?? [];
		unset($body['_route'], $body['zgwApi'], $body['resource'], $body['uuid']);

		$mapped = $this->mappingService->executeMapping(
			mapping: $inbound->mapping,
			input: $body
		);
		$mapped = $this->withoutEmptyNullables(mapped: $mapped, mappingConfig: $inbound->mappingConfig);

		// Decision 171: a ZGW communicatiekanaal URL becomes a channel slug
		// here, at the boundary, so the slug enum never refuses a zaak.
		if (($inbound->mappingConfig['zgwResource'] ?? '') === 'zaak') {
			$mapped = $this->channels()->inbound(body: $body, mapped: $mapped);
		}

		return $mapped;
	}//end mappedToEnglish()

	/**
	 * The mapped data without the nullable or date fields that came back empty.
	 *
	 * Removing them prevents OpenRegister from storing "" in date fields
	 * (which converts to today's date).
	 *
	 * @param array $mapped        The mapped data
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return array The mapped data
	 */
	private function withoutEmptyNullables(array $mapped, array $mappingConfig): array {
		foreach (($mappingConfig['inboundNullable'] ?? self::INBOUND_NULLABLE) as $key) {
			if (isset($mapped[$key]) === true && $mapped[$key] === '') {
				unset($mapped[$key]);
			}
		}

		return $mapped;
	}//end withoutEmptyNullables()

	/**
	 * The channel mapping, injected or built without an administrator's map.
	 *
	 * @return CommunicationChannel
	 */
	private function channels(): CommunicationChannel {
		return ($this->channels ?? new CommunicationChannel());
	}//end channels()

	/**
	 * Get the request body, falling back to raw body parsing for malformed JSON.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return array The parsed request body
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleCreate(
		IRequest $request,
		string $zgwApi,
		string $resource,
		?bool $caseClosed = null,
		bool $hasForceer = true,
		?bool $parentCaseTypeDraft = null,
	): JSONResponse {
		$route = new ResourceRoute(request: $request, api: $zgwApi, resource: $resource);
		$mappingConfig = $this->mappingOrRefusal(route: $route);
		if ($mappingConfig instanceof JSONResponse) {
			return $mappingConfig;
		}

		return $this->create(
			draft: new ResourceWrite(route: $route, mapping: $mappingConfig),
			flags: new RuleFlags(action: 'create', parentCaseTypeDraft: $parentCaseTypeDraft, caseClosed: $caseClosed, hasGeforceerd: $hasForceer)
		);
	}//end handleCreate()

	/**
	 * The mapping configuration of a route, or the answer when the store or the mapping is missing.
	 *
	 * @param ResourceRoute $route The route.
	 *
	 * @return array|JSONResponse The mapping configuration, or the 503 or 404 answer
	 */
	private function mappingOrRefusal(ResourceRoute $route): array|JSONResponse {
		if ($this->objectService === null) {
			return $this->unavailableResponse();
		}

		$mappingConfig = $this->loadMappingConfig(zgwApi: $route->api, resource: $route->resource);
		if ($mappingConfig === null) {
			return $this->mappingNotFoundResponse(zgwApi: $route->api, resource: $route->resource);
		}

		return $mappingConfig;
	}//end mappingOrRefusal()

	/**
	 * Ask the business rules about a write.
	 *
	 * @param ResourceWrite $write The write, with its body.
	 * @param RuleFlags     $flags The action and parent state flags.
	 *
	 * @return array The rule result (`valid`, `status`, `enrichedBody`, ...)
	 */
	private function checkRules(ResourceWrite $write, RuleFlags $flags): array {
		return $this->businessRulesService->validate(
			zgwApi: $write->route->api,
			resource: $write->route->resource,
			action: $flags->action,
			body: $write->body,
			existingObject: $write->existing,
			objectService: $this->objectService,
			mappingConfig: $write->mapping,
			parentCaseTypeDraft: $flags->parentCaseTypeDraft,
			caseClosed: $flags->caseClosed,
			hasGeforceerd: $flags->hasGeforceerd
		);
	}//end checkRules()

	/**
	 * Validate, store and announce a new object.
	 *
	 * @param ResourceWrite $draft The write, before its body is read.
	 * @param RuleFlags     $flags The action and parent state flags.
	 *
	 * @return JSONResponse The created object, or the refusal
	 */
	private function create(ResourceWrite $draft, RuleFlags $flags): JSONResponse {
		$route = $draft->route;
		try {
			$write = $draft->withBody(body: $this->getRequestBody(request: $route->request));
			$ruleResult = $this->checkRules(write: $write, flags: $flags);
			if ($ruleResult['valid'] === false) {
				return $this->ruleRefusal(ruleResult: $ruleResult);
			}

			// Map to English; direct OpenRegister fields (array fields like documentTypes/caseTypes
			// that Twig cannot handle) bypass the inbound mapping.
			$englishData = $this->mapInbound(enrichedBody: $ruleResult['enrichedBody'], mappingConfig: $write->mapping)['data'];

			// `_directFields` reach the payload unmapped, so a create drops the keys that name a stored object.
			$object = $this->objectService->saveObject(
				register: $write->mapping['sourceRegister'],
				schema: $write->mapping['sourceSchema'],
				object: array_diff_key($englishData, CallerIdentity::KEYS)
			);

			$outbound = $this->outboundFor(write: $write, object: $object);
			$objectUuid = $outbound['objectData']['id'] ?? ($outbound['objectData']['@self']['id'] ?? '');
			$this->publishNotification(
				zgwApi: $route->api,
				resource: $route->resource,
				resourceUrl: $outbound['baseUrl'] . '/' . $objectUuid,
				action: 'create'
			);

			return new JSONResponse(data: $outbound['mapped'], statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->logger->error('ZGW create error: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(data: ['detail' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end create()

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
		} catch (\Throwable $e) {
			return $this->storeFailureResponse(error: $e, operation: 'ZGW show', object: $resource . ' ' . $uuid);
		}

		if ($object === null) {
			return $this->notFoundResponse();
		}

		try {
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
		$route = new ResourceRoute(request: $request, api: $zgwApi, resource: $resource, uuid: $this->resolvePathUuid(request: $request, uuid: $uuid));
		$action = match ($partial) { true => 'patch', default => 'update' };
		$flags = new RuleFlags(action: $action, parentCaseTypeDraft: $parentZtDraft, caseClosed: $caseClosed, hasGeforceerd: $hasForceer);

		return $this->updateRoute(route: $route, flags: $flags);
	}//end handleUpdate()

	/**
	 * Update the stored object a route names, or answer why it cannot be read.
	 *
	 * @param ResourceRoute $route The route, with the object id.
	 * @param RuleFlags     $flags The action (update or patch) and parent state flags.
	 *
	 * @return JSONResponse The updated object, or the refusal
	 */
	private function updateRoute(ResourceRoute $route, RuleFlags $flags): JSONResponse {
		$draft = $this->existingWrite(route: $route, operation: 'ZGW update');
		if ($draft instanceof JSONResponse) {
			return $draft;
		}

		return $this->update(draft: $draft, flags: $flags);
	}//end updateRoute()

	/**
	 * The write on a stored object, or the answer when the store, the mapping or the object is missing.
	 *
	 * @param ResourceRoute $route     The route, with the object id.
	 * @param string        $operation What the request asks (`ZGW delete`), for the log
	 *
	 * @return ResourceWrite|JSONResponse The write, or the 503, 404 or 403 answer
	 */
	private function existingWrite(ResourceRoute $route, string $operation): ResourceWrite|JSONResponse {
		$mappingConfig = $this->mappingOrRefusal(route: $route);
		if ($mappingConfig instanceof JSONResponse) {
			return $mappingConfig;
		}

		$existingData = $this->readExisting(uuid: $route->uuid, mappingConfig: $mappingConfig, operation: $operation, resource: $route->resource);
		if ($existingData instanceof JSONResponse) {
			return $existingData;
		}

		return new ResourceWrite(route: $route, mapping: $mappingConfig, existing: $existingData);
	}//end existingWrite()

	/**
	 * Validate, store and announce an update of a stored object.
	 *
	 * @param ResourceWrite $draft The write on the stored object, before its body is read.
	 * @param RuleFlags     $flags The action (update or patch) and parent state flags.
	 *
	 * @return JSONResponse The updated object, or the refusal
	 */
	private function update(ResourceWrite $draft, RuleFlags $flags): JSONResponse {
		$route = $draft->route;
		try {
			$write = $draft->withBody(body: $this->getRequestBody(request: $route->request));
			$ruleResult = $this->checkRules(write: $write, flags: $flags);
			if ($ruleResult['valid'] === false) {
				return $this->ruleRefusal(ruleResult: $ruleResult);
			}

			$object = $this->objectService->saveObject(
				register: $write->mapping['sourceRegister'],
				schema: $write->mapping['sourceSchema'],
				object: $this->updatedData(write: $write, ruleResult: $ruleResult + ['patch' => $flags->isPatch()]),
				uuid: $route->uuid
			);

			$outbound = $this->outboundFor(write: $write, object: $object);
			$this->publishNotification(
				zgwApi: $route->api,
				resource: $route->resource,
				resourceUrl: $outbound['baseUrl'] . '/' . $route->uuid,
				action: 'update'
			);

			return new JSONResponse(data: $outbound['mapped']);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW update error (' . $route->objectLabel() . '): ' . $e->getMessage(),
				['exception' => $e, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['detail' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end update()

	/**
	 * The English data an update stores: mapped, merged over the stored object for a patch.
	 *
	 * @param ResourceWrite $write      The write, with its body and stored object.
	 * @param array         $ruleResult The rule result, plus `patch` for a partial update.
	 *
	 * @return array The data to save
	 */
	private function updatedData(ResourceWrite $write, array $ruleResult): array {
		// Map to English; direct OpenRegister fields bypass the Twig inbound mapping.
		$inbound = $this->mapInbound(enrichedBody: $ruleResult['enrichedBody'], mappingConfig: $write->mapping);
		$englishData = $inbound['data'];
		$englishData['id'] = $write->route->uuid;

		// For partial updates (PATCH), merge with the existing object read above.
		if ($ruleResult['patch'] === true) {
			$englishData = (new ZgwPatchMerger())->merge(
				existingData: $write->existing,
				body: $write->body,
				englishData: $englishData,
				mappingConfig: $write->mapping
			);
		}

		// Apply _directFields after PATCH merge to ensure they override correctly.
		if (empty($inbound['directFields']) === false) {
			$englishData = array_merge($englishData, $inbound['directFields']);
		}

		return $englishData;
	}//end updatedData()

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
	 * The stored object an update or delete acts on, or the answer when it cannot be read.
	 *
	 * @param string $uuid The object uuid
	 * @param array $mappingConfig The ZGW mapping (`sourceRegister`, `sourceSchema`)
	 * @param string $operation What the request asks (`ZGW delete`), for the log
	 * @param string $resource The ZGW resource name, for the log
	 *
	 * @return array|JSONResponse The object as an array, or the 404, 403 or 503 answer
	 */
	private function readExisting(string $uuid, array $mappingConfig, string $operation, string $resource): array|JSONResponse {
		try {
			$existing = $this->findSerialized(uuid: $uuid, mappingConfig: $mappingConfig);
		} catch (\Throwable $e) {
			return $this->storeFailureResponse(error: $e, operation: $operation, object: $resource . ' ' . $uuid);
		}

		return $existing ?? $this->notFoundResponse();
	}//end readExisting()

	/**
	 * Find a stored object and serialise it.
	 *
	 * @param string $uuid The object uuid
	 * @param array $mappingConfig The ZGW mapping (`sourceRegister`, `sourceSchema`)
	 *
	 * @return array|null The object as an array, or null when the store answered none
	 */
	private function findSerialized(string $uuid, array $mappingConfig): ?array {
		$existingObj = $this->objectService->find(
			$uuid,
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema']
		);
		if ($existingObj === null) {
			return null;
		}

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
	 * Map a written object back to the ZGW shape of its route.
	 *
	 * @param ResourceWrite $write  The write.
	 * @param mixed         $object The saved object.
	 *
	 * @return array{mapped: array, baseUrl: string, objectData: mixed} The ZGW object, its
	 *                                                                  collection URL and the
	 *                                                                  serialised object
	 */
	private function outboundFor(ResourceWrite $write, mixed $object): array {
		$objectData = $object;
		if (is_array($object) === false) {
			$objectData = $object->jsonSerialize();
		}

		$route = $write->route;
		$baseUrl = $this->buildBaseUrl(request: $route->request, zgwApi: $route->api, resource: $route->resource);
		$mapped = $this->applyOutboundMapping(
			objectData: $objectData,
			mapping: $this->createOutboundMapping(mappingConfig: $write->mapping),
			mappingConfig: $write->mapping,
			baseUrl: $baseUrl
		);

		return ['mapped' => $mapped, 'baseUrl' => $baseUrl, 'objectData' => $objectData];
	}//end outboundFor()

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
		$route = new ResourceRoute(request: $request, api: $zgwApi, resource: $resource, uuid: $uuid);
		$flags = new RuleFlags(action: 'destroy', parentCaseTypeDraft: $parentZtDraft, caseClosed: $caseClosed, hasGeforceerd: $hasForceer);

		return $this->destroyRoute(route: $route, flags: $flags);
	}//end handleDestroy()

	/**
	 * Delete the stored object a route names, or answer why it cannot be read.
	 *
	 * @param ResourceRoute $route The route, with the object id.
	 * @param RuleFlags     $flags The destroy action and parent state flags.
	 *
	 * @return JSONResponse The answer, or the refusal
	 */
	private function destroyRoute(ResourceRoute $route, RuleFlags $flags): JSONResponse {
		$write = $this->existingWrite(route: $route, operation: 'ZGW delete');
		if ($write instanceof JSONResponse) {
			return $write;
		}

		return $this->destroy(write: $write, flags: $flags);
	}//end destroyRoute()

	/**
	 * Validate and delete a stored object, then announce it.
	 *
	 * @param ResourceWrite $write The write on the stored object.
	 * @param RuleFlags     $flags The action and parent state flags.
	 *
	 * @return JSONResponse The 204, or the refusal
	 */
	private function destroy(ResourceWrite $write, RuleFlags $flags): JSONResponse {
		$route = $write->route;
		try {
			$ruleResult = $this->checkRules(write: $write, flags: $flags);
			if ($ruleResult['valid'] === false) {
				return $this->ruleRefusal(ruleResult: $ruleResult);
			}

			$this->objectService->deleteObject(uuid: $route->uuid);
		} catch (\Throwable $e) {
			return $this->storeFailureResponse(error: $e, operation: 'ZGW delete', object: $route->objectLabel());
		}//end try

		// The object is gone. A notification that fails does not make the delete fail.
		$this->notifyDeleted(request: $route->request, zgwApi: $route->api, resource: $route->resource, uuid: $route->uuid);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroy()

	/**
	 * Publish the notification of a delete that happened, logging a failure instead of answering it.
	 *
	 * @param IRequest $request The request.
	 * @param string $zgwApi The ZGW API group.
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The deleted object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notifyDeleted(IRequest $request, string $zgwApi, string $resource, string $uuid): void {
		try {
			$baseUrl = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
			$this->publishNotification(
				zgwApi: $zgwApi,
				resource: $resource,
				resourceUrl: $baseUrl . '/' . $uuid,
				action: 'destroy'
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ZGW delete of {resource} {uuid} happened, but its notification failed: ' . $e->getMessage(),
				['resource' => $resource, 'uuid' => $uuid, 'exception' => $e]
			);
		}
	}//end notifyDeleted()

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleAudittrailIndex(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
	): JSONResponse {
		return $this->auditTrailIndex(route: new ResourceRoute(request: $request, api: $zgwApi, resource: $resource, uuid: $uuid));
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleAudittrailShow(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		string $auditUuid,
	): JSONResponse {
		$route = new ResourceRoute(request: $request, api: $zgwApi, resource: $resource, uuid: $uuid);

		return $this->auditTrailEntry(route: $route, auditUuid: $auditUuid);
	}//end handleAudittrailShow()

	/**
	 * The audit trail of one object, or one synthetic creation entry when it has none.
	 *
	 * @param ResourceRoute $route The route, with the object id.
	 *
	 * @return JSONResponse
	 */
	private function auditTrailIndex(ResourceRoute $route): JSONResponse {
		$audited = $this->auditedResource(route: $route);

		// Fetch real audit trail from OpenRegister.
		$entries = $this->auditTrailEntries(audited: $audited);

		// If no entries found, return a synthetic creation entry.
		if (empty($entries) === true) {
			$entries[] = $this->mapAuditTrailToZgw(log: ['uuid' => $route->uuid . '-audit-1'], audited: $audited);
		}

		return new JSONResponse(data: $entries);
	}//end auditTrailIndex()

	/**
	 * One entry of an object's audit trail, or a synthetic one under the asked uuid when it is not found.
	 *
	 * @param ResourceRoute $route     The route, with the object id.
	 * @param string        $auditUuid The audit trail entry UUID.
	 *
	 * @return JSONResponse
	 */
	private function auditTrailEntry(ResourceRoute $route, string $auditUuid): JSONResponse {
		$audited = $this->auditedResource(route: $route);

		// Try to find the specific audit trail entry from OpenRegister; fall
		// back to a synthetic entry with the requested UUID.
		return new JSONResponse(
			data: $this->auditEntry(audited: $audited, auditUuid: $auditUuid) ?? $this->mapAuditTrailToZgw(log: ['uuid' => $auditUuid], audited: $audited)
		);
	}//end auditTrailEntry()

	/**
	 * The object an audit trail is read for, with its ZGW URL.
	 *
	 * @param ResourceRoute $route The route, with the object id.
	 *
	 * @return ZgwAuditedResource
	 */
	private function auditedResource(ResourceRoute $route): ZgwAuditedResource {
		$url = $this->buildBaseUrl(request: $route->request, zgwApi: $route->api, resource: $route->resource) . '/' . $route->uuid;

		return new ZgwAuditedResource(resource: $route->resource, uuid: $route->uuid, url: $url);
	}//end auditedResource()

	/**
	 * Every entry of the object's audit trail in OpenRegister, mapped; on a failure, those read before it.
	 *
	 * @param ZgwAuditedResource $audited The object.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function auditTrailEntries(ZgwAuditedResource $audited): array {
		$entries = [];
		try {
			foreach ($this->auditLogs(audited: $audited) as $log) {
				$entries[] = $this->mapAuditTrailToZgw(log: $log, audited: $audited);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Failed to fetch audit trail for ' . $audited->uuid . ': ' . $e->getMessage()
			);
		}

		return $entries;
	}//end auditTrailEntries()

	/**
	 * The entry of the object's audit trail in OpenRegister with this uuid, mapped.
	 *
	 * @param ZgwAuditedResource $audited   The object.
	 * @param string             $auditUuid The audit trail entry UUID.
	 *
	 * @return array<string, mixed>|null The entry, or null when there is none or it cannot be read.
	 */
	private function auditEntry(ZgwAuditedResource $audited, string $auditUuid): ?array {
		try {
			foreach ($this->auditLogs(audited: $audited) as $log) {
				if (($this->auditLogData(log: $log)['uuid'] ?? '') === $auditUuid) {
					return $this->mapAuditTrailToZgw(log: $log, audited: $audited);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Failed to fetch audit trail entry ' . $auditUuid . ': ' . $e->getMessage()
			);
		}

		return null;
	}//end auditEntry()

	/**
	 * The object's audit trail entries in OpenRegister, none when OpenRegister is absent.
	 *
	 * @param ZgwAuditedResource $audited The object.
	 *
	 * @return iterable<object|array> The entries, as OpenRegister answers them.
	 */
	private function auditLogs(ZgwAuditedResource $audited): iterable {
		if ($this->objectService === null) {
			return [];
		}

		return $this->objectService->getLogs($audited->uuid, [], false, false);
	}//end auditLogs()

	/**
	 * An OpenRegister audit trail entry as an array.
	 *
	 * @param object|array $log The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function auditLogData(object|array $log): array {
		if (is_array($log) === true) {
			return $log;
		}

		return $log->jsonSerialize();
	}//end auditLogData()

	/**
	 * Map an OpenRegister AuditTrail entry to ZGW audittrail format.
	 *
	 * A log that carries only a uuid maps to the synthetic creation entry.
	 *
	 * @param object|array       $log     The OpenRegister audit trail entry
	 * @param ZgwAuditedResource $audited The object the entry is about
	 *
	 * @return array ZGW-formatted audit trail entry
	 */
	private function mapAuditTrailToZgw(object|array $log, ZgwAuditedResource $audited): array {
		$logData = $this->auditLogData(log: $log);

		return [
			'uuid' => $logData['uuid'] ?? '',
			// `bron` and `applicatieId` are FROZEN at `procest`. They are the
			// ZGW audit-trail SOURCE identifiers this app writes into the
			// external zaaksysteem's audit trail; every entry already written
			// carries them and consumers filter on them, so renaming would
			// split one system's history into two. `applicatieWeergave` is a
			// display label and does follow the brand.
			'bron' => 'procest',
			'applicatieId' => $logData['user'] ?? 'procest',
			'applicatieWeergave' => $logData['userName'] ?? 'Dossiq',
			...$this->auditAction(logData: $logData),
			'result' => 200,
			...$audited->zgwFields(),
			'aanmaakdatum' => $logData['created'] ?? date('c'),
		];
	}//end mapAuditTrailToZgw()

	/**
	 * The ZGW actie of an OpenRegister audit trail entry, and how it is shown.
	 *
	 * @param array<string, mixed> $logData The entry.
	 *
	 * @return array{action: string, actieWeergave: string}
	 */
	private function auditAction(array $logData): array {
		$orAction = $logData['action'] ?? 'create';
		$zgwAction = self::AUDIT_ACTIONS[$orAction] ?? $orAction;

		return ['action' => $zgwAction, 'actieWeergave' => self::AUDIT_ACTION_DISPLAY[$zgwAction] ?? ucfirst($orAction)];
	}//end auditAction()

	/**
	 * Resolve whether a zaak is closed (has einddatum set).
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The existing object data
	 *
	 * @return bool|null True if closed, false if open, null if N/A
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
