<?php

/**
 * Procest ZTC (Catalogi) Controller
 *
 * Controller for serving ZGW Catalogi API endpoints (catalogussen, zaaktypen,
 * statustypen, resultaattypen, roltypen, eigenschappen, informatieobjecttypen,
 * besluittypen, zaaktype-informatieobjecttypen). Delegates shared operations
 * to ZgwService and handles ZTC-specific publish logic.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Controller\ZtcController\CatalogiFilterHandler;
use OCA\Procest\Controller\ZtcController\CrossReferenceEnricher;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class ZtcController extends Controller
{
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
        'zaaktypen'    => [
            'informatieobjecttypen' => [
                'schemaKey' => 'document_type_schema',
                'nested'    => false,
            ],
            'besluittypen'          => [
                'schemaKey' => 'decision_type_schema',
                'nested'    => false,
            ],
            'deelzaaktypen'         => [
                'schemaKey' => 'case_type_schema',
                'nested'    => false,
            ],
            'gerelateerdeZaaktypen' => [
                'schemaKey' => 'case_type_schema',
                'nested'    => true,
            ],
        ],
        'besluittypen' => [
            'informatieobjecttypen' => [
                'schemaKey' => 'document_type_schema',
                'nested'    => false,
            ],
            'zaaktypen'             => [
                'schemaKey' => 'case_type_schema',
                'nested'    => false,
            ],
        ],
    ];

    /**
     * Constructor.
     *
     * @param string                 $appName                The app name.
     * @param IRequest               $request                The incoming request.
     * @param ZgwService             $zgwService             The shared ZGW service.
     * @param CrossReferenceEnricher $crossReferenceEnricher The cross-reference enricher handler.
     * @param CatalogiFilterHandler  $catalogiFilterHandler  The catalogi filter handler.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwService $zgwService,
        private readonly CrossReferenceEnricher $crossReferenceEnricher,
        private readonly CatalogiFilterHandler $catalogiFilterHandler,
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
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function index(string $resource): JSONResponse
    {
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

        // ZTC datumGeldigheid: post-filter results by date validity.
        $datumGeldigheid = $this->request->getParam('datumGeldigheid');
        if ($datumGeldigheid !== null && $datumGeldigheid !== '') {
            $data['results'] = $this->filterByDatumGeldigheid(
                results: $data['results'],
                datumGeldigheid: $datumGeldigheid
            );
            $data['count']   = count($data['results']);
        }

        // Enrich cross-references and filter invalid URLs from paginated results.
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true) {
            foreach ($data['results'] as $idx => $item) {
                $item = $this->enrichCrossReferences(resource: $resource, data: $item);
                $data['results'][$idx] = $this->filterValidUrls(resource: $resource, data: $item);
            }
        }

        return new JSONResponse(data: $data, statusCode: Http::STATUS_OK);
    }//end index()

    /**
     * Create a new resource of the given type.
     *
     * @param string $resource The ZGW resource name.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function create(string $resource): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // Ztc-010: Resolve parent zaaktype draft status for sub-resource creation.
        $body = $this->zgwService->getRequestBody($this->request);
        $parentZaaktypeDraft = $this->zgwService->resolveParentZaaktypeDraftFromBody($resource, $body);

        // Ztc-010m: For ZIOT, resolve informatieobjecttype by omschrijving if not a UUID/URL.
        if ($resource === 'zaaktype-informatieobjecttypen') {
            $this->resolveIotByOmschrijving(body: $body);
        }

        $response = $this->zgwService->handleCreate(
            $this->request,
            self::ZGW_API,
            $resource,
            parentZaaktypeDraft: $parentZaaktypeDraft
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
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function show(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        $response = $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);

        // Enrich cross-references and filter invalid URLs.
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true
            && $response->getStatus() === Http::STATUS_OK
        ) {
            $data     = $response->getData();
            $data     = $this->enrichCrossReferences(resource: $resource, data: $data);
            $filtered = $this->filterValidUrls(resource: $resource, data: $data);

            return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
        }

        return $response;
    }//end show()

    /**
     * Resolve the parent zaaktype draft status for a sub-resource.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return bool|null The parent zaaktype draft status, or null if not applicable.
     */
    private function resolveParentDraft(string $resource, string $uuid): ?bool
    {
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
            if (is_array($existingObj) === true) {
                $existingData = $existingObj;
            } else {
                $existingData = $existingObj->jsonSerialize();
            }

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
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function update(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
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
            $data     = $response->getData();
            $data     = $this->enrichCrossReferences(resource: $resource, data: $data);
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
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function patch(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
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
            $data     = $response->getData();
            $data     = $this->enrichCrossReferences(resource: $resource, data: $data);
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
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function destroy(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
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
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     */
    private function handlePublish(string $resource, string $uuid): JSONResponse
    {
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
            $existing     = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $existingData = $existing->jsonSerialize();
            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['isDraft'] = false;

            if (isset($existingData['identifier']) === true && is_int($existingData['identifier']) === true) {
                $existingData['identifier'] = (string) $existingData['identifier'];
            }

            // Re-encode fields that are stored as JSON strings but auto-decoded
            // by jsonSerialize. Only string-typed schema fields need re-encoding.
            $jsonStringFields = ['productsOrServices', 'referenceProcess', 'relatedCaseTypes'];
            foreach ($jsonStringFields as $field) {
                if (isset($existingData[$field]) === true && is_array($existingData[$field]) === true) {
                    $existingData[$field] = json_encode($existingData[$field]);
                }
            }

            $object = $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'ZTC publish error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(data: ['detail' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try
    }//end handlePublish()

    /**
     * Publish a zaaktype (set isDraft to false).
     *
     * @param string $uuid The zaaktype UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function publishZaaktype(string $uuid): JSONResponse
    {
        return $this->handlePublish(resource: 'zaaktypen', uuid: $uuid);
    }//end publishZaaktype()

    /**
     * Publish a besluittype (set isDraft to false).
     *
     * @param string $uuid The besluittype UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function publishBesluittype(string $uuid): JSONResponse
    {
        return $this->handlePublish(resource: 'besluittypen', uuid: $uuid);
    }//end publishBesluittype()

    /**
     * Publish an informatieobjecttype (set isDraft to false).
     *
     * @param string $uuid The informatieobjecttype UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function publishInformatieobjecttype(string $uuid): JSONResponse
    {
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
     * @param array  $data     The outbound-mapped response data.
     *
     * @return array The enriched response data with cross-reference URLs.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function enrichCrossReferences(string $resource, array $data): array
    {
        return $this->crossReferenceEnricher->enrichCrossReferences(resource: $resource, data: $data);
    }//end enrichCrossReferences()

    /**
     * Enrich besluittype with informatieobjecttypen and zaaktypen URLs.
     *
     * @param array  $data          The response data.
     * @param string $baseUrl       The base URL for building ZGW resource URLs.
     * @param object $objectService The OpenRegister object service.
     * @param string $uuid          The besluittype UUID.
     *
     * @return array The enriched response data.
     */
    private function enrichBesluittype(
        array $data,
        string $baseUrl,
        object $objectService,
        string $uuid
    ): array {
        return $this->crossReferenceEnricher->enrichBesluittype(
            data: $data,
            baseUrl: $baseUrl,
            objectService: $objectService,
            uuid: $uuid
        );
    }//end enrichBesluittype()

    /**
     * Enrich zaaktype with informatieobjecttypen and besluittypen URLs.
     *
     * @param array  $data          The response data.
     * @param string $baseUrl       The base URL for building ZGW resource URLs.
     * @param object $objectService The OpenRegister object service.
     * @param string $uuid          The zaaktype UUID.
     *
     * @return array The enriched response data.
     */
    private function enrichZaaktype(
        array $data,
        string $baseUrl,
        object $objectService,
        string $uuid
    ): array {
        return $this->crossReferenceEnricher->enrichZaaktype(
            data: $data,
            baseUrl: $baseUrl,
            objectService: $objectService,
            uuid: $uuid
        );
    }//end enrichZaaktype()

    /**
     * Filter a list of ZTC results by datumGeldigheid (date validity).
     *
     * @param array  $results         The array of outbound-mapped result items.
     * @param string $datumGeldigheid The validity date in Y-m-d format.
     *
     * @return array The filtered results (re-indexed).
     */
    private function filterByDatumGeldigheid(array $results, string $datumGeldigheid): array
    {
        return $this->catalogiFilterHandler->filterByDatumGeldigheid(
            results: $results,
            datumGeldigheid: $datumGeldigheid
        );
    }//end filterByDatumGeldigheid()

    /**
     * For zaaktypen and besluittypen, removes URLs from array fields that point to
     * objects which are not published or not currently valid (date-wise).
     *
     * @param string $resource The ZGW resource name.
     * @param array  $data     The outbound-mapped response data.
     *
     * @return array The filtered response data.
     */
    private function filterValidUrls(string $resource, array $data): array
    {
        return $this->catalogiFilterHandler->filterValidUrls(resource: $resource, data: $data);
    }//end filterValidUrls()

    /**
     * List audit trail entries for a resource.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function audittrailIndex(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
    }//end audittrailIndex()

    /**
     * Retrieve a single audit trail entry for a resource.
     *
     * @param string $resource  The ZGW resource name.
     * @param string $uuid      The resource UUID.
     * @param string $auditUuid The audit trail entry UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function audittrailShow(string $resource, string $uuid, string $auditUuid): JSONResponse
    {
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
     * @param array $body The request body (modified in-place via cached body).
     *
     * @return void
     */
    private function resolveIotByOmschrijving(array $body): void
    {
        $this->crossReferenceEnricher->resolveIotByOmschrijving(body: $body);
    }//end resolveIotByOmschrijving()
}//end class
