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

use OCA\Dossiq\Service\ZgwService;
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
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

			$objects = $result['results'] ?? [];
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

			return new JSONResponse(data: $mapped);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC list ' . $resource . ' error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Internal server error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end indexFlatArray()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
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

			$body = $ruleResult['enrichedBody'];

			$inhoud = $body['inhoud'] ?? null;

			$englishData = $this->eioEnglishData(body: $body, mappingConfig: $mappingConfig);
			$bestandsomvang = (int)($body['bestandsomvang'] ?? 0);

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
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC create error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end createEio()

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
		$fileName = $objectData['fileName'] ?? 'document';
		if ($fileName === '') {
			$fileName = 'document';
		}

		$fileSize = $this->zgwService->getDocumentService()->storeBase64(
			uuid: $objectUuid,
			fileName: $fileName,
			content: $inhoud
		);

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
			if ($needsSize === true) {
				$objectData['fileSize'] = $fileSize;
			}

			if ($fileId > 0) {
				$objectData['fileId'] = $fileId;
			}

			// The WHOLE object, never a partial: ObjectService::saveObject
			// REPLACES, and a partial write drops the four properties the
			// informatieobject schema requires (#1960).
			$objectData['uuid'] = $objectUuid;
			$this->zgwService->getObjectService()->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $objectData
			);
		}

		return $objectData;
	}//end storeInhoud()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
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
			return $this->handleEioUpdate(resource: $resource, uuid: $uuid, partial: false);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
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
			return $this->handleEioUpdate(resource: $resource, uuid: $uuid, partial: true);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
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
	 * Refused while ObjectInformatieObjecten point at it (drc-008a). After a successful delete its
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
		if ($this->zgwService->getObjectService() !== null
			&& empty($this->findOioRelationsForEio(eioUuid: $uuid)) === false
		) {
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

		$response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, self::EIO_RESOURCE, $uuid);
		if ($response->getStatus() !== Http::STATUS_NO_CONTENT) {
			return $response;
		}

		// Drc-008 (VNG): Cascade delete gebruiksrechten after EIO deletion.
		$this->cascadeDeleteGebruiksrechten(eioUuid: $uuid);

		if ($hasStoredFiles === true) {
			try {
				$this->zgwService->getDocumentService()->deleteFiles(uuid: $uuid);
			} catch (\Throwable $e) {
				$this->zgwService->getLogger()->warning(
					'DRC file cleanup failed: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}

		return $response;
	}//end destroyEio()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function download(string $uuid): DataDownloadResponse|JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
		if ($mappingConfig === null) {
			return new JSONResponse(
				data: ['detail' => 'Document mapping not configured'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$object = $this->zgwService->getObjectService()->find(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				id: $uuid
			);
			$objectData = $this->objectToArray(row: $object);

			$fileName = $objectData['fileName'] ?? 'document';
			if ($fileName === '') {
				$fileName = 'document';
			}

			$format = $objectData['format'] ?? 'application/octet-stream';

			if ($this->zgwService->getDocumentService()->fileExists(uuid: $uuid, fileName: $fileName) === false) {
				return new JSONResponse(
					data: ['detail' => $this->l10n->t('File not found.')],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$content = $this->zgwService->getDocumentService()->getContent(uuid: $uuid, fileName: $fileName);

			return new DataDownloadResponse(data: $content, filename: $fileName, contentType: $format);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC download error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}//end try
	}//end download()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function lock(string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		// Check if already locked (entity lock or data blob fallback).
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig !== null
			&& $this->resolveStoredLockId(
				objectService: $objectService,
				mappingConfig: $mappingConfig,
				uuid: $uuid
			) !== null
		) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('Document is already locked.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$objectService->lockObject(identifier: $uuid);

			// OpenRegister's lock system doesn't produce a ZGW lockId.
			// Generate one and store it in the data blob for verification.
			$lockId = bin2hex(random_bytes(16));
			if ($mappingConfig !== null) {
				$this->storeLockIdInData(
					objectService: $objectService,
					mappingConfig: $mappingConfig,
					uuid: $uuid,
					lockId: $lockId
				);
			}

			return new JSONResponse(
				data: ['lock' => $lockId],
				statusCode: Http::STATUS_OK
			);
		} catch (\OCA\OpenRegister\Exception\LockedException $e) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('Document is already locked.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			// Fallback: OpenRegister lock may fail without a Nextcloud user
			// session (JWT-only context). Use manual lock via saveObject.
			return $this->lockFallback(objectService: $objectService, uuid: $uuid, original: $e);
		}//end try
	}//end lock()

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
			return new JSONResponse(
				data: ['detail' => $original->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existing);

			$lockId = bin2hex(random_bytes(16));

			unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
			$existingData['locked'] = true;
			$existingData['lockId'] = $lockId;

			$objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $existingData,
				uuid: $uuid
			);

			return new JSONResponse(data: ['lock' => $lockId], statusCode: Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC lock fallback error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end lockFallback()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function unlock(string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);

		// Check if the document is actually locked (entity or data blob).
		$storedLockId = null;
		if ($mappingConfig !== null) {
			$storedLockId = $this->resolveStoredLockId(
				objectService: $objectService,
				mappingConfig: $mappingConfig,
				uuid: $uuid
			);
		}

		if ($storedLockId === null) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('Document is not locked.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$body = $this->zgwService->getRequestBody($this->request);
		$lockId = $body['lock'] ?? '';

		// Determine if this is a forced unlock (wrong/empty lockId + scope).
		$refusal = $this->refuseUnforcedUnlock(lockId: $lockId, storedLockId: $storedLockId);
		if ($refusal !== null) {
			return $refusal;
		}

		// Try OpenRegister's LockHandler, fall back to clearing data blob.
		try {
			$objectService->unlockObject(identifier: $uuid);

			// Clear lockId from the data blob.
			if ($mappingConfig !== null) {
				$this->clearLockIdInData(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);
			}

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			// Fallback: unlock via saveObject when LockHandler fails
			// (e.g., no Nextcloud user session in JWT-only context).
			return $this->unlockFallback(objectService: $objectService, uuid: $uuid, original: $e);
		}
	}//end unlock()

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
			return new JSONResponse(
				data: ['detail' => $original->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existing);

			unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
			$existingData['locked'] = false;
			$existingData['lockId'] = '';

			foreach ($existingData as $key => $value) {
				if ($value === null) {
					unset($existingData[$key]);
				}
			}

			$objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $existingData,
				uuid: $uuid
			);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC unlock fallback error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end unlockFallback()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
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
	 */
	private function findOioRelationsForEio(string $eioUuid): array {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		// Check OIO, ZIO, and BIO schemas for references to this EIO.
		$schemasToCheck = [];

		// OIO (ObjectInformatieObject) — DRC register.
		$oioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'objectinformatieobjecten');
		if ($oioConfig !== null) {
			$schemasToCheck[] = [
				'register' => $oioConfig['sourceRegister'],
				'schema' => $oioConfig['sourceSchema'],
			];
		}

		// ZIO (ZaakInformatieObject) — ZRC register.
		$zioConfig = $this->zgwService->loadMappingConfig('zaken', 'zaakinformatieobjecten');
		if ($zioConfig !== null) {
			$schemasToCheck[] = [
				'register' => $zioConfig['sourceRegister'],
				'schema' => $zioConfig['sourceSchema'],
			];
		}

		// BIO (BesluitInformatieObject) — BRC register.
		$bioConfig = $this->zgwService->loadMappingConfig('besluiten', 'besluitinformatieobjecten');
		if ($bioConfig !== null) {
			$schemasToCheck[] = [
				'register' => $bioConfig['sourceRegister'],
				'schema' => $bioConfig['sourceSchema'],
			];
		}

		foreach ($schemasToCheck as $schemaInfo) {
			$ids = $this->searchRelationsInSchema(
				objectService: $objectService,
				eioUuid: $eioUuid,
				register: $schemaInfo['register'],
				schema: $schemaInfo['schema']
			);
			if (empty($ids) === false) {
				return $ids;
			}
		}

		return [];
	}//end findOioRelationsForEio()

	/**
	 * Search for document relations in a specific schema.
	 *
	 * @param object $objectService The object service
	 * @param string $eioUuid The EIO UUID to search for
	 * @param string $register The register ID
	 * @param string $schema The schema ID
	 *
	 * @return array List of related object IDs
	 */
	private function searchRelationsInSchema(
		object $objectService,
		string $eioUuid,
		string $register,
		string $schema,
	): array {
		try {
			// Try exact UUID match (OIO may store just the UUID).
			$query = $objectService->buildSearchQuery(
				requestParams: ['document' => $eioUuid, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);
			$ids = $this->extractIdsFromResults(result: $result);
			if (empty($ids) === false) {
				return $ids;
			}

			// Fallback: full-text search by UUID (document field stores
			// the full URL, and field-specific LIKE is not supported).
			$query = $objectService->buildSearchQuery(
				requestParams: ['_search' => $eioUuid, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);
			$ids = $this->extractIdsFromResults(result: $result);
			if (empty($ids) === false) {
				return $ids;
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-008a: Relation search failed for schema ' . $schema . ': ' . $e->getMessage()
			);
		}//end try

		return [];
	}//end searchRelationsInSchema()

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
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 */
	private function cascadeDeleteGebruiksrechten(string $eioUuid): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($grConfig === null) {
			return;
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
			return;
		}

		try {
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
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-008: Failed to cascade delete gebruiksrechten for EIO ' . $eioUuid . ': ' . $e->getMessage()
			);
		}//end try
	}//end cascadeDeleteGebruiksrechten()

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
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 */
	private function checkAndClearIndicationGebruiksrecht(string $eioUuid): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($grConfig === null) {
			return;
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
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: ['document' => $eioUuid, '_limit' => 1],
				register: $grScope->register,
				schema: $grScope->schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);
			$total = $result['total'] ?? count($result['results'] ?? []);

			if ($total === 0) {
				// No more gebruiksrechten — clear indicatieGebruiksrecht.
				$eioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
				if ($eioConfig !== null) {
					try {
						$eioObj = $objectService->find(
							$eioUuid,
							register: $eioConfig['sourceRegister'],
							schema: $eioConfig['sourceSchema']
						);
						$eioData = $this->objectToArray(row: $eioObj);

						$eioData['usageRightsIndication'] = null;

						unset($eioData['@self'], $eioData['id'], $eioData['organisation']);
						$objectService->saveObject(
							register: $eioConfig['sourceRegister'],
							schema: $eioConfig['sourceSchema'],
							object: $eioData,
							uuid: $eioUuid
						);
					} catch (\Throwable $e) {
						$this->zgwService->getLogger()->warning(
							'drc-006: Failed to clear indicatieGebruiksrecht: ' . $e->getMessage()
						);
					}//end try
				}//end if
			}//end if
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-006: Failed to check remaining gebruiksrechten: ' . $e->getMessage()
			);
		}//end try
	}//end checkAndClearIndicatieGebruiksrecht()

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

		try {
			$eioObj = $objectService->find(
				$ioMatches[1],
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
				uuid: $ioMatches[1]
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-006: Failed to set indicatieGebruiksrecht: ' . $e->getMessage()
			);
		}//end try
	}//end setIndicatieGebruiksrecht()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function uploadChunk(string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, self::EIO_RESOURCE);
		}

		try {
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

			// Store the chunk.
			$docService = $this->zgwService->getDocumentService();
			$chunkSize = $docService->storeChunk(
				uuid: $uuid,
				sequenceNumber: $sequenceNumber,
				content: $content
			);

			// Check if all chunks have been uploaded.
			$uploaded = $docService->getUploadedChunks(uuid: $uuid, totalParts: $totalParts);

			if (count($uploaded) === $totalParts) {
				// All chunks present — merge into final file.
				$mergedSize = $this->mergeUploadedChunks(objectData: $objectData, uuid: $uuid, totalParts: $totalParts, mappingConfig: $mappingConfig);

				return $this->chunkProgress(
					progress: ['sequenceNumber' => $sequenceNumber, 'size' => $chunkSize, 'uploadComplete' => true, 'bestandsomvang' => $mergedSize],
					uploadedParts: count($uploaded),
					totalParts: $totalParts
				);
			}//end if

			return $this->chunkProgress(
				progress: ['sequenceNumber' => $sequenceNumber, 'size' => $chunkSize, 'uploadComplete' => false],
				uploadedParts: count($uploaded),
				totalParts: $totalParts
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC chunk upload error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end uploadChunk()

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
		$fileName = $objectData['fileName'] ?? 'document';
		if ($fileName === '') {
			$fileName = 'document';
		}

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
			$existing = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$objectData = $this->objectToArray(row: $existing);

			$data = $response->getData();
			if (is_array($data) === false) {
				return;
			}

			$chunkInfo = $this->parseFileParts(objectData: $objectData);
			$data['bestandsdelen'] = [];
			if ($chunkInfo !== null && ($chunkInfo['pending'] ?? false) === true) {
				$data['bestandsdelen'] = $this->buildBestandsdelenArray(
					uuid: $uuid,
					fileSize: (int)($chunkInfo['fileSize'] ?? 0),
					totalParts: (int)($chunkInfo['totalParts'] ?? 1)
				);
			}

			$response->setData(data: $data);
		} catch (\Throwable $e) {
			// Silently skip enrichment on errors.
		}//end try
	}//end enrichWithBestandsdelen()

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
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 * @param bool $partial Whether this is a partial (PATCH) update.
	 *
	 * @return JSONResponse
	 */
	private function handleEioUpdate(string $resource, string $uuid, bool $partial): JSONResponse {
		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$body = $this->zgwService->getRequestBody($this->request);

			// Check document lock (drc-009).
			$lockError = $this->checkDocumentLock(
				mappingConfig: $mappingConfig,
				uuid: $uuid,
				body: $body,
				partial: $partial
			);
			if ($lockError !== null) {
				return $lockError;
			}

			$action = 'update';
			if ($partial === true) {
				$action = 'partial_update';
			}

			$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
				zgwApi: self::ZGW_API,
				resource: $resource,
				action: $action,
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

			$body = $ruleResult['enrichedBody'];

			$inhoud = $body['inhoud'] ?? null;

			$objectData = $this->saveEioKeepingItsLock(
				uuid: $uuid,
				englishData: $this->mapEioBody(body: $body, mappingConfig: $mappingConfig),
				mappingConfig: $mappingConfig
			);

			$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? $uuid);

			// Store file content.
			if (empty($inhoud) === false && $objectUuid !== '') {
				$objectData = $this->storeInhoud(objectData: $objectData, objectUuid: $objectUuid, inhoud: $inhoud, mappingConfig: $mappingConfig);
			}

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$mapped = $this->mapEioOut(objectData: $objectData, mappingConfig: $mappingConfig, baseUrl: $baseUrl);

			$this->zgwService->publishNotification(
				self::ZGW_API,
				$resource,
				$baseUrl . '/' . $objectUuid,
				'update'
			);

			return new JSONResponse(data: $mapped);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC update error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end handleEioUpdate()

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
	 * @param array $mappingConfig The mapping configuration.
	 * @param string $uuid The document UUID.
	 * @param array $body The request body.
	 * @param bool $partial Whether this is a partial (PATCH) update.
	 *
	 * @return JSONResponse|null Error response if lock check fails, null if OK.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $partial distinguishes PUT vs PATCH lock semantics
	 */
	private function checkDocumentLock(
		array $mappingConfig,
		string $uuid,
		array $body,
		bool $partial = false,
	): ?JSONResponse {
		$objectService = $this->zgwService->getObjectService();

		// Drc-009a/b: Document must be locked to allow updates.
		// Try OpenRegister's LockHandler first, then check the object data
		// blob (used by lockFallback in JWT-only contexts).
		$storedLockId = $this->resolveStoredLockId(
			objectService: $objectService,
			mappingConfig: $mappingConfig,
			uuid: $uuid
		);

		if ($storedLockId === null) {
			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Only locked documents may be edited.'),
					'invalidParams' => [
						[
							'name' => 'nonFieldErrors',
							'code' => 'unlocked',
							'reason' => $this->l10n->t('The document is not locked. Lock the document first.'),
						],
					],
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$providedLockId = $body['lock'] ?? '';

		// Drc-009d/e: Lock ID must be provided.
		if ($providedLockId === '') {
			// PUT (full update): lock is a required field (drc-009d).
			// PATCH (partial): lock is missing for lock enforcement (drc-009e).
			$errorName = 'nonFieldErrors';
			$errorCode = 'missing-lock-id';
			if ($partial === false) {
				$errorName = 'lock';
				$errorCode = 'required';
			}

			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Lock ID is required for editing a locked document.'),
					'invalidParams' => [
						[
							'name' => $errorName,
							'code' => $errorCode,
							'reason' => $this->l10n->t('Lock ID is missing from the request.'),
						],
					],
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end if

		// Drc-009h/i: Lock ID must match.
		if ($providedLockId !== $storedLockId) {
			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Lock ID does not match.'),
					'invalidParams' => [
						[
							'name' => 'nonFieldErrors',
							'code' => 'incorrect-lock-id',
							'reason' => $this->l10n->t('Lock ID does not match the stored lock.'),
						],
					],
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end checkDocumentLock()

	/**
	 * Resolve the stored lock ID from either OpenRegister's LockHandler
	 * or the object data blob (fallback lock).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $mappingConfig The mapping configuration
	 * @param string $uuid The document UUID
	 *
	 * @return string|null The stored lock ID, or null if not locked
	 */
	private function resolveStoredLockId(
		object $objectService,
		array $mappingConfig,
		string $uuid,
	): ?string {
		// Try OpenRegister's dedicated lock system first, then the object data blob.
		return $this->lockIdFromLockSystem(objectService: $objectService, uuid: $uuid)
			?? $this->lockIdFromObject(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);
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
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array $mappingConfig The EIO mapping.
	 * @param string $uuid The document uuid.
	 *
	 * @return string|null The lock id, or null when the object is not locked or not found.
	 */
	private function lockIdFromObject(object $objectService, array $mappingConfig, string $uuid): ?string {
		try {
			$existingData = $this->objectToArray(
				row: $objectService->find(
					$uuid,
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema']
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
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $mappingConfig The mapping configuration
	 * @param string $uuid The document UUID
	 * @param string $lockId The lock ID to store
	 *
	 * @return void
	 */
	private function storeLockIdInData(
		object $objectService,
		array $mappingConfig,
		string $uuid,
		string $lockId,
	): void {
		try {
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existing);

			unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
			$existingData['locked'] = true;
			$existingData['lockId'] = $lockId;

			$objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $existingData,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'DRC: Failed to store lockId in data blob: ' . $e->getMessage()
			);
		}//end try
	}//end storeLockIdInData()

	/**
	 * Clear the ZGW lockId from the object data blob after unlocking.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $mappingConfig The mapping configuration
	 * @param string $uuid The document UUID
	 *
	 * @return void
	 */
	private function clearLockIdInData(
		object $objectService,
		array $mappingConfig,
		string $uuid,
	): void {
		try {
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existing);

			unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
			$existingData['locked'] = false;
			$existingData['lockId'] = '';

			$objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $existingData,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'DRC: Failed to clear lockId in data blob: ' . $e->getMessage()
			);
		}//end try
	}//end clearLockIdInData()
}//end class
