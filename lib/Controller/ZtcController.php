<?php

/**
 * Dossiq ZTC (Catalogi) Controller
 *
 * Controller for serving ZGW Catalogi API endpoints (catalogussen, zaaktypen,
 * statustypen, resultaattypen, roltypen, eigenschappen, informatieobjecttypen,
 * besluittypen, zaaktype-informatieobjecttypen). Delegates shared operations
 * to ZgwService and handles ZTC-specific publish logic.
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

use OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher;
use OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * ZTC (Catalogi) API Controller
 *
 * Handles ZGW Catalogi register resources with publish support for
 * zaaktypen, besluittypen, and informatieobjecttypen.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZtcController extends ZgwController {
	/**
	 * The ZGW API identifier for the Catalogi register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'catalogi';

	/**
	 * Resources that need URL validity filtering in responses.
	 *
	 * Maps resource name to the fields containing URL arrays that need filtering,
	 * and the schema config key to look up each referenced type.
	 *
	 * @var array<string, array<string, array{schemaKey: string, nested: bool}>>
	 */
	private const URL_FILTER_FIELDS = [
		'zaaktypen' => [
			'informatieobjecttypen' => [
				'schemaKey' => 'document_type_schema',
				'nested' => false,
			],
			'besluittypen' => [
				'schemaKey' => 'decision_type_schema',
				'nested' => false,
			],
			'deelzaaktypen' => [
				'schemaKey' => 'case_type_schema',
				'nested' => false,
			],
			'gerelateerdeZaaktypen' => [
				'schemaKey' => 'case_type_schema',
				'nested' => true,
			],
		],
		'besluittypen' => [
			'informatieobjecttypen' => [
				'schemaKey' => 'document_type_schema',
				'nested' => false,
			],
			'zaaktypen' => [
				'schemaKey' => 'case_type_schema',
				'nested' => false,
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwService $zgwService The shared ZGW service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List resources of the given type.
	 *
	 * @param string $resource The ZGW resource name (e.g. catalogussen, zaaktypen).
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

		$response = $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);

		if ($response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		$data = $response->getData();
		if (is_array($data) === false || isset($data['results']) === false || is_array($data['results']) === false) {
			return $response;
		}

		$data = $this->postProcessIndex(resource: $resource, data: $data);

		return new JSONResponse(data: $data, statusCode: Http::STATUS_OK);
	}//end index()

	/**
	 * Filter a page of ZTC results by datumGeldigheid, and enrich and filter each result's
	 * cross-references.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param array $data The page, with its `results` list.
	 *
	 * @return array The processed page.
	 */
	private function postProcessIndex(string $resource, array $data): array {
		// ZTC datumGeldigheid: post-filter results by date validity.
		$dateValidity = $this->request->getParam('datumGeldigheid');
		if ($dateValidity !== null && $dateValidity !== '') {
			$data['results'] = $this->filterByDateValidity(
				results: $data['results'],
				dateValidity: $dateValidity
			);
			$data['count'] = count($data['results']);
		}

		// Enrich cross-references and filter invalid URLs from paginated results.
		if (isset(self::URL_FILTER_FIELDS[$resource]) === true) {
			foreach ($data['results'] as $idx => $item) {
				$item = $this->enrichCrossReferences(resource: $resource, data: $item);
				$data['results'][$idx] = $this->filterValidUrls(resource: $resource, data: $item);
			}
		}

		return $data;
	}//end postProcessIndex()

	/**
	 * Create a new resource of the given type.
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

		// SB2: Gate creates on catalogi.schrijven scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'ztc', 'catalogi.schrijven') === false) {
			return $this->scopeDeniedResponse(scope: 'catalogi.schrijven');
		}

		// Ztc-010: Resolve parent zaaktype draft status for sub-resource creation.
		$body = $this->zgwService->getRequestBody($this->request);
		$parentCaseTypeDraft = $this->zgwService->resolveParentZaaktypeDraftFromBody($resource, $body);

		// Ztc-010m: For ZIOT, resolve informatieobjecttype by omschrijving if not a UUID/URL.
		if ($resource === 'zaaktype-informatieobjecttypen') {
			$this->resolveIotByOmschrijving(body: $body);
		}

		$response = $this->zgwService->handleCreate(
			$this->request,
			self::ZGW_API,
			$resource,
			parentCaseTypeDraft: $parentCaseTypeDraft
		);

		// Enrich cross-references on create response (without validity filtering
		// since referenced types may not yet be published at creation time).
		if (isset(self::URL_FILTER_FIELDS[$resource]) === true
			&& $response->getStatus() === Http::STATUS_CREATED
		) {
			$data = $response->getData();
			$data = $this->enrichCrossReferences(resource: $resource, data: $data);

			return new JSONResponse(data: $data, statusCode: Http::STATUS_CREATED);
		}

		return $response;
	}//end create()

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

		// Enrich cross-references and filter invalid URLs.
		if (isset(self::URL_FILTER_FIELDS[$resource]) === true
			&& $response->getStatus() === Http::STATUS_OK
		) {
			$data = $response->getData();
			$data = $this->enrichCrossReferences(resource: $resource, data: $data);
			$filtered = $this->filterValidUrls(resource: $resource, data: $data);

			return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
		}

		return $response;
	}//end show()

	/**
	 * Resolve the parent zaaktype draft status for a sub-resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return bool|null The parent zaaktype draft status, or null if not applicable.
	 */
	private function resolveParentDraft(string $resource, string $uuid): ?bool {
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null || $this->zgwService->getObjectService() === null) {
			return null;
		}

		try {
			$existingObj = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existingObj);

			return $this->zgwService->resolveParentZaaktypeDraft($resource, $existingData);
		} catch (\Throwable $e) {
			// Proceed without parent zaaktype info.
			return null;
		}
	}//end resolveParentDraft()

	/**
	 * Full update (PUT) a resource by UUID.
	 *
	 * For sub-resources of zaaktypen, resolves parentZaaktypeDraft before delegating.
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

		// SB2: Gate updates on catalogi.schrijven scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'ztc', 'catalogi.schrijven') === false) {
			return $this->scopeDeniedResponse(scope: 'catalogi.schrijven');
		}

		$parentZtDraft = $this->resolveParentDraft(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			false,
			$parentZtDraft
		);

		// Enrich cross-references and filter invalid URLs.
		if (isset(self::URL_FILTER_FIELDS[$resource]) === true
			&& $response->getStatus() === Http::STATUS_OK
		) {
			$data = $response->getData();
			$data = $this->enrichCrossReferences(resource: $resource, data: $data);
			$filtered = $this->filterValidUrls(resource: $resource, data: $data);

			return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
		}

		return $response;
	}//end update()

	/**
	 * Partial update (PATCH) a resource by UUID.
	 *
	 * For sub-resources of zaaktypen, resolves parentZaaktypeDraft before delegating.
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

		// SB2: Gate patches on catalogi.schrijven scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'ztc', 'catalogi.schrijven') === false) {
			return $this->scopeDeniedResponse(scope: 'catalogi.schrijven');
		}

		$parentZtDraft = $this->resolveParentDraft(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			true,
			$parentZtDraft
		);

		// Enrich cross-references and filter invalid URLs.
		if (isset(self::URL_FILTER_FIELDS[$resource]) === true
			&& $response->getStatus() === Http::STATUS_OK
		) {
			$data = $response->getData();
			$data = $this->enrichCrossReferences(resource: $resource, data: $data);
			$filtered = $this->filterValidUrls(resource: $resource, data: $data);

			return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
		}

		return $response;
	}//end patch()

	/**
	 * Delete a resource by UUID.
	 *
	 * For sub-resources of zaaktypen, resolves parentZaaktypeDraft before delegating.
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

		// SB2: Gate destroys on catalogi.schrijven scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'ztc', 'catalogi.schrijven') === false) {
			return $this->scopeDeniedResponse(scope: 'catalogi.schrijven');
		}

		$parentZtDraft = $this->resolveParentDraft(resource: $resource, uuid: $uuid);

		return $this->zgwService->handleDestroy(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			$parentZtDraft
		);
	}//end destroy()

	/**
	 * Publish a ZTC resource by setting isDraft to false.
	 *
	 * Loads the existing object, sets isDraft=false, saves it back,
	 * and returns the outbound-mapped result.
	 *
	 * @param string $resource The ZGW resource name (zaaktypen, besluittypen, informatieobjecttypen).
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 */
	private function handlePublish(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$existing = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->publishedCopy(existingData: $this->objectToArray(row: $existing));

			$object = $this->zgwService->getObjectService()->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $existingData,
				uuid: $uuid
			);
			$objectData = $this->objectToArray(row: $object);

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);

			return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'ZTC publish error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(data: ['detail' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end handlePublish()

	/**
	 * The stored type as it is saved on publish: concept off, bookkeeping keys gone.
	 *
	 * @param array $existingData The stored type
	 *
	 * @return array The data to save
	 */
	private function publishedCopy(array $existingData): array {
		unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
		$existingData['isDraft'] = false;

		if (isset($existingData['identifier']) === true && is_int($existingData['identifier']) === true) {
			$existingData['identifier'] = (string)$existingData['identifier'];
		}

		// Re-encode fields that are stored as JSON strings but auto-decoded
		// by jsonSerialize. Only string-typed schema fields need re-encoding.
		// productsOrServices is NOT in this list: it is declared as an array,
		// so re-encoding it here wrote a string into an array property.
		foreach (['referenceProcess', 'relatedCaseTypes'] as $field) {
			if (isset($existingData[$field]) === true && is_array($existingData[$field]) === true) {
				$existingData[$field] = json_encode($existingData[$field]);
			}
		}

		return $existingData;
	}//end publishedCopy()

	/**
	 * Publish a zaaktype (set isDraft to false).
	 *
	 * @param string $uuid The zaaktype UUID.
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
	public function publishZaaktype(string $uuid): JSONResponse {
		return $this->handlePublish(resource: 'zaaktypen', uuid: $uuid);
	}//end publishZaaktype()

	/**
	 * Publish a besluittype (set isDraft to false).
	 *
	 * @param string $uuid The besluittype UUID.
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
	public function publishBesluittype(string $uuid): JSONResponse {
		return $this->handlePublish(resource: 'besluittypen', uuid: $uuid);
	}//end publishBesluittype()

	/**
	 * Publish an informatieobjecttype (set isDraft to false).
	 *
	 * @param string $uuid The informatieobjecttype UUID.
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
	public function publishInformatieobjecttype(string $uuid): JSONResponse {
		return $this->handlePublish(resource: 'informatieobjecttypen', uuid: $uuid);
	}//end publishInformatieobjecttype()

	/**
	 * Filter URL arrays in a ZTC response to only include valid/existing references.
	 *
	 * Enrich response data with cross-reference URLs.
	 *
	 * For besluittypen: expand stored UUID arrays (documentTypes, caseTypes) to
	 * full ZGW URLs so that the response includes informatieobjecttypen/zaaktypen.
	 * For zaaktypen: query ZIOT records and besluittype records to populate
	 * informatieobjecttypen and besluittypen arrays.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param array $data The outbound-mapped response data.
	 *
	 * @return array The enriched response data with cross-reference URLs.
	 */
	private function enrichCrossReferences(string $resource, array $data): array {
		$baseUrl = $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . '/index.php/apps/dossiq/api/zgw/catalogi/v1';

		return (new ZtcCrossReferenceEnricher(zgwService: $this->zgwService))->enrich(resource: $resource, data: $data, baseUrl: $baseUrl);
	}//end enrichCrossReferences()



	/**
	 * Filter a list of ZTC results by datumGeldigheid (date validity).
	 *
	 * Returns only items where beginGeldigheid <= datumGeldigheid and
	 * (eindeGeldigheid >= datumGeldigheid or eindeGeldigheid is absent).
	 *
	 * @param array $results The array of outbound-mapped result items.
	 * @param string $dateValidity The validity date in Y-m-d format.
	 *
	 * @return array The filtered results (re-indexed).
	 */
	private function filterByDateValidity(array $results, string $dateValidity): array {
		$filtered = [];
		foreach ($results as $item) {
			$start = $item['startValidity'] ?? null;
			$end = $item['endValidity'] ?? null;

			// BeginGeldigheid must be present and <= datumGeldigheid.
			if ($start !== null && $start !== '' && $start > $dateValidity) {
				continue;
			}

			// EindeGeldigheid, if present, must be >= datumGeldigheid.
			if ($end !== null && $end !== '' && $end < $dateValidity) {
				continue;
			}

			$filtered[] = $item;
		}

		return $filtered;
	}//end filterByDatumGeldigheid()

	/**
	 * For zaaktypen and besluittypen, removes URLs from array fields that point to
	 * objects which are not published or not currently valid (date-wise).
	 *
	 * @param string $resource The ZGW resource name.
	 * @param array $data The outbound-mapped response data.
	 *
	 * @return array The filtered response data.
	 */
	private function filterValidUrls(string $resource, array $data): array {
		return (new ZtcUrlValidityFilter(zgwService: $this->zgwService))->filter(
			fieldConfigs: self::URL_FILTER_FIELDS[$resource] ?? [],
			data: $data,
			today: date('Y-m-d')
		);
	}//end filterValidUrls()


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
	 * Resolve informatieobjecttype by omschrijving when not a UUID/URL (ztc-010m).
	 *
	 * The ZGW standard allows referencing an IOT by omschrijving in ZIOT creation.
	 * This method looks up the IOT by omschrijving and replaces it with its UUID.
	 *
	 * @param array $body The request body (modified in-place via cached body)
	 *
	 * @return void
	 */
	private function resolveIotByOmschrijving(array $body): void {
		$iotValue = $body['informatieobjecttype'] ?? '';
		if ($iotValue === '') {
			return;
		}

		// Already a UUID or URL — no resolution needed.
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $iotValue) === 1) {
			return;
		}

		if (filter_var($iotValue, FILTER_VALIDATE_URL) !== false) {
			return;
		}

		// Try to look up by omschrijving (internal field: name).
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$iotMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'informatieobjecttypen');
		if ($iotMapping === null) {
			return;
		}

		try {
			$iotUuid = $this->findIotUuidByOmschrijving(objectService: $objectService, iotMapping: $iotMapping, omschrijving: $iotValue);
			if ($iotUuid !== '') {
				$this->zgwService->updateCachedBodyField('informatieobjecttype', $iotUuid);
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->debug(
				'ztc-010m: Failed to resolve IOT by omschrijving: ' . $e->getMessage()
			);
		}//end try
	}//end resolveIotByOmschrijving()

	/**
	 * The uuid of the informatieobjecttype with this omschrijving, by name and else by full text.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array $iotMapping The informatieobjecttypen mapping.
	 * @param string $omschrijving The omschrijving.
	 *
	 * @return string The uuid, or '' when nothing matches.
	 */
	private function findIotUuidByOmschrijving(object $objectService, array $iotMapping, string $omschrijving): string {
		$result = ['total' => 0];
		foreach ([['name' => $omschrijving, '_limit' => 1], ['_search' => $omschrijving, '_limit' => 1]] as $params) {
			$query = $objectService->buildSearchQuery(
				requestParams: $params,
				register: $iotMapping['sourceRegister'],
				schema: $iotMapping['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(
				query: $query,
				_rbac: false,
				_multitenancy: false
			);
			if (($result['total'] ?? 0) !== 0) {
				break;
			}
		}

		if (($result['total'] ?? 0) <= 0) {
			return '';
		}

		$iotData = $this->objectToArray(row: $result['results'][0]);

		return $iotData['id'] ?? ($iotData['@self']['id'] ?? '');
	}//end findIotUuidByOmschrijving()
}//end class
