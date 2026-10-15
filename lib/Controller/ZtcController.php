<?php

/**
 * Dossiq ZTC (Catalogi) Controller
 *
 * Controller for serving ZGW Catalogi API endpoints (catalogussen, zaaktypen,
 * statustypen, resultaattypen, roltypen, eigenschappen, informatieobjecttypen,
 * besluittypen, zaaktype-informatieobjecttypen). Delegates shared operations
 * to ZgwService. Publishing lives in ZtcPublishController.
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

use OCA\Dossiq\Service\Zgw\ValidityDateFilter;
use OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher;
use OCA\Dossiq\Service\Zgw\ZtcTypeNameResolver;
use OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * ZTC (Catalogi) API Controller
 *
 * Handles ZGW Catalogi register resources. Publishing a zaaktype,
 * besluittype or informatieobjecttype is ZtcPublishController's.
 *
 * @psalm-suppress UnusedClass
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
			$data['results'] = (new ValidityDateFilter())->keep(results: $data['results'], date: $dateValidity);
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
			(new ZtcTypeNameResolver(zgwService: $this->zgwService))->resolveInformatieobjecttype(body: $body);
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

}//end class
