<?php

/**
 * Dossiq DRC (Documenten) Controller
 *
 * Controller for serving ZGW Documenten API endpoints (enkelvoudiginformatieobjecten,
 * objectinformatieobjecten, gebruiksrechten, verzendingen). Handles EIO-specific
 * features: base64 file content, document locking, and file downloads.
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
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-1
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Service\Zgw\RelationSearch;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZgwSearchScope;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * DRC (Documenten) API Controller
 *
 * Handles ZGW Documenten register resources with EIO-specific features:
 * base64 file content handling, document locking/unlocking, and downloads.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
class DrcController extends ZgwController {
	/**
	 * The ZGW API identifier for the Documenten register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'documenten';

	/**
	 * The EIO resource name.
	 *
	 * @var string
	 */
	private const EIO_RESOURCE = 'enkelvoudiginformatieobjecten';

	/**
	 * The API and resource of each mapping that holds relations to a document: OIO, ZIO and BIO.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private const DOCUMENT_RELATIONS = [
		[self::ZGW_API, 'objectinformatieobjecten'],
		['zaken', 'zaakinformatieobjecten'],
		['besluiten', 'besluitinformatieobjecten'],
	];

	/**
	 * Default chunk size for bestandsdelen (10 MB).
	 *
	 * @var int
	 */
	private const DEFAULT_CHUNK_SIZE = 10485760;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwServiceInterface $zgwService The shared ZGW service.
	 * @param IL10N $l10n The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwServiceInterface $zgwService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List resources of the given type.
	 *
	 * @param string $resource The ZGW resource name (e.g. enkelvoudiginformatieobjecten).
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_READ, period: 60)]
	public function index(string $resource): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// ObjectInformatieObjecten and Gebruiksrechten return a plain array per ZGW spec.
		if ($resource === 'objectinformatieobjecten' || $resource === 'gebruiksrechten') {
			return $this->indexFlatArray(resource: $resource);
		}

		return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
	}//end index()

	/**
	 * List DRC resources as a plain array (per ZGW spec).
	 *
	 * Used for objectinformatieobjecten and gebruiksrechten which return
	 * flat arrays instead of paginated results.
	 *
	 * @param string $resource The ZGW resource name
	 *
	 * @return JSONResponse
	 */
	private function indexFlatArray(string $resource): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			return new JSONResponse(data: $this->listFlatArray(objectService: $objectService, resource: $resource, mappingConfig: $mappingConfig));
		} catch (\Throwable $e) {
			return $this->flatArrayErrorResponse(resource: $resource, e: $e);
		}
	}//end indexFlatArray()

	/**
	 * Search the flat-array resource and map every hit outbound.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $resource      The ZGW resource name
	 * @param array  $mappingConfig The resource mapping configuration
	 *
	 * @return array The mapped ZGW objects
	 */
	private function listFlatArray(object $objectService, string $resource, array $mappingConfig): array {
		$params = $this->request->getParams();
		$filters = $this->zgwService->translateQueryParams(
			params: $params,
			mappingConfig: $mappingConfig
		);

		$searchParams = array_merge($filters, ['_limit' => 100]);

		$query = $objectService->buildSearchQuery(
			requestParams: $searchParams,
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema']
		);
		$result = $objectService->searchObjectsPaginated(query: $query);

		return $this->mapFlatArrayObjects(objects: $result['results'] ?? [], resource: $resource, mappingConfig: $mappingConfig);
	}//end listFlatArray()

	/**
	 * Apply the outbound mapping to each object of a flat-array listing.
	 *
	 * @param array  $objects       The OpenRegister objects
	 * @param string $resource      The ZGW resource name
	 * @param array  $mappingConfig The resource mapping configuration
	 *
	 * @return array The mapped ZGW objects
	 */
	private function mapFlatArrayObjects(array $objects, string $resource, array $mappingConfig): array {
		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
		$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
		$mapped = [];
		foreach ($objects as $object) {
			$objectData = $this->objectToArray(row: $object);

			$mapped[] = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);
		}

		return $mapped;
	}//end mapFlatArrayObjects()

	/**
	 * Log a flat-array listing failure and build the 500 response.
	 *
	 * @param string     $resource The ZGW resource name
	 * @param \Throwable $e        The caught failure
	 *
	 * @return JSONResponse
	 */
	private function flatArrayErrorResponse(string $resource, \Throwable $e): JSONResponse {
		$this->zgwService->getLogger()->error(
			'DRC list ' . $resource . ' error: ' . $e->getMessage(),
			['exception' => $e]
		);
		return new JSONResponse(
			data: ['detail' => 'Internal server error'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end flatArrayErrorResponse()

	/**
	 * Create a new resource of the given type.
	 *
	 * For EIO resources, handles base64 file content (inhoud field) by storing
	 * the file separately via the document service after saving the object.
	 *
	 * @param string $resource The ZGW resource name.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function create(string $resource): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate creates on documenten.aanmaken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.aanmaken') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.aanmaken');
		}

		// Drc-006 (VNG): Gebruiksrechten create — set indicatieGebruiksrecht to true on EIO.
		if ($resource === 'gebruiksrechten') {
			$response = $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
			if ($response->getStatus() === Http::STATUS_CREATED) {
				$this->updateIndicationGebruiksrecht(response: $response);
			}

			return $response;
		}

		// For non-EIO resources, use generic create.
		if ($resource !== self::EIO_RESOURCE) {
			return $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
		}

		return $this->createEio(resource: $resource);
	}//end create()

	/**
	 * Create an enkelvoudiginformatieobject: validate, map, save, store its inhoud or open a
	 * chunked upload, and answer the ZGW object.
	 *
	 * @param string $resource The ZGW resource name (the EIO resource).
	 *
	 * @return JSONResponse
	 */
	private function createEio(string $resource): JSONResponse {
		// EIO-specific: handle inhoud (base64 file content).
		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			return $this->createValidatedEio(resource: $resource, mappingConfig: $mappingConfig);
		} catch (\Throwable $e) {
			return $this->loggedBadRequest(e: $e, logPrefix: 'DRC create error: ');
		}//end try
	}//end createEio()

	/**
	 * Validate the request body, save the document and answer it with its bestandsdelen.
	 *
	 * @param string $resource The ZGW resource name (the EIO resource).
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Throwable When validating, saving or storing fails.
	 */
	private function createValidatedEio(string $resource, array $mappingConfig): JSONResponse {
		$body = $this->validatedEioBody(resource: $resource, mappingConfig: $mappingConfig);
		if ($body instanceof JSONResponse) {
			return $body;
		}

		$bestandsomvang = (int)($body['bestandsomvang'] ?? 0);
		[$objectData, $objectUuid] = $this->saveNewEio(body: $body, mappingConfig: $mappingConfig);

		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
		$mapped = $this->mapEioOut(objectData: $objectData, mappingConfig: $mappingConfig, baseUrl: $baseUrl);

		// Add bestandsdelen for chunked upload responses.
		$mapped['bestandsdelen'] = $this->pendingBestandsdelen(objectData: $objectData, uuid: $objectUuid, fileSize: $bestandsomvang);

		$this->zgwService->publishNotification(
			self::ZGW_API,
			$resource,
			$baseUrl . '/' . $objectUuid,
			'create'
		);

		return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
	}//end createValidatedEio()

	/**
	 * Run the create business rules over the request body.
	 *
	 * @param string $resource The ZGW resource name (the EIO resource).
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array|JSONResponse The enriched body, or the validation error.
	 */
	private function validatedEioBody(string $resource, array $mappingConfig): array|JSONResponse {
		$body = $this->zgwService->getRequestBody($this->request);

		$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
			zgwApi: self::ZGW_API,
			resource: $resource,
			action: 'create',
			body: $body,
			objectService: $this->zgwService->getObjectService(),
			mappingConfig: $mappingConfig
		);
		if ($ruleResult['valid'] === false) {
			return new JSONResponse(
				data: $this->zgwService->buildValidationError($ruleResult),
				statusCode: $ruleResult['status']
			);
		}

		return $ruleResult['enrichedBody'];
	}//end validatedEioBody()

	/**
	 * Save a new document from its validated body and store its inhoud when one was sent.
	 *
	 * @param array $body The validated body.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array{0: array, 1: string} The saved document and its uuid.
	 */
	private function saveNewEio(array $body, array $mappingConfig): array {
		$inhoud = $body['inhoud'] ?? null;

		$englishData = $this->eioEnglishData(body: $body, mappingConfig: $mappingConfig);

		$object = $this->zgwService->getObjectService()->saveObject(
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema'],
			object: $englishData
		);
		$objectData = $this->objectToArray(row: $object);

		$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

		// Store file content (only when inhoud is provided).
		if (empty($inhoud) === false && $objectUuid !== '') {
			$objectData = $this->storeInhoud(objectData: $objectData, objectUuid: $objectUuid, inhoud: $inhoud, mappingConfig: $mappingConfig);
		}

		return [$objectData, $objectUuid];
	}//end saveNewEio()

	/**
	 * Map a validated EIO body to English field names.
	 *
	 * Content sent as inhoud is stored as a file, so it never travels in the object. Without
	 * inhoud, a declared bestandsomvang opens a chunked upload: fileParts is set BEFORE the
	 * first save, which spares a second round-trip.
	 *
	 * @param array $body The validated body.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array The object to save.
	 */
	private function eioEnglishData(array $body, array $mappingConfig): array {
		$englishData = $this->mapEioBody(body: $body, mappingConfig: $mappingConfig);
		if (empty($body['inhoud'] ?? null) === false) {
			return $englishData;
		}

		$bestandsomvang = (int)($body['bestandsomvang'] ?? 0);
		if ($bestandsomvang > 0) {
			$englishData['fileParts'] = $this->pendingFileParts(fileSize: $bestandsomvang);
		}

		return $englishData;
	}//end eioEnglishData()

	/**
	 * Map an EIO body to English field names; content sent as inhoud is stored as a file and
	 * never travels in the object.
	 *
	 * @param array $body The validated body.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array The mapped object.
	 */
	private function mapEioBody(array $body, array $mappingConfig): array {
		$englishData = $this->zgwService->applyInboundMapping(
			body: $body,
			mapping: $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig),
			mappingConfig: $mappingConfig
		);

		if (empty($body['inhoud'] ?? null) === false) {
			unset($englishData['content']);
		}

		return $englishData;
	}//end mapEioBody()

	/**
	 * Map a saved document back to its ZGW shape.
	 *
	 * @param array $objectData The saved document.
	 * @param array $mappingConfig The EIO mapping.
	 * @param string $baseUrl The collection URL.
	 *
	 * @return array The ZGW document.
	 */
	private function mapEioOut(array $objectData, array $mappingConfig, string $baseUrl): array {
		return $this->zgwService->applyOutboundMapping(
			objectData: $objectData,
			mapping: $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig),
			mappingConfig: $mappingConfig,
			baseUrl: $baseUrl
		);
	}//end mapEioOut()

	/**
	 * The fileParts marker of a document whose content arrives in chunks.
	 *
	 * @param int $fileSize The declared bestandsomvang.
	 *
	 * @return string The JSON-encoded marker.
	 */
	private function pendingFileParts(int $fileSize): string {
		return (string)json_encode(
			[
				'pending' => true,
				'totalParts' => (int)ceil($fileSize / self::DEFAULT_CHUNK_SIZE),
				'chunkSize' => self::DEFAULT_CHUNK_SIZE,
				'fileSize' => $fileSize,
			]
		);
	}//end pendingFileParts()

	/**
	 * Store a document's base64 inhoud and stamp its size and file id on the object.
	 *
	 * @param array $objectData The saved document.
	 * @param string $objectUuid The document uuid.
	 * @param mixed $inhoud The base64 content.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array The document, with fileSize and fileId when they were stamped.
	 */
	private function storeInhoud(array $objectData, string $objectUuid, mixed $inhoud, array $mappingConfig): array {
		$fileName = $this->documentFileName(objectData: $objectData);

		$fileSize = $this->zgwService->getDocumentService()->storeBase64(uuid: $objectUuid, fileName: $fileName, content: $inhoud);

		// The file id, and NOT only the size. Three surfaces resolve a
		// document through `fileId` and each of them fails SILENTLY
		// without one: `openInFiles()` and `VersionHistoryPanel` both
		// return early on a falsy id, and Files comments hang off the
		// same node. Only the upload path stamped it, so a document
		// created over this API rendered an Open in Files action, a
		// Version history action and a comments sidebar that all did
		// nothing and reported nothing.
		//
		// The old guard is why: keyed on `fileSize` alone, a caller
		// that supplied `bestandsomvang` skipped the write entirely
		// and the id never landed at all.
		$fileId = $this->resolveStoredFileId(uuid: $objectUuid, fileName: $fileName);
		$needsSize = (empty($objectData['fileSize']) === true);
		$needsFileId = ($fileId > 0 && (int)($objectData['fileId'] ?? 0) !== $fileId);

		if ($needsSize === true || $needsFileId === true) {
			$objectData = $this->withFileFacts(objectData: $objectData, needsSize: $needsSize, fileSize: $fileSize, fileId: $fileId);
			$objectData = $this->saveWholeEio(objectData: $objectData, objectUuid: $objectUuid, mappingConfig: $mappingConfig);
		}

		return $objectData;
	}//end storeInhoud()

	/**
	 * The file name a document's content is stored under: its fileName, or 'document'.
	 *
	 * @param array $objectData The stored document.
	 *
	 * @return string The file name.
	 */
	private function documentFileName(array $objectData): string {
		$fileName = $objectData['fileName'] ?? 'document';
		if ($fileName === '') {
			$fileName = 'document';
		}

		return $fileName;
	}//end documentFileName()

	/**
	 * Stamp the stored file's size (when the document has none) and its file id on a document.
	 *
	 * @param array $objectData The saved document.
	 * @param bool $needsSize Whether the document still lacks a fileSize.
	 * @param int $fileSize The stored file's size.
	 * @param int $fileId The stored file's id, 0 when unresolved.
	 *
	 * @return array The stamped document.
	 */
	private function withFileFacts(array $objectData, bool $needsSize, int $fileSize, int $fileId): array {
		if ($needsSize === true) {
			$objectData['fileSize'] = $fileSize;
		}

		if ($fileId > 0) {
			$objectData['fileId'] = $fileId;
		}

		return $objectData;
	}//end withFileFacts()

	/**
	 * Save a whole document back under its uuid.
	 *
	 * @param array $objectData The document.
	 * @param string $objectUuid The document uuid.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array The document, with its uuid set.
	 */
	private function saveWholeEio(array $objectData, string $objectUuid, array $mappingConfig): array {
		// The WHOLE object, never a partial: ObjectService::saveObject
		// REPLACES, and a partial write drops the four properties the
		// informatieobject schema requires (#1960).
		$objectData['uuid'] = $objectUuid;
		$this->zgwService->getObjectService()->saveObject(
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema'],
			object: $objectData
		);

		return $objectData;
	}//end saveWholeEio()

	/**
	 * The bestandsdelen of a document with a pending chunked upload, else [].
	 *
	 * @param array $objectData The saved document.
	 * @param string $uuid The document uuid.
	 * @param int $fileSize The declared bestandsomvang, used when the marker has none.
	 *
	 * @return array The bestandsdelen.
	 */
	private function pendingBestandsdelen(array $objectData, string $uuid, int $fileSize): array {
		$chunkInfo = $this->parseFileParts(objectData: $objectData);
		if ($chunkInfo === null || ($chunkInfo['pending'] ?? false) !== true) {
			return [];
		}

		return $this->buildBestandsdelenArray(
			uuid: $uuid,
			fileSize: ($chunkInfo['fileSize'] ?? $fileSize),
			totalParts: ($chunkInfo['totalParts'] ?? 1)
		);
	}//end pendingBestandsdelen()


	/**
	 * Retrieve a single resource by UUID.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_READ, period: 60)]
	public function show(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$response = $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);

		// Add bestandsdelen for EIO resources with pending chunked uploads.
		if ($resource === self::EIO_RESOURCE
			&& $response->getStatus() === Http::STATUS_OK
			&& $this->zgwService->getObjectService() !== null
		) {
			$this->enrichWithBestandsdelen(response: $response, uuid: $uuid);
		}

		return $response;
	}//end show()

	/**
	 * Full update (PUT) a resource by UUID.
	 *
	 * For EIO resources, checks document lock and handles inhoud.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function update(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate updates on documenten.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.bijwerken');
		}

		if ($resource === self::EIO_RESOURCE) {
			return $this->handleEioUpdate(run: new EioUpdateRun(resource: $resource, uuid: $uuid, partial: false));
		}

		return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, false);
	}//end update()

	/**
	 * Partial update (PATCH) a resource by UUID.
	 *
	 * For EIO resources, checks document lock and handles inhoud.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function patch(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate patches on documenten.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.bijwerken');
		}

		if ($resource === self::EIO_RESOURCE) {
			return $this->handleEioUpdate(run: new EioUpdateRun(resource: $resource, uuid: $uuid, partial: true));
		}

		return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, true);
	}//end patch()

	/**
	 * Delete a resource by UUID.
	 *
	 * For EIO resources, deletes stored files after deleting the object.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function destroy(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate destroys on documenten.verwijderen scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.verwijderen') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.verwijderen');
		}

		// Drc-006 (VNG): Gebruiksrechten delete — update indicatieGebruiksrecht on EIO.
		if ($resource === 'gebruiksrechten') {
			return $this->destroyGebruiksrecht(uuid: $uuid);
		}

		if ($resource === self::EIO_RESOURCE) {
			return $this->destroyEio(uuid: $uuid);
		}

		return $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);
	}//end destroy()

	/**
	 * Delete a gebruiksrecht, and clear its document's indicatieGebruiksrecht when it was the last.
	 *
	 * @param string $uuid The gebruiksrecht uuid.
	 *
	 * @return JSONResponse
	 */
	private function destroyGebruiksrecht(string $uuid): JSONResponse {
		$grData = $this->getGebruiksrechtData(uuid: $uuid);
		$response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, 'gebruiksrechten', $uuid);
		if ($response->getStatus() === Http::STATUS_NO_CONTENT && $grData !== null) {
			$this->checkAndClearIndicationGebruiksrecht(eioUuid: $grData['informatieobjectUuid']);
		}

		return $response;
	}//end destroyGebruiksrecht()

	/**
	 * Delete an enkelvoudiginformatieobject.
	 *
	 * Refused while ObjectInformatieObjecten point at it (drc-008a), and refused when that could
	 * not be looked up. After a successful delete its
	 * gebruiksrechten go (drc-008), and its stored files go when the document could be read
	 * before the delete.
	 *
	 * @param string $uuid The document uuid.
	 *
	 * @return JSONResponse
	 */
	private function destroyEio(string $uuid): JSONResponse {
		$hasStoredFiles = $this->isReadableEio(uuid: $uuid);

		// Drc-008a (VNG): Block EIO deletion when OIO relations exist.
		$refusal = $this->refuseRelatedEioDelete(uuid: $uuid);
		if ($refusal !== null) {
			return $refusal;
		}

		$response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, self::EIO_RESOURCE, $uuid);
		if ($response->getStatus() !== Http::STATUS_NO_CONTENT) {
			return $response;
		}

		$this->cleanUpDeletedEio(uuid: $uuid, hasStoredFiles: $hasStoredFiles);

		return $response;
	}//end destroyEio()

	/**
	 * Remove what a deleted document leaves behind: its gebruiksrechten and its stored files.
	 *
	 * @param string $uuid The document UUID.
	 * @param bool $hasStoredFiles Whether the document had stored files.
	 *
	 * @return void
	 */
	private function cleanUpDeletedEio(string $uuid, bool $hasStoredFiles): void {
		// Drc-008 (VNG): Cascade delete gebruiksrechten after EIO deletion.
		$this->cascadeDeleteGebruiksrechten(eioUuid: $uuid);

		if ($hasStoredFiles === true) {
			$this->deleteStoredFiles(uuid: $uuid);
		}
	}//end cleanUpDeletedEio()

	/**
	 * Refuse deleting a document that ObjectInformatieObjecten still point at, or whose relations could not be checked.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse|null The refusal, or null when the document may be deleted.
	 */
	private function refuseRelatedEioDelete(string $uuid): ?JSONResponse {
		try {
			$relations = $this->findOioRelationsForEio(eioUuid: $uuid);
		} catch (\Throwable $e) {
			// The search did not run, so nobody knows whether relations point here. Refuse.
			return $this->relationCheckNotCompleted(uuid: $uuid, cause: $e->getMessage());
		}

		if (empty($relations) === false) {
			return new JSONResponse(
				[
					'detail' => $this->l10n->t('The document cannot be deleted: there are related ObjectInformatieObjecten.'),
					'invalidParams' => [
						[
							'name' => 'nonFieldErrors',
							'code' => 'pending-relations',
							'reason' => $this->l10n->t('The document cannot be deleted.'),
						],
					],
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end refuseRelatedEioDelete()

	/**
	 * Delete a deleted document's stored files, logging a failure instead of raising it.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return void
	 */
	private function deleteStoredFiles(string $uuid): void {
		try {
			$this->zgwService->getDocumentService()->deleteFiles(uuid: $uuid);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'DRC file cleanup failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end deleteStoredFiles()

	/**
	 * The answer when the relation check of a document delete could not run (drc-008a).
	 *
	 * The same refusal the rules give, logged with the rule and the document. Nothing is deleted.
	 *
	 * @param string $uuid The document uuid.
	 * @param string $cause Why the check could not run.
	 *
	 * @return JSONResponse
	 */
	private function relationCheckNotCompleted(string $uuid, string $cause): JSONResponse {
		$refusal = (new ZgwRuleResults(logger: $this->zgwService->getLogger()))
			->notVerified(rule: 'drc-008a', object: 'document ' . $uuid, cause: $cause);

		return new JSONResponse(
			data: $this->zgwService->buildValidationError(ruleResult: $refusal),
			statusCode: $refusal['status']
		);
	}//end relationCheckNotCompleted()

	/**
	 * Whether the document can be read, which is what decides whether its files are cleaned up.
	 *
	 * @param string $uuid The document uuid.
	 *
	 * @return bool True when the document was found.
	 */
	private function isReadableEio(string $uuid): bool {
		if ($this->zgwService->getObjectService() === null) {
			return false;
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return false;
		}

		try {
			$this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}//end isReadableEio()

	/**
	 * Download the binary file content for an EIO document.
	 *
	 * Rate-limit rationale: lower than the sibling reads — a download moves
	 * file bytes.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function download(string $uuid): DataDownloadResponse|JSONResponse {
		$objectService = $this->authorisedObjectService();
		if ($objectService instanceof JSONResponse) {
			return $objectService;
		}

		$mappingConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
		if ($mappingConfig === null) {
			return new JSONResponse(data: ['detail' => 'Document mapping not configured'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			return $this->downloadStoredFile(uuid: $uuid, mappingConfig: $mappingConfig);
		} catch (\Throwable $e) {
			return $this->downloadFailure(e: $e);
		}//end try
	}//end download()

	/**
	 * Log a failed download and answer it as not found.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function downloadFailure(\Throwable $e): JSONResponse {
		$this->zgwService->getLogger()->error(
			'DRC download error: ' . $e->getMessage(),
			['exception' => $e]
		);

		return new JSONResponse(
			data: ['detail' => 'Not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end downloadFailure()

	/**
	 * Answer a document's stored file, or 404 when it has none.
	 *
	 * @param string $uuid The document UUID.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @throws \Throwable When the document or its file cannot be read.
	 */
	private function downloadStoredFile(string $uuid, array $mappingConfig): DataDownloadResponse|JSONResponse {
		$object = $this->zgwService->getObjectService()->find(
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema'],
			id: $uuid
		);
		$objectData = $this->objectToArray(row: $object);

		$fileName = $this->documentFileName(objectData: $objectData);

		$format = $objectData['format'] ?? 'application/octet-stream';

		if ($this->zgwService->getDocumentService()->fileExists(uuid: $uuid, fileName: $fileName) === false) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('File not found.')],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$content = $this->zgwService->getDocumentService()->getContent(uuid: $uuid, fileName: $fileName);

		return new DataDownloadResponse(data: $content, filename: $fileName, contentType: $format);
	}//end downloadStoredFile()

	/**
	 * Lock an EIO document.
	 *
	 * Sets the document as locked and generates a lock identifier.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function lock(string $uuid): JSONResponse {
		$objectService = $this->authorisedObjectService();
		if ($objectService instanceof JSONResponse) {
			return $objectService;
		}

		// Check if already locked (entity lock or data blob fallback).
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($this->storedLockIdFor(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid) !== null) {
			return $this->alreadyLockedResponse();
		}

		try {
			return $this->lockWithNewLockId(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);
		} catch (\OCA\OpenRegister\Exception\LockedException $e) {
			return $this->alreadyLockedResponse();
		} catch (\Throwable $e) {
			// Fallback: OpenRegister lock may fail without a Nextcloud user
			// session (JWT-only context). Use manual lock via saveObject.
			return $this->lockFallback(objectService: $objectService, uuid: $uuid, original: $e);
		}//end try
	}//end lock()

	/**
	 * The lockId stored for a document (entity lock or data blob), null when unlocked or unmapped.
	 *
	 * @param object $objectService The object service.
	 * @param array|null $mappingConfig The EIO mapping, null when not configured.
	 * @param string $uuid The document UUID.
	 *
	 * @return string|null The stored lockId.
	 */
	private function storedLockIdFor(object $objectService, ?array $mappingConfig, string $uuid): ?string {
		if ($mappingConfig === null) {
			return null;
		}

		return $this->resolveStoredLockId(document: new EioDocument(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid));
	}//end storedLockIdFor()

	/**
	 * The refusal for locking a document that is already locked.
	 *
	 * @return JSONResponse
	 */
	private function alreadyLockedResponse(): JSONResponse {
		return new JSONResponse(
			data: ['detail' => $this->l10n->t('Document is already locked.')],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end alreadyLockedResponse()

	/**
	 * Lock a document through OpenRegister and store a generated ZGW lockId on it.
	 *
	 * @param object $objectService The object service.
	 * @param array|null $mappingConfig The EIO mapping, null when not configured.
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse The new lock.
	 *
	 * @throws \Throwable When OpenRegister refuses or fails the lock.
	 */
	private function lockWithNewLockId(object $objectService, ?array $mappingConfig, string $uuid): JSONResponse {
		$objectService->lockObject(identifier: $uuid);

		// OpenRegister's lock system doesn't produce a ZGW lockId.
		// Generate one and store it in the data blob for verification.
		$lockId = bin2hex(random_bytes(16));
		if ($mappingConfig !== null) {
			$this->storeLockIdInData(document: new EioDocument(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid), lockId: $lockId);
		}

		return new JSONResponse(
			data: ['lock' => $lockId],
			statusCode: Http::STATUS_OK
		);
	}//end lockWithNewLockId()

	/**
	 * Fallback lock implementation for when OpenRegister's LockHandler
	 * fails due to missing Nextcloud user session (JWT-only context).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The document UUID
	 * @param \Throwable $original The original exception
	 *
	 * @return JSONResponse
	 */
	private function lockFallback(object $objectService, string $uuid, \Throwable $original): JSONResponse {
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return $this->fallbackBadRequest(detail: $original->getMessage());
		}

		try {
			return $this->lockViaSave(document: new EioDocument(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid));
		} catch (\Throwable $e) {
			return $this->fallbackFailure(logPrefix: 'DRC lock fallback error: ', e: $e);
		}//end try
	}//end lockFallback()

	/**
	 * Lock a document by writing a fresh lockId into its stored data.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Throwable When the object cannot be read or saved.
	 */
	private function lockViaSave(EioDocument $document): JSONResponse {
		$existingData = $this->fallbackObjectData(document: $document);

		$lockId = bin2hex(random_bytes(16));

		unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
		$existingData['locked'] = true;
		$existingData['lockId'] = $lockId;

		$this->saveFallbackObject(document: $document, data: $existingData);

		return new JSONResponse(data: ['lock' => $lockId], statusCode: Http::STATUS_OK);
	}//end lockViaSave()

	/**
	 * Read a document's stored data for the lock fallbacks.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return array The object data
	 *
	 * @throws \Throwable When the object cannot be found.
	 */
	private function fallbackObjectData(EioDocument $document): array {
		$existing = $document->objectService->find(
			$document->uuid,
			register: $document->mappingConfig['sourceRegister'],
			schema: $document->mappingConfig['sourceSchema']
		);
		return $this->objectToArray(row: $existing);
	}//end fallbackObjectData()

	/**
	 * Save a document's data for the lock fallbacks.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 * @param array       $data     The object data to save
	 *
	 * @return void
	 *
	 * @throws \Throwable When the object cannot be saved.
	 */
	private function saveFallbackObject(EioDocument $document, array $data): void {
		$document->objectService->saveObject(
			register: $document->mappingConfig['sourceRegister'],
			schema: $document->mappingConfig['sourceSchema'],
			object: $data,
			uuid: $document->uuid
		);
	}//end saveFallbackObject()

	/**
	 * Log a lock fallback failure and build the 400 response.
	 *
	 * @param string     $logPrefix The log message prefix
	 * @param \Throwable $e         The caught failure
	 *
	 * @return JSONResponse
	 */
	private function fallbackFailure(string $logPrefix, \Throwable $e): JSONResponse {
		$this->zgwService->getLogger()->error(
			$logPrefix . $e->getMessage(),
			['exception' => $e]
		);

		return $this->fallbackBadRequest(detail: $e->getMessage());
	}//end fallbackFailure()

	/**
	 * Build a 400 response carrying a detail message.
	 *
	 * @param string $detail The detail message
	 *
	 * @return JSONResponse
	 */
	private function fallbackBadRequest(string $detail): JSONResponse {
		return new JSONResponse(
			data: ['detail' => $detail],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end fallbackBadRequest()

	/**
	 * Unlock an EIO document.
	 *
	 * Verifies the lock identifier and sets the document as unlocked.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function unlock(string $uuid): JSONResponse {
		$objectService = $this->authorisedObjectService();
		if ($objectService instanceof JSONResponse) {
			return $objectService;
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);

		$refusal = $this->unlockRefusal(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);
		if ($refusal !== null) {
			return $refusal;
		}

		// Try OpenRegister's LockHandler, fall back to clearing data blob.
		try {
			return $this->unlockAndClearLockId(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);
		} catch (\Throwable $e) {
			// Fallback: unlock via saveObject when LockHandler fails
			// (e.g., no Nextcloud user session in JWT-only context).
			return $this->unlockFallback(objectService: $objectService, uuid: $uuid, original: $e);
		}
	}//end unlock()

	/**
	 * Refuse unlocking a document that is not locked, or an unforced unlock with the wrong lockId.
	 *
	 * @param object $objectService The object service.
	 * @param array|null $mappingConfig The EIO mapping, null when not configured.
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse|null The refusal, or null when the unlock may go ahead.
	 */
	private function unlockRefusal(object $objectService, ?array $mappingConfig, string $uuid): ?JSONResponse {
		// Check if the document is actually locked (entity or data blob).
		$storedLockId = $this->storedLockIdFor(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);

		if ($storedLockId === null) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('Document is not locked.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$body = $this->zgwService->getRequestBody($this->request);
		$lockId = $body['lock'] ?? '';

		// Determine if this is a forced unlock (wrong/empty lockId + scope).
		return $this->refuseUnforcedUnlock(lockId: $lockId, storedLockId: $storedLockId);
	}//end unlockRefusal()

	/**
	 * Unlock a document through OpenRegister and clear its stored lockId.
	 *
	 * @param object $objectService The object service.
	 * @param array|null $mappingConfig The EIO mapping, null when not configured.
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Throwable When OpenRegister fails the unlock.
	 */
	private function unlockAndClearLockId(object $objectService, ?array $mappingConfig, string $uuid): JSONResponse {
		$objectService->unlockObject(identifier: $uuid);

		// Clear lockId from the data blob.
		if ($mappingConfig !== null) {
			$this->clearLockIdInData(document: new EioDocument(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid));
		}

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end unlockAndClearLockId()

	/**
	 * Refuse an unlock with a wrong or empty lock id from a consumer without the
	 * geforceerd-bijwerken scope.
	 *
	 * @param mixed $lockId The lock id the caller sent.
	 * @param string $storedLockId The lock id the document holds.
	 *
	 * @return JSONResponse|null The refusal, or null when the unlock may proceed.
	 */
	private function refuseUnforcedUnlock(mixed $lockId, string $storedLockId): ?JSONResponse {
		if ($lockId === $storedLockId
			|| $this->zgwService->consumerHasScope($this->request, 'documenten', 'geforceerd-bijwerken') === true
		) {
			return null;
		}

		$detail = $this->l10n->t('Lock ID does not match and forced unlocking is not allowed.');
		if ($lockId === '') {
			$detail = $this->l10n->t('Forced unlocking is not allowed without the correct scope.');
		}

		return new JSONResponse(
			data: [
				'detail' => $detail,
				'invalidParams' => [
					[
						'name' => 'nonFieldErrors',
						'code' => 'incorrect-lock-id',
						'reason' => $detail,
					],
				],
			],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end refuseUnforcedUnlock()

	/**
	 * Fallback unlock for when OpenRegister's LockHandler fails (no NC session).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The document UUID
	 * @param \Throwable $original The original exception
	 *
	 * @return JSONResponse
	 */
	private function unlockFallback(object $objectService, string $uuid, \Throwable $original): JSONResponse {
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return $this->fallbackBadRequest(detail: $original->getMessage());
		}

		try {
			return $this->unlockViaSave(document: new EioDocument(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid));
		} catch (\Throwable $e) {
			return $this->fallbackFailure(logPrefix: 'DRC unlock fallback error: ', e: $e);
		}//end try
	}//end unlockFallback()

	/**
	 * Unlock a document by clearing the lock fields in its stored data.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Throwable When the object cannot be read or saved.
	 */
	private function unlockViaSave(EioDocument $document): JSONResponse {
		$existingData = $this->fallbackObjectData(document: $document);

		unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
		$existingData['locked'] = false;
		$existingData['lockId'] = '';

		foreach ($existingData as $key => $value) {
			if ($value === null) {
				unset($existingData[$key]);
			}
		}

		$this->saveFallbackObject(document: $document, data: $existingData);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end unlockViaSave()

	/**
	 * List audit trail entries for a resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_READ, period: 60)]
	public function audittrailIndex(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// Drc-008c (VNG): Return 404 if the parent resource no longer exists.
		if ($this->zgwService->getObjectService() !== null) {
			$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
			if ($mappingConfig !== null) {
				try {
					$this->zgwService->getObjectService()->find(
						$uuid,
						register: $mappingConfig['sourceRegister'],
						schema: $mappingConfig['sourceSchema']
					);
				} catch (\Throwable $e) {
					return new JSONResponse(
						data: ['detail' => $this->l10n->t('Not found.')],
						statusCode: Http::STATUS_NOT_FOUND
					);
				}
			}
		}

		return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
	}//end audittrailIndex()

	/**
	 * Retrieve a single audit trail entry for a resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 * @param string $auditUuid The audit trail entry UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_READ, period: 60)]
	public function audittrailShow(string $resource, string $uuid, string $auditUuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		return $this->zgwService->handleAudittrailShow(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			$auditUuid
		);
	}//end audittrailShow()

	/**
	 * Find relations for an EIO by UUID (drc-008a VNG).
	 *
	 * Checks OIO, ZIO, and BIO schemas for references to the given document.
	 *
	 * @param string $eioUuid The EIO UUID
	 *
	 * @return array List of related object IDs linked to this EIO
	 *
	 * @throws \Throwable When a schema cannot be searched.
	 */
	private function findOioRelationsForEio(string $eioUuid): array {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$lookup = new EioRelationLookup(objectService: $objectService, eioUuid: $eioUuid);
		foreach ($this->relationSchemas() as $schemaInfo) {
			$ids = $this->searchRelationsInSchema(lookup: $lookup, schemaInfo: $schemaInfo);
			if (empty($ids) === false) {
				return $ids;
			}
		}

		return [];
	}//end findOioRelationsForEio()

	/**
	 * The schemas that hold relations to a document: OIO, ZIO and BIO, as far as they are mapped.
	 *
	 * Every mapping is read before any of them is searched.
	 *
	 * @return array<int, array{register: mixed, schema: mixed}> The register and schema of each.
	 */
	private function relationSchemas(): array {
		$schemasToCheck = [];
		foreach (self::DOCUMENT_RELATIONS as [$zgwApi, $resource]) {
			$config = $this->zgwService->loadMappingConfig($zgwApi, $resource);
			if ($config !== null) {
				$schemasToCheck[] = [
					'register' => $config['sourceRegister'],
					'schema' => $config['sourceSchema'],
				];
			}
		}

		return $schemasToCheck;
	}//end relationSchemas()

	/**
	 * Search for document relations in a specific schema.
	 *
	 * @param EioRelationLookup $lookup     The lookup: the object service and the document UUID.
	 * @param array             $schemaInfo The register and schema to search.
	 *
	 * @return array List of related object IDs. Empty means the search ran and found none.
	 *
	 * @throws \Throwable When the schema cannot be searched.
	 */
	private function searchRelationsInSchema(EioRelationLookup $lookup, array $schemaInfo): array {
		// Try exact UUID match (OIO may store just the UUID).
		$exact = $this->relationSearch(schemaInfo: $schemaInfo, requestParams: ['document' => $lookup->eioUuid, '_limit' => 1]);
		$ids = $this->relationIdsMatching(lookup: $lookup, search: $exact);
		if (empty($ids) === false) {
			return $ids;
		}

		// Fallback: full-text search by UUID (document field stores
		// the full URL, and field-specific LIKE is not supported).
		$fullText = $this->relationSearch(schemaInfo: $schemaInfo, requestParams: ['_search' => $lookup->eioUuid, '_limit' => 1]);

		return $this->relationIdsMatching(lookup: $lookup, search: $fullText);
	}//end searchRelationsInSchema()

	/**
	 * One relation search in a schema.
	 *
	 * @param array $schemaInfo    The register and schema to search.
	 * @param array $requestParams The search parameters.
	 *
	 * @return RelationSearch
	 */
	private function relationSearch(array $schemaInfo, array $requestParams): RelationSearch {
		return new RelationSearch(register: $schemaInfo['register'], schema: $schemaInfo['schema'], requestParams: $requestParams);
	}//end relationSearch()

	/**
	 * The ids of the relations one search finds.
	 *
	 * @param EioRelationLookup $lookup The lookup: the object service.
	 * @param RelationSearch    $search The register, schema and parameters to search with.
	 *
	 * @return array The ids found, [] when none.
	 */
	private function relationIdsMatching(EioRelationLookup $lookup, RelationSearch $search): array {
		$query = $lookup->objectService->buildSearchQuery(
			requestParams: $search->requestParams,
			register: $search->register,
			schema: $search->schema
		);

		return $this->extractIdsFromResults(result: $lookup->objectService->searchObjectsPaginated(query: $query));
	}//end relationIdsMatching()

	/**
	 * Extract IDs from a search result set.
	 *
	 * @param array $result The search result from searchObjectsPaginated
	 *
	 * @return array<string> Array of object IDs
	 */
	private function extractIdsFromResults(array $result): array {
		$ids = [];
		foreach (($result['results'] ?? []) as $obj) {
			$data = $this->objectToArray(row: $obj);

			$id = $data['id'] ?? ($data['@self']['id'] ?? null);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end extractIdsFromResults()

	/**
	 * Cascade delete all gebruiksrechten for an EIO (drc-008 VNG).
	 *
	 * @param string $eioUuid The EIO UUID
	 *
	 * @return void
	 */
	private function cascadeDeleteGebruiksrechten(string $eioUuid): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$grScope = $this->cascadeGebruiksrechtenScope(eioUuid: $eioUuid);
		if ($grScope === null) {
			return;
		}

		$this->deleteGebruiksrechtenOrWarn(objectService: $objectService, grScope: $grScope, eioUuid: $eioUuid);
	}//end cascadeDeleteGebruiksrechten()

	/**
	 * Delete the gebruiksrechten that point at a document, logging a failure instead of raising it.
	 *
	 * @param object $objectService The object service.
	 * @param ZgwSearchScope $grScope The gebruiksrechten scope.
	 * @param string $eioUuid The EIO UUID.
	 *
	 * @return void
	 */
	private function deleteGebruiksrechtenOrWarn(object $objectService, ZgwSearchScope $grScope, string $eioUuid): void {
		try {
			$this->deleteGebruiksrechtenOf(objectService: $objectService, grScope: $grScope, eioUuid: $eioUuid);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-008: Failed to cascade delete gebruiksrechten for EIO ' . $eioUuid . ': ' . $e->getMessage()
			);
		}//end try
	}//end deleteGebruiksrechtenOrWarn()

	/**
	 * The searchable gebruiksrechten scope for a delete cascade, null (and logged when unsearchable) without one.
	 *
	 * @param string $eioUuid The EIO UUID being deleted.
	 *
	 * @return ZgwSearchScope|null
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 */
	private function cascadeGebruiksrechtenScope(string $eioUuid): ?ZgwSearchScope {
		$grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($grConfig === null) {
			return null;
		}

		// An unsearchable scope answers this cascade with an empty page and no
		// error ({@see ZgwSearchScope}), so the EIO goes and its gebruiksrechten
		// stay behind pointing at a document that no longer exists. Name it.
		$grScope = ZgwSearchScope::fromMapping(mappingConfig: $grConfig);
		if ($grScope === null) {
			$this->zgwService->getLogger()->error(
				'drc-008: gebruiksrechten mapping has no searchable register/schema, so the '
				. 'gebruiksrechten of ' . $eioUuid . ' are being left behind as orphans'
			);
			return null;
		}

		return $grScope;
	}//end cascadeGebruiksrechtenScope()

	/**
	 * Delete the gebruiksrechten that point at a document.
	 *
	 * @param object $objectService The object service.
	 * @param ZgwSearchScope $grScope The gebruiksrechten scope.
	 * @param string $eioUuid The EIO UUID.
	 *
	 * @return void
	 *
	 * @throws \Throwable When searching or deleting fails.
	 */
	private function deleteGebruiksrechtenOf(object $objectService, ZgwSearchScope $grScope, string $eioUuid): void {
		$query = $objectService->buildSearchQuery(
			requestParams: ['document' => '%' . $eioUuid . '%', '_limit' => 100],
			register: $grScope->register,
			schema: $grScope->schema
		);
		$result = $objectService->searchObjectsPaginated(query: $query);

		foreach (($result['results'] ?? []) as $gr) {
			$grData = $this->objectToArray(row: $gr);

			$grUuid = $grData['id'] ?? ($grData['@self']['id'] ?? '');
			if ($grUuid !== '') {
				$objectService->deleteObject(uuid: $grUuid);
			}
		}
	}//end deleteGebruiksrechtenOf()

	/**
	 * Update indicatieGebruiksrecht on an EIO after creating a gebruiksrecht (drc-006 VNG).
	 *
	 * Sets indicatieGebruiksrecht to true on the related informatieobject.
	 *
	 * @param JSONResponse $response The create response containing the gebruiksrecht data
	 *
	 * @return void
	 */
	private function updateIndicationGebruiksrecht(JSONResponse $response): void {
		$data = $response->getData();
		if (is_array($data) === false) {
			return;
		}

		$ioUrl = $data['informatieobject'] ?? '';
		if ($ioUrl === '') {
			return;
		}

		$this->setIndicationGebruiksrecht(ioUrl: $ioUrl, value: true);
	}//end updateIndicatieGebruiksrecht()

	/**
	 * Get gebruiksrecht data before deletion (drc-006 VNG).
	 *
	 * @param string $uuid The gebruiksrecht UUID
	 *
	 * @return array|null Array with informatieobjectUuid, or null
	 */
	private function getGebruiksrechtData(string $uuid): ?array {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($mappingConfig === null) {
			return null;
		}

		try {
			$obj = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$data = $this->objectToArray(row: $obj);

			$ioRef = $data['document'] ?? ($data['informatieobject'] ?? '');
			$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
			if (preg_match($uuidPattern, (string)$ioRef, $grMatches) === 1) {
				return ['informatieobjectUuid' => $grMatches[1]];
			}
		} catch (\Throwable $e) {
			// Not found.
		}

		return null;
	}//end getGebruiksrechtData()

	/**
	 * Check if EIO still has gebruiksrechten after deletion (drc-006 VNG).
	 *
	 * If no gebruiksrechten remain, sets indicatieGebruiksrecht to null.
	 *
	 * @param string $eioUuid The EIO UUID
	 *
	 * @return void
	 */
	private function checkAndClearIndicationGebruiksrecht(string $eioUuid): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$grScope = $this->indicationGebruiksrechtenScope(eioUuid: $eioUuid);
		if ($grScope === null) {
			return;
		}

		$this->clearIndicationWhenNoneRemain(objectService: $objectService, grScope: $grScope, eioUuid: $eioUuid);
	}//end checkAndClearIndicatieGebruiksrecht()

	/**
	 * Clear indicatieGebruiksrecht when no gebruiksrechten remain, logging a failed count instead of raising it.
	 *
	 * @param object $objectService The object service.
	 * @param ZgwSearchScope $grScope The gebruiksrechten scope.
	 * @param string $eioUuid The EIO UUID.
	 *
	 * @return void
	 */
	private function clearIndicationWhenNoneRemain(object $objectService, ZgwSearchScope $grScope, string $eioUuid): void {
		try {
			$total = $this->countGebruiksrechtenOf(objectService: $objectService, grScope: $grScope, eioUuid: $eioUuid);

			if ($total === 0) {
				// No more gebruiksrechten — clear indicatieGebruiksrecht.
				$this->clearIndicationGebruiksrecht(objectService: $objectService, eioUuid: $eioUuid);
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-006: Failed to check remaining gebruiksrechten: ' . $e->getMessage()
			);
		}//end try
	}//end clearIndicationWhenNoneRemain()

	/**
	 * The searchable gebruiksrechten scope for the indicatieGebruiksrecht check, null (and logged when unsearchable) without one.
	 *
	 * @param string $eioUuid The EIO UUID.
	 *
	 * @return ZgwSearchScope|null
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 */
	private function indicationGebruiksrechtenScope(string $eioUuid): ?ZgwSearchScope {
		$grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($grConfig === null) {
			return null;
		}

		// 🔴 A ZERO THIS CODE CANNOT TRUST MUST NOT CLEAR A USAGE RIGHT.
		// `total: 0` is also what a gebruiksrechten mapping whose register or
		// schema OpenRegister cannot resolve answers, with no error at all
		// ({@see ZgwSearchScope}). Clearing indicatieGebruiksrecht on that
		// zero states "this document carries no usage restrictions" about a
		// document whose gebruiksrechten were never counted.
		$grScope = ZgwSearchScope::fromMapping(mappingConfig: $grConfig);
		if ($grScope === null) {
			$this->zgwService->getLogger()->warning(
				'drc-006: gebruiksrechten mapping has no searchable register/schema, '
				. 'leaving indicatieGebruiksrecht as it is for ' . $eioUuid
			);
			return null;
		}

		return $grScope;
	}//end indicationGebruiksrechtenScope()

	/**
	 * Count the gebruiksrechten that still point at a document.
	 *
	 * @param object $objectService The object service.
	 * @param ZgwSearchScope $grScope The gebruiksrechten scope.
	 * @param string $eioUuid The EIO UUID.
	 *
	 * @return mixed The total the search reports.
	 *
	 * @throws \Throwable When the search fails.
	 */
	private function countGebruiksrechtenOf(object $objectService, ZgwSearchScope $grScope, string $eioUuid): mixed {
		$query = $objectService->buildSearchQuery(
			requestParams: ['document' => $eioUuid, '_limit' => 1],
			register: $grScope->register,
			schema: $grScope->schema
		);
		$result = $objectService->searchObjectsPaginated(query: $query);

		return $result['total'] ?? count($result['results'] ?? []);
	}//end countGebruiksrechtenOf()

	/**
	 * Clear indicatieGebruiksrecht on a document, logging a failure instead of raising it.
	 *
	 * @param object $objectService The object service.
	 * @param string $eioUuid The EIO UUID.
	 *
	 * @return void
	 */
	private function clearIndicationGebruiksrecht(object $objectService, string $eioUuid): void {
		$eioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($eioConfig !== null) {
			try {
				$this->writeUsageRightsIndication(objectService: $objectService, eioConfig: $eioConfig, eioUuid: $eioUuid, value: null);
			} catch (\Throwable $e) {
				$this->zgwService->getLogger()->warning(
					'drc-006: Failed to clear indicatieGebruiksrecht: ' . $e->getMessage()
				);
			}//end try
		}//end if
	}//end clearIndicationGebruiksrecht()

	/**
	 * Write usageRightsIndication on a document and save it.
	 *
	 * @param object $objectService The object service.
	 * @param array $eioConfig The EIO mapping.
	 * @param string $eioUuid The EIO UUID.
	 * @param bool|null $value The value to set (true or null).
	 *
	 * @return void
	 *
	 * @throws \Throwable When finding or saving the document fails.
	 */
	private function writeUsageRightsIndication(object $objectService, array $eioConfig, string $eioUuid, ?bool $value): void {
		$eioObj = $objectService->find(
			$eioUuid,
			register: $eioConfig['sourceRegister'],
			schema: $eioConfig['sourceSchema']
		);
		$eioData = $this->objectToArray(row: $eioObj);

		$eioData['usageRightsIndication'] = $value;

		unset($eioData['@self'], $eioData['id'], $eioData['organisation']);
		$objectService->saveObject(
			register: $eioConfig['sourceRegister'],
			schema: $eioConfig['sourceSchema'],
			object: $eioData,
			uuid: $eioUuid
		);
	}//end writeUsageRightsIndication()

	/**
	 * Set indicatieGebruiksrecht on an EIO (drc-006 VNG).
	 *
	 * @param string $ioUrl The informatieobject URL
	 * @param bool|null $value The value to set (true or null)
	 *
	 * @return void
	 */
	private function setIndicationGebruiksrecht(string $ioUrl, ?bool $value): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		if (preg_match($uuidPattern, $ioUrl, $ioMatches) !== 1) {
			return;
		}

		$eioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($eioConfig === null) {
			return;
		}

		$this->setUsageRightsIndicationOrWarn(objectService: $objectService, eioConfig: $eioConfig, eioUuid: $ioMatches[1], value: $value);
	}//end setIndicatieGebruiksrecht()

	/**
	 * Write usageRightsIndication on a document, logging a failure instead of raising it.
	 *
	 * @param object $objectService The object service.
	 * @param array $eioConfig The EIO mapping.
	 * @param string $eioUuid The EIO UUID.
	 * @param bool|null $value The value to set (true or null).
	 *
	 * @return void
	 */
	private function setUsageRightsIndicationOrWarn(object $objectService, array $eioConfig, string $eioUuid, ?bool $value): void {
		try {
			$this->writeUsageRightsIndication(objectService: $objectService, eioConfig: $eioConfig, eioUuid: $eioUuid, value: $value);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-006: Failed to set indicatieGebruiksrecht: ' . $e->getMessage()
			);
		}//end try
	}//end setUsageRightsIndicationOrWarn()

	/**
	 * Upload a chunk (bestandsdeel) for a document.
	 *
	 * Receives raw binary data for a single chunk and stores it.
	 * When all chunks have been uploaded, merges them into the final file.
	 *
	 * Rate-limit rationale: tight — each call accepts a chunk of file bytes,
	 * so this is the cheapest way for an anonymous caller to consume storage.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwServiceInterface::RATE_LIMIT_WRITE, period: 60)]
	public function uploadChunk(string $uuid): JSONResponse {
		$objectService = $this->authorisedObjectService();
		if ($objectService instanceof JSONResponse) {
			return $objectService;
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, self::EIO_RESOURCE);
		}

		try {
			return $this->receiveChunk(uuid: $uuid, objectService: $objectService, mappingConfig: $mappingConfig);
		} catch (\Throwable $e) {
			return $this->loggedBadRequest(e: $e, logPrefix: 'DRC chunk upload error: ');
		}//end try
	}//end uploadChunk()

	/**
	 * Pass the JWT check and answer the object service, or the auth error or 503 that stops the request.
	 *
	 * @return object The object service, or the JSONResponse that refuses the request.
	 */
	private function authorisedObjectService(): object {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		return $objectService;
	}//end authorisedObjectService()

	/**
	 * Log a failed write and answer it as 400 with the failure's message.
	 *
	 * @param \Throwable $e The failure.
	 * @param string $logPrefix What the log line starts with.
	 *
	 * @return JSONResponse
	 */
	private function loggedBadRequest(\Throwable $e, string $logPrefix): JSONResponse {
		$this->zgwService->getLogger()->error(
			$logPrefix . $e->getMessage(),
			['exception' => $e]
		);

		return new JSONResponse(
			data: ['detail' => $e->getMessage()],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end loggedBadRequest()

	/**
	 * Check a chunk against the document's pending upload, read it from the body and store it.
	 *
	 * @param string $uuid The document UUID.
	 * @param object $objectService The object service.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Throwable When finding, storing or merging fails.
	 */
	private function receiveChunk(string $uuid, object $objectService, array $mappingConfig): JSONResponse {
		// Find the EIO object.
		$existing = $objectService->find(
			$uuid,
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema']
		);
		$objectData = $this->objectToArray(row: $existing);

		// Verify this document has a pending chunked upload, and the volgnummer (query
		// parameter or request body) falls inside it.
		$chunkInfo = $this->parseFileParts(objectData: $objectData);
		$totalParts = (int)($chunkInfo['totalParts'] ?? 0);
		$sequenceNumber = (int)($this->request->getParam('sequenceNumber') ?? 0);
		$content = $this->chunkContentOrRefusal(chunkInfo: $chunkInfo, totalParts: $totalParts, sequenceNumber: $sequenceNumber);
		if ($content instanceof JSONResponse) {
			return $content;
		}

		[$chunkSize, $uploadedParts] = $this->storeChunkAndCount(uuid: $uuid, sequenceNumber: $sequenceNumber, content: $content, totalParts: $totalParts);

		if ($uploadedParts === $totalParts) {
			// All chunks present — merge into final file.
			$mergedSize = $this->mergeUploadedChunks(objectData: $objectData, uuid: $uuid, totalParts: $totalParts, mappingConfig: $mappingConfig);

			return $this->chunkProgress(
				progress: ['sequenceNumber' => $sequenceNumber, 'size' => $chunkSize, 'uploadComplete' => true, 'bestandsomvang' => $mergedSize],
				uploadedParts: $uploadedParts,
				totalParts: $totalParts
			);
		}//end if

		return $this->chunkProgress(
			progress: ['sequenceNumber' => $sequenceNumber, 'size' => $chunkSize, 'uploadComplete' => false],
			uploadedParts: $uploadedParts,
			totalParts: $totalParts
		);
	}//end receiveChunk()

	/**
	 * Store one chunk, then count how many of the document's chunks are in.
	 *
	 * @param string $uuid The document UUID.
	 * @param int $sequenceNumber The volgnummer of the chunk.
	 * @param string $content The raw chunk content.
	 * @param int $totalParts The number of parts expected.
	 *
	 * @return array{0: int, 1: int} The stored chunk's size and the number of uploaded parts.
	 */
	private function storeChunkAndCount(string $uuid, int $sequenceNumber, string $content, int $totalParts): array {
		// Store the chunk.
		$docService = $this->zgwService->getDocumentService();
		$chunkSize = $docService->storeChunk(
			uuid: $uuid,
			sequenceNumber: $sequenceNumber,
			content: $content
		);

		// Check if all chunks have been uploaded.
		$uploaded = $docService->getUploadedChunks(uuid: $uuid, totalParts: $totalParts);

		return [$chunkSize, count($uploaded)];
	}//end storeChunkAndCount()

	/**
	 * Refuse a chunk that does not fit the pending upload or arrived without content; otherwise read the content.
	 *
	 * @param array|null $chunkInfo The document's fileParts marker.
	 * @param int $totalParts The number of parts it expects.
	 * @param int $sequenceNumber The volgnummer sent.
	 *
	 * @return string|JSONResponse The raw chunk content, or the refusal.
	 */
	private function chunkContentOrRefusal(?array $chunkInfo, int $totalParts, int $sequenceNumber): string|JSONResponse {
		$refusal = $this->refuseChunk(chunkInfo: $chunkInfo, totalParts: $totalParts, sequenceNumber: $sequenceNumber);
		if ($refusal !== null) {
			return $refusal;
		}

		// Read raw body content.
		$content = file_get_contents('php://input');
		if ($content === false || $content === '') {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('No file content received.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $content;
	}//end chunkContentOrRefusal()

	/**
	 * Merge a document's uploaded chunks into its file, and close the chunked upload on the object.
	 *
	 * @param array $objectData The stored document.
	 * @param string $uuid The document uuid.
	 * @param int $totalParts The number of chunks.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return int The merged file size.
	 */
	private function mergeUploadedChunks(array $objectData, string $uuid, int $totalParts, array $mappingConfig): int {
		$fileName = $this->documentFileName(objectData: $objectData);

		$mergedSize = $this->zgwService->getDocumentService()->mergeChunks(
			uuid: $uuid,
			fileName: $fileName,
			totalParts: $totalParts
		);

		// Update the object: clear chunk metadata, set file size.
		unset(
			$objectData['@self'],
			$objectData['id'],
			$objectData['organisation']
		);
		$objectData['fileParts'] = '';
		$objectData['fileSize'] = $mergedSize;

		// Same stamp as the single-shot path: the merged file is the first moment a chunked
		// upload HAS a node to point at.
		$mergedFileId = $this->resolveStoredFileId(uuid: $uuid, fileName: $fileName);
		if ($mergedFileId > 0) {
			$objectData['fileId'] = $mergedFileId;
		}

		$this->zgwService->getObjectService()->saveObject(
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema'],
			object: $objectData,
			uuid: $uuid
		);

		return $mergedSize;
	}//end mergeUploadedChunks()

	/**
	 * Refuse a chunk for a document without a pending upload, with a broken chunk
	 * configuration, or with a volgnummer outside 1..totalParts.
	 *
	 * @param array|null $chunkInfo The document's fileParts marker.
	 * @param int $totalParts The number of parts it expects.
	 * @param int $sequenceNumber The volgnummer sent.
	 *
	 * @return JSONResponse|null The refusal, or null when the chunk may be stored.
	 */
	private function refuseChunk(?array $chunkInfo, int $totalParts, int $sequenceNumber): ?JSONResponse {
		$detail = null;
		if ($chunkInfo === null || ($chunkInfo['pending'] ?? false) !== true) {
			$detail = $this->l10n->t('This document has no pending chunked upload.');
		} elseif ($totalParts <= 0) {
			$detail = $this->l10n->t('Invalid chunk configuration.');
		} elseif ($sequenceNumber <= 0 || $sequenceNumber > $totalParts) {
			$detail = $this->l10n->t('Invalid sequence number. Expected 1-%s.', [$totalParts]);
		}

		if ($detail === null) {
			return null;
		}

		return new JSONResponse(data: ['detail' => $detail], statusCode: Http::STATUS_BAD_REQUEST);
	}//end refuseChunk()

	/**
	 * The answer to a stored chunk: the chunk's facts, then how many parts are in.
	 *
	 * @param array $progress The chunk's sequenceNumber, size, uploadComplete (and bestandsomvang when complete).
	 * @param int $uploadedParts The number of parts uploaded so far.
	 * @param int $totalParts The number of parts expected.
	 *
	 * @return JSONResponse
	 */
	private function chunkProgress(array $progress, int $uploadedParts, int $totalParts): JSONResponse {
		return new JSONResponse(
			data: array_merge($progress, ['uploadedParts' => $uploadedParts, 'totalParts' => $totalParts]),
			statusCode: Http::STATUS_OK
		);
	}//end chunkProgress()

	/**
	 * Enrich a show response with bestandsdelen if a chunked upload is pending.
	 *
	 * @param JSONResponse $response The show response
	 * @param string $uuid The document UUID
	 *
	 * @return void
	 */
	private function enrichWithBestandsdelen(JSONResponse $response, string $uuid): void {
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return;
		}

		try {
			$objectData = $this->storedEioData(uuid: $uuid, mappingConfig: $mappingConfig);

			$data = $response->getData();
			if (is_array($data) === false) {
				return;
			}

			$data['bestandsdelen'] = $this->shownBestandsdelen(objectData: $objectData, uuid: $uuid);

			$response->setData(data: $data);
		} catch (\Throwable $e) {
			// Silently skip enrichment on errors.
		}//end try
	}//end enrichWithBestandsdelen()

	/**
	 * Read a stored document as an array.
	 *
	 * @param string $uuid The document UUID.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array The stored document.
	 *
	 * @throws \Throwable When the document cannot be found.
	 */
	private function storedEioData(string $uuid, array $mappingConfig): array {
		$existing = $this->zgwService->getObjectService()->find(
			$uuid,
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema']
		);

		return $this->objectToArray(row: $existing);
	}//end storedEioData()

	/**
	 * The bestandsdelen a shown document lists: its pending chunks, else [].
	 *
	 * @param array $objectData The stored document.
	 * @param string $uuid The document UUID.
	 *
	 * @return array The bestandsdelen.
	 */
	private function shownBestandsdelen(array $objectData, string $uuid): array {
		$chunkInfo = $this->parseFileParts(objectData: $objectData);
		$bestandsdelen = [];
		if ($chunkInfo !== null && ($chunkInfo['pending'] ?? false) === true) {
			$bestandsdelen = $this->buildBestandsdelenArray(
				uuid: $uuid,
				fileSize: (int)($chunkInfo['fileSize'] ?? 0),
				totalParts: (int)($chunkInfo['totalParts'] ?? 1)
			);
		}

		return $bestandsdelen;
	}//end shownBestandsdelen()

	/**
	 * Resolve the Nextcloud file id of a stored document, or 0.
	 *
	 * Never throws. A missing file id costs three delegated surfaces (Open in
	 * Files, version history, the Files comments sidebar); a document that
	 * cannot be stamped is a worse outcome than one that cannot be created, so
	 * a failure here degrades the metadata rather than failing the write the
	 * caller already committed.
	 *
	 * @param string $uuid The informatieobject UUID
	 * @param string $fileName The stored file name
	 *
	 * @return int The Nextcloud file id, or 0 when it cannot be resolved
	 */
	private function resolveStoredFileId(string $uuid, string $fileName): int {
		try {
			return $this->zgwService->getDocumentService()->getFileId(
				uuid: $uuid,
				fileName: $fileName
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'DRC could not resolve the file id for ' . $uuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return 0;
		}
	}//end resolveStoredFileId()

	/**
	 * Parse the fileParts JSON field from an object data array.
	 *
	 * @param array $objectData The object data array
	 *
	 * @return array|null Decoded chunk info, or null if not set
	 */
	private function parseFileParts(array $objectData): ?array {
		$raw = $objectData['fileParts'] ?? '';
		if ($raw === '') {
			return null;
		}

		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return null;
	}//end parseFileParts()

	/**
	 * Build the bestandsdelen array for a chunked upload response.
	 *
	 * @param string $uuid The document UUID
	 * @param int $fileSize The total file size in bytes
	 * @param int $totalParts The total number of parts
	 *
	 * @return array The bestandsdelen array with volgnummer, omvang, and url
	 */
	private function buildBestandsdelenArray(string $uuid, int $fileSize, int $totalParts): array {
		$baseUrl = $this->zgwService->buildBaseUrl(
			$this->request,
			self::ZGW_API,
			'bestandsdelen'
		);

		$bestandsdelen = [];
		$remaining = $fileSize;

		for ($i = 1; $i <= $totalParts; $i++) {
			$chunkSize = min(self::DEFAULT_CHUNK_SIZE, $remaining);
			$remaining -= $chunkSize;

			$bestandsdelen[] = [
				'url' => $baseUrl . '/' . $uuid . '?volgnummer=' . $i,
				'sequenceNumber' => $i,
				'size' => $chunkSize,
				'lock' => '',
			];
		}

		return $bestandsdelen;
	}//end buildBestandsdelenArray()

	/**
	 * Handle EIO-specific update with lock checking and inhoud handling.
	 *
	 * @param EioUpdateRun $run The update: resource, uuid and whether it is partial.
	 *
	 * @return JSONResponse
	 */
	private function handleEioUpdate(EioUpdateRun $run): JSONResponse {
		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $run->resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $run->resource);
		}

		$run->mappingConfig = $mappingConfig;

		return $this->updateEioOrRefuse(run: $run);
	}//end handleEioUpdate()

	/**
	 * Update the document, answering any failure as a 400 with its message.
	 *
	 * @param EioUpdateRun $run The update, its mapping loaded.
	 *
	 * @return JSONResponse
	 */
	private function updateEioOrRefuse(EioUpdateRun $run): JSONResponse {
		try {
			return $this->updateEio(run: $run);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC update error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}
	}//end updateEioOrRefuse()

	/**
	 * Check the lock and the business rules, then save, store and answer the document.
	 *
	 * @param EioUpdateRun $run The update, its mapping loaded.
	 *
	 * @return JSONResponse
	 */
	private function updateEio(EioUpdateRun $run): JSONResponse {
		$run->body = $this->zgwService->getRequestBody($this->request);

		// Check document lock (drc-009).
		$lockError = $this->checkDocumentLock(run: $run);
		if ($lockError !== null) {
			return $lockError;
		}

		$ruleError = $this->applyUpdateRules(run: $run);
		if ($ruleError !== null) {
			return $ruleError;
		}

		return $this->respondUpdated(run: $run, objectData: $this->saveUpdatedEio(run: $run));
	}//end updateEio()

	/**
	 * Validate the update against the business rules and take their enriched body.
	 *
	 * @param EioUpdateRun $run The update, its body read.
	 *
	 * @return JSONResponse|null The rules' refusal, or null when they accept.
	 */
	private function applyUpdateRules(EioUpdateRun $run): ?JSONResponse {
		$ruleResult = $this->validateUpdate(run: $run);
		if ($ruleResult['valid'] === false) {
			return new JSONResponse(
				data: $this->zgwService->buildValidationError($ruleResult),
				statusCode: $ruleResult['status']
			);
		}

		$run->body = $ruleResult['enrichedBody'];

		return null;
	}//end applyUpdateRules()

	/**
	 * What the business rules answer for this update.
	 *
	 * @param EioUpdateRun $run The update, its body read.
	 *
	 * @return array<string, mixed> The rule result.
	 */
	private function validateUpdate(EioUpdateRun $run): array {
		$action = 'update';
		if ($run->partial === true) {
			$action = 'partial_update';
		}

		return $this->zgwService->getBusinessRulesService()->validate(
			zgwApi: self::ZGW_API,
			resource: $run->resource,
			action: $action,
			body: $run->body,
			objectService: $this->zgwService->getObjectService(),
			mappingConfig: $run->mappingConfig
		);
	}//end validateUpdate()

	/**
	 * Save the update keeping the stored lock, then store its inhoud.
	 *
	 * @param EioUpdateRun $run The update, its body accepted.
	 *
	 * @return array The saved document.
	 */
	private function saveUpdatedEio(EioUpdateRun $run): array {
		$objectData = $this->saveEioKeepingItsLock(
			uuid: $run->uuid,
			englishData: $this->mapEioBody(body: $run->body, mappingConfig: $run->mappingConfig),
			mappingConfig: $run->mappingConfig
		);

		$run->objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? $run->uuid);

		return $this->storeUpdatedInhoud(run: $run, objectData: $objectData);
	}//end saveUpdatedEio()

	/**
	 * Store the file content the update sent, if it sent any.
	 *
	 * @param EioUpdateRun $run        The update, its document saved.
	 * @param array        $objectData The saved document.
	 *
	 * @return array The document, with fileSize and fileId when they were stamped.
	 */
	private function storeUpdatedInhoud(EioUpdateRun $run, array $objectData): array {
		$inhoud = $run->body['inhoud'] ?? null;
		if (empty($inhoud) === true || $run->objectUuid === '') {
			return $objectData;
		}

		return $this->storeInhoud(
			objectData: $objectData,
			objectUuid: $run->objectUuid,
			inhoud: $inhoud,
			mappingConfig: $run->mappingConfig
		);
	}//end storeUpdatedInhoud()

	/**
	 * Announce the updated document and answer it in its ZGW shape.
	 *
	 * @param EioUpdateRun $run        The update, its document saved.
	 * @param array        $objectData The saved document.
	 *
	 * @return JSONResponse
	 */
	private function respondUpdated(EioUpdateRun $run, array $objectData): JSONResponse {
		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $run->resource);
		$mapped = $this->mapEioOut(objectData: $objectData, mappingConfig: $run->mappingConfig, baseUrl: $baseUrl);

		$this->zgwService->publishNotification(
			self::ZGW_API,
			$run->resource,
			$baseUrl . '/' . $run->objectUuid,
			'update'
		);

		return new JSONResponse(data: $mapped);
	}//end respondUpdated()

	/**
	 * Save an updated document, keeping the lock state the stored one holds.
	 *
	 * @param string $uuid The document uuid.
	 * @param array $englishData The mapped update.
	 * @param array $mappingConfig The EIO mapping.
	 *
	 * @return array The saved document.
	 */
	private function saveEioKeepingItsLock(string $uuid, array $englishData, array $mappingConfig): array {
		$objectService = $this->zgwService->getObjectService();
		$existingData = $this->objectToArray(
			row: $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			)
		);

		// Preserve lock state.
		$englishData['locked'] = $existingData['locked'] ?? false;
		$englishData['lockId'] = $existingData['lockId'] ?? '';

		return $this->objectToArray(
			row: $objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData,
				uuid: $uuid
			)
		);
	}//end saveEioKeepingItsLock()

	/**
	 * Check document lock state before allowing update.
	 *
	 * Validates DRC business rules:
	 * - drc-009a/b: Document must be locked for updates.
	 * - drc-009d/e: Lock ID must be provided.
	 * - drc-009h/i: Lock ID must match the stored lock.
	 *
	 * @param EioUpdateRun $run The update: the document, its mapping, the body and whether it is partial.
	 *
	 * @return JSONResponse|null Error response if lock check fails, null if OK.
	 */
	private function checkDocumentLock(EioUpdateRun $run): ?JSONResponse {
		// Drc-009a/b: Document must be locked to allow updates.
		// Try OpenRegister's LockHandler first, then check the object data
		// blob (used by lockFallback in JWT-only contexts).
		$storedLockId = $this->resolveStoredLockId(
			document: new EioDocument(
				objectService: $this->zgwService->getObjectService(),
				mappingConfig: $run->mappingConfig,
				uuid: $run->uuid
			)
		);

		if ($storedLockId === null) {
			return $this->unlockedRefusal();
		}

		return $this->lockIdRefusal(run: $run, storedLockId: $storedLockId);
	}//end checkDocumentLock()

	/**
	 * Refuse an update of a locked document whose lock ID is missing or does not match.
	 *
	 * @param EioUpdateRun $run          The update: the body and whether it is partial.
	 * @param string       $storedLockId The document's lock ID.
	 *
	 * @return JSONResponse|null Error response if the lock ID is missing or wrong, null if it matches.
	 */
	private function lockIdRefusal(EioUpdateRun $run, string $storedLockId): ?JSONResponse {
		$providedLockId = $run->body['lock'] ?? '';

		// Drc-009d/e: Lock ID must be provided.
		if ($providedLockId === '') {
			return $this->missingLockIdRefusal(run: $run);
		}

		// Drc-009h/i: Lock ID must match.
		if ($providedLockId !== $storedLockId) {
			return $this->incorrectLockIdRefusal();
		}

		return null;
	}//end lockIdRefusal()

	/**
	 * Refuse an update of a locked document that names no lock ID.
	 *
	 * PUT (full update): lock is a required field (drc-009d). PATCH (partial):
	 * lock is missing for lock enforcement (drc-009e).
	 *
	 * @param EioUpdateRun $run The update: whether it is partial.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function missingLockIdRefusal(EioUpdateRun $run): JSONResponse {
		$missing = ['name' => 'nonFieldErrors', 'code' => 'missing-lock-id'];
		if ($run->partial === false) {
			$missing = ['name' => 'lock', 'code' => 'required'];
		}

		return $this->lockRefusal(
			detail: $this->l10n->t('Lock ID is required for editing a locked document.'),
			invalidParam: $missing + ['reason' => $this->l10n->t('Lock ID is missing from the request.')]
		);
	}//end missingLockIdRefusal()

	/**
	 * Refuse an update of a document that is not locked (drc-009a/b).
	 *
	 * @return JSONResponse The refusal.
	 */
	private function unlockedRefusal(): JSONResponse {
		return $this->lockRefusal(
			detail: $this->l10n->t('Only locked documents may be edited.'),
			invalidParam: [
				'name' => 'nonFieldErrors',
				'code' => 'unlocked',
				'reason' => $this->l10n->t('The document is not locked. Lock the document first.'),
			]
		);
	}//end unlockedRefusal()

	/**
	 * Refuse an update whose lock ID does not match the stored lock (drc-009h/i).
	 *
	 * @return JSONResponse The refusal.
	 */
	private function incorrectLockIdRefusal(): JSONResponse {
		return $this->lockRefusal(
			detail: $this->l10n->t('Lock ID does not match.'),
			invalidParam: [
				'name' => 'nonFieldErrors',
				'code' => 'incorrect-lock-id',
				'reason' => $this->l10n->t('Lock ID does not match the stored lock.'),
			]
		);
	}//end incorrectLockIdRefusal()

	/**
	 * A 400 refusing an update for its lock, in the ZGW invalidParams envelope.
	 *
	 * @param string                $detail       The detail.
	 * @param array<string, string> $invalidParam The one invalid param: name, code and reason.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function lockRefusal(string $detail, array $invalidParam): JSONResponse {
		return new JSONResponse(
			data: ['detail' => $detail, 'invalidParams' => [$invalidParam]],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end lockRefusal()

	/**
	 * Resolve the stored lock ID from either OpenRegister's LockHandler
	 * or the object data blob (fallback lock).
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return string|null The stored lock ID, or null if not locked
	 */
	private function resolveStoredLockId(EioDocument $document): ?string {
		// Try OpenRegister's dedicated lock system first, then the object data blob.
		return $this->lockIdFromLockSystem(objectService: $document->objectService, uuid: $document->uuid)
			?? $this->lockIdFromObject(document: $document);
	}//end resolveStoredLockId()

	/**
	 * The lock id OpenRegister's lock system holds for the object, if it has that system.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $uuid The document uuid.
	 *
	 * @return string|null The lock id, or null when there is none or no lock system.
	 */
	private function lockIdFromLockSystem(object $objectService, string $uuid): ?string {
		if (method_exists($objectService, 'getLockInfo') === false) {
			return null;
		}

		try {
			$lockId = $objectService->getLockInfo($uuid)['lock_id'] ?? null;
			if ($lockId !== null && $lockId !== '') {
				return $lockId;
			}
		} catch (\Throwable $e) {
			// GetLockInfo not available — fall through to data blob check.
			return null;
		}

		return null;
	}//end lockIdFromLockSystem()

	/**
	 * The lock id stored on the object by lock/lockFallback, or 'entity-lock' for a locked flag.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return string|null The lock id, or null when the object is not locked or not found.
	 */
	private function lockIdFromObject(EioDocument $document): ?string {
		try {
			$existingData = $this->objectToArray(
				row: $document->objectService->find(
					$document->uuid,
					register: $document->mappingConfig['sourceRegister'],
					schema: $document->mappingConfig['sourceSchema']
				)
			);
		} catch (\Throwable $e) {
			// Object not found — treat as not locked.
			return null;
		}

		$lockId = $existingData['lockId'] ?? null;
		if ($lockId !== null && $lockId !== '') {
			return (string)$lockId;
		}

		// Fallback: check locked field (boolean or entity lock structure).
		$isLocked = $existingData['locked'] ?? false;
		if (in_array($isLocked, [true, 'true', 1], true) === true || is_array($isLocked) === true) {
			return 'entity-lock';
		}

		return null;
	}//end lockIdFromObject()

	/**
	 * Store a ZGW lockId in the object data blob.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 * @param string      $lockId   The lock ID to store
	 *
	 * @return void
	 */
	private function storeLockIdInData(EioDocument $document, string $lockId): void {
		try {
			$existingData = $this->lockDataOf(document: $document);
			$existingData['locked'] = true;
			$existingData['lockId'] = $lockId;

			$this->saveLockData(document: $document, data: $existingData);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning('DRC: Failed to store lockId in data blob: ' . $e->getMessage());
		}//end try
	}//end storeLockIdInData()

	/**
	 * Clear the ZGW lockId from the object data blob after unlocking.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return void
	 */
	private function clearLockIdInData(EioDocument $document): void {
		try {
			$existingData = $this->lockDataOf(document: $document);
			$existingData['locked'] = false;
			$existingData['lockId'] = '';

			$this->saveLockData(document: $document, data: $existingData);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning('DRC: Failed to clear lockId in data blob: ' . $e->getMessage());
		}//end try
	}//end clearLockIdInData()

	/**
	 * The document's stored data, without the keys a save must not carry.
	 *
	 * @param EioDocument $document The document, its object service and its mapping.
	 *
	 * @return array<string, mixed> The data to write back.
	 *
	 * @throws \Throwable When the document cannot be found.
	 */
	private function lockDataOf(EioDocument $document): array {
		$existing = $document->objectService->find(
			$document->uuid,
			register: $document->mappingConfig['sourceRegister'],
			schema: $document->mappingConfig['sourceSchema']
		);
		$existingData = $this->objectToArray(row: $existing);

		unset($existingData['@self'], $existingData['id'], $existingData['organisation']);

		return $existingData;
	}//end lockDataOf()

	/**
	 * Write the document's data back.
	 *
	 * @param EioDocument          $document The document, its object service and its mapping.
	 * @param array<string, mixed> $data     The data to write.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the save fails.
	 */
	private function saveLockData(EioDocument $document, array $data): void {
		$document->objectService->saveObject(
			register: $document->mappingConfig['sourceRegister'],
			schema: $document->mappingConfig['sourceSchema'],
			object: $data,
			uuid: $document->uuid
		);
	}//end saveLockData()
}//end class
