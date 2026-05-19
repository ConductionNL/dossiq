<?php

/**
 * Procest ZRC (Zaken Register) Controller
 *
 * Handles the ZGW Zaken register API endpoints: zaken, statussen, resultaten,
 * rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten.
 *
 * Delegates shared operations to ZgwService while implementing ZRC-specific
 * behaviour such as zaak-closed resolution, eindstatus side effects,
 * authorization-based filtering (zrc-006), and OIO cross-register sync (zrc-005).
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

use OCA\Procest\Controller\ZrcController\EindstatusHandler;
use OCA\Procest\Controller\ZrcController\ZaakAuthorizationHandler;
use OCA\Procest\Controller\ZrcController\ZaakDeleteHandler;
use OCA\Procest\Controller\ZrcController\ZaakValidationHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * ZRC (Zaken Register) Controller
 *
 * Serves ZGW-compliant Zaken API endpoints on top of English-language
 * OpenRegister data with bidirectional mapping.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ZrcController extends Controller
{
    /**
     * The ZGW API group for this controller.
     *
     * @var string
     */
    private const ZGW_API = 'zaken';

    /**
     * Ordered vertrouwelijkheidaanduiding levels for authorization filtering.
     *
     * @var array<string, int>
     */
    private const VERTROUWELIJKHEID_LEVELS = [
        'openbaar'          => 1,
        'beperkt_openbaar'  => 2,
        'intern'            => 3,
        'zaakvertrouwelijk' => 4,
        'vertrouwelijk'     => 5,
        'confidentieel'     => 6,
        'geheim'            => 7,
        'zeer_geheim'       => 8,
    ];

    /**
     * Constructor.
     *
     * @param string                   $appName               The application name
     * @param IRequest                 $request               The incoming request
     * @param ZgwService               $zgwService            The shared ZGW service
     * @param IL10N                    $l10n                  The localization service
     * @param ZaakAuthorizationHandler $zaakAuthHandler       Handler for authorization filtering
     * @param ZaakValidationHandler    $zaakValidationHandler Handler for zaak body validation
     * @param EindstatusHandler        $eindstatusHandler     Handler for eindstatus side effects
     * @param ZaakDeleteHandler        $zaakDeleteHandler     Handler for cascade zaak deletion
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwService $zgwService,
        private readonly IL10N $l10n,
        private readonly ZaakAuthorizationHandler $zaakAuthHandler,
        private readonly ZaakValidationHandler $zaakValidationHandler,
        private readonly EindstatusHandler $eindstatusHandler,
        private readonly ZaakDeleteHandler $zaakDeleteHandler,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List resources.
     *
     * ZRC-specific: for zaken, applies authorization-based filtering (zrc-006a).
     *
     * @param string $resource The ZGW resource name
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
        $response = $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);

        // Zrc-006a: Filter zaken results based on consumer's vertrouwelijkheidaanduiding.
        if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->filterZakenByAuthorisation(response: $response);
        }

        return $response;
    }//end index()

    /**
     * Create a resource.
     *
     * ZRC-specific: resolves zaak-closed from the request body before validation,
     * triggers eindstatus side effects when creating statussen, checks scopes
     * for zaken creation (zrc-006c), and syncs OIO for zaakinformatieobjecten (zrc-005a).
     *
     * @param string $resource The ZGW resource name
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

        // Zrc-006c: Check zaken.aanmaken scope for zaak creation.
        if ($resource === 'zaken') {
            $hasScope = $this->zgwService->consumerHasScope(
                $this->request,
                'zrc',
                'zaken.aanmaken'
            );
            if ($hasScope === false) {
                return $this->permissionDeniedResponse();
            }
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $body         = $this->zgwService->getRequestBody($this->request);
            $originalBody = $body;

            // ZRC-specific: resolve zaak closed from body before validation.
            $zaakClosed    = $this->zgwService->resolveZaakClosedFromBody($resource, $body);
            $hasGeforceerd = true;
            if ($zaakClosed === true) {
                $hasGeforceerd = $this->zgwService->consumerHasScope(
                    $this->request,
                    'zrc',
                    'zaken.geforceerd-bijwerken'
                );
            }

            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: 'create',
                body: $body,
                objectService: $this->zgwService->getObjectService(),
                mappingConfig: $mappingConfig,
                zaakClosed: $zaakClosed,
                hasGeforceerd: $hasGeforceerd
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->zgwService->buildValidationError($ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $body = $ruleResult['enrichedBody'];

            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $body,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            // @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
            if (is_array($englishData) === false) {
                return new JSONResponse(
                    data: ['detail' => 'Invalid mapping result'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Zrc-008c: Before saving a status, check if it would reopen a closed zaak
            // and require the zaken.heropenen scope.
            if ($resource === 'statussen') {
                $reopenError = $this->checkReopenScope(body: $originalBody);
                if ($reopenError !== null) {
                    return $reopenError;
                }

                // Zrc-007q: Before adding an eindstatus, verify all linked IOs
                // have indicatieGebruiksrecht set (not null).
                $gebruiksrechtError = $this->checkIndicatieGebruiksrechtBeforeClose(body: $originalBody);
                if ($gebruiksrechtError !== null) {
                    return $gebruiksrechtError;
                }
            }

            $object = $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

            // ZRC-specific: handle eindstatus / heropenen effect for statussen.
            if ($resource === 'statussen') {
                $this->handleEindstatusEffect(body: $originalBody, objectData: $objectData);
            }

            // Zrc-021: When a resultaat is created, derive archiefactiedatum
            // and archiefnominatie on the parent zaak from the resultaattype.
            if ($resource === 'resultaten') {
                $this->handleResultaatCreated(body: $originalBody, objectData: $objectData);
            }

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            // Zrc-004a/zrc-005a: ZaakInformatieObject enrichment and OIO sync.
            if ($resource === 'zaakinformatieobjecten') {
                // Zrc-004a: Ensure aardRelatieWeergave and registratiedatum in response.
                $mapped = $this->enrichZioResponse(mapped: $mapped, body: $body);

                // Zrc-005a: Create ObjectInformatieObject in DRC.
                $zaakUrl = $originalBody['zaak'] ?? ($body['zaak'] ?? '');
                $ioUrl   = $originalBody['informatieobject'] ?? ($body['informatieobject'] ?? '');
                $this->syncCreateObjectInformatieObject(zaakUrl: $zaakUrl, ioUrl: $ioUrl);
            }

            $this->zgwService->publishNotification(
                self::ZGW_API,
                $resource,
                $baseUrl.'/'.$objectUuid,
                'create'
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'ZRC create error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Show a specific resource.
     *
     * ZRC-specific: for zaken, checks zaken.lezen scope and vertrouwelijkheidaanduiding (zrc-006b).
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
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
        // Zrc-006b: Check zaken.lezen scope and vertrouwelijkheidaanduiding.
        if ($resource === 'zaken') {
            $authError = $this->zgwService->validateJwtAuth($this->request);
            if ($authError !== null) {
                return $authError;
            }

            $scopeError = $this->checkZaakReadAccess(uuid: $uuid);
            if ($scopeError !== null) {
                return $scopeError;
            }
        }

        return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
    }//end show()

    /**
     * Full update a resource.
     *
     * ZRC-specific: resolves zaak-closed from existing data before delegating.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
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
        // Resolve UUID from URL path — body "uuid" can override controller args.
        $uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // Zrc-010/zrc-015: Pre-validate body fields that don't require
        // the existing object, so validation errors are returned even
        // when the OpenRegister find() call fails transiently.
        if ($resource === 'zaken') {
            $preValidation = $this->preValidateZaakBody(isPatch: false);
            if ($preValidation !== null) {
                return $preValidation;
            }
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting(resource: $resource, uuid: $uuid);

        $response = $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            false,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // Zrc-004b: Enrich ZIO response with immutable aardRelatieWeergave.
        if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->enrichZioJsonResponse(response: $response);
        }

        return $response;
    }//end update()

    /**
     * Partial update a resource.
     *
     * ZRC-specific: resolves zaak-closed from existing data before delegating.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
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
        // Resolve UUID from URL path — body "uuid" can override controller args.
        $uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // Zrc-010/zrc-015: Pre-validate body fields that don't require
        // the existing object, so validation errors are returned even
        // when the OpenRegister find() call fails transiently.
        if ($resource === 'zaken') {
            $preValidation = $this->preValidateZaakBody(isPatch: true);
            if ($preValidation !== null) {
                return $preValidation;
            }
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting(resource: $resource, uuid: $uuid);

        $response = $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            true,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // Zrc-004c: Enrich ZIO response with immutable aardRelatieWeergave.
        if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->enrichZioJsonResponse(response: $response);
        }

        return $response;
    }//end patch()

    /**
     * Delete a resource.
     *
     * ZRC-specific: resolves zaak-closed from existing data before delegating.
     * For zaakinformatieobjecten, syncs ObjectInformatieObject deletion in DRC (zrc-005b).
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
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

        // Zrc-023: Cascade delete for zaken.
        if ($resource === 'zaken') {
            return $this->destroyZaak(uuid: $uuid);
        }

        // Zrc-005b: Before deleting, capture ZIO data for OIO cleanup.
        $zioData = null;
        if ($resource === 'zaakinformatieobjecten') {
            $zioData = $this->getZioDataForOioSync(uuid: $uuid);
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting(resource: $resource, uuid: $uuid);

        $response = $this->zgwService->handleDestroy(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // Zrc-005b: If ZIO deletion succeeded, also delete the OIO in DRC.
        if ($resource === 'zaakinformatieobjecten'
            && $response->getStatus() === Http::STATUS_NO_CONTENT
            && $zioData !== null
        ) {
            $this->syncDeleteObjectInformatieObject(
                zaakUrl: $zioData['zaakUrl'],
                ioUrl: $zioData['ioUrl']
            );
        }

        return $response;
    }//end destroy()

    /**
     * List zaakeigenschappen for a zaak.
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
     */
    public function zaakeigenschappenIndex(string $zaakUuid): JSONResponse
    {
        return $this->index(resource: 'zaakeigenschappen');
    }//end zaakeigenschappenIndex()

    /**
     * Create a zaakeigenschap for a zaak.
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
     */
    public function zaakeigenschappenCreate(string $zaakUuid): JSONResponse
    {
        return $this->create(resource: 'zaakeigenschappen');
    }//end zaakeigenschappenCreate()

    /**
     * Show a specific zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
     */
    public function zaakeigenschappenShow(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->show(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenShow()

    /**
     * Update a zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
     */
    public function zaakeigenschappenUpdate(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->update(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenUpdate()

    /**
     * Partial update a zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
     */
    public function zaakeigenschappenPatch(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->patch(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenPatch()

    /**
     * Delete a zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
     */
    public function zaakeigenschappenDestroy(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->destroy(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenDestroy()

    /**
     * List zaakbesluiten for a zaak.
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakbesluitenIndex(string $zaakUuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig('besluiten', 'besluiten');
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => 'Besluit mapping not configured'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid],
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, 'besluiten', 'besluiten');
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = [];
            foreach (($result['results'] ?? []) as $object) {
                if (is_array($object) === true) {
                    $objectData = $object;
                } else {
                    $objectData = $object->jsonSerialize();
                }

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
                'ZRC zaakbesluiten error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end zaakbesluitenIndex()

    /**
     * Search zaken (POST /zaken/v1/zaken/_zoek).
     *
     * Delegates to index and returns HTTP 201 per the ZGW specification.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zoek(): JSONResponse
    {
        $indexResponse = $this->index(resource: 'zaken');
        // The zoek endpoint reuses the list handler but returns 201 Created.
        $responseData = [];
        if ($indexResponse instanceof JSONResponse) {
            $responseData = $indexResponse->getData() ?? [];
        }

        return new JSONResponse(data: $responseData, statusCode: Http::STATUS_CREATED);
    }//end zoek()

    /**
     * Get audit trail for a resource.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
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
        return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
    }//end audittrailIndex()

    /**
     * Get a specific audit trail entry.
     *
     * @param string $resource  The ZGW resource name
     * @param string $uuid      The resource UUID
     * @param string $auditUuid The audit trail entry UUID
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
        return $this->zgwService->handleAudittrailShow($this->request, self::ZGW_API, $resource, $uuid, $auditUuid);
    }//end audittrailShow()

    /**
     * Check zaak read access based on consumer scopes and vertrouwelijkheidaanduiding (zrc-006b).
     *
     * @param string $uuid The zaak UUID
     *
     * @return JSONResponse|null Permission denied response, or null if access is allowed
     */
    private function checkZaakReadAccess(string $uuid): ?JSONResponse
    {
        return $this->zaakAuthHandler->checkZaakReadAccess(uuid: $uuid, request: $this->request);
    }//end checkZaakReadAccess()

    /**
     * Filter zaken results based on consumer's vertrouwelijkheidaanduiding (zrc-006a).
     *
     * @param JSONResponse $response The original index response
     *
     * @return JSONResponse The filtered response
     */
    private function filterZakenByAuthorisation(JSONResponse $response): JSONResponse
    {
        return $this->zaakAuthHandler->filterZakenByAuthorisation(response: $response, request: $this->request);
    }//end filterZakenByAuthorisation()

    /**
     * Build a permission denied response (zrc-006/zrc-007).
     *
     * @return JSONResponse
     */
    private function permissionDeniedResponse(): JSONResponse
    {
        return $this->zaakAuthHandler->permissionDeniedResponse();
    }//end permissionDeniedResponse()

    /**
     * Pre-validate zaak body fields before calling handleUpdate (zrc-010/zrc-015).
     *
     * @param bool $isPatch Whether this is a PATCH operation
     *
     * @return JSONResponse|null A 400 response if validation fails, null if valid
     */
    private function preValidateZaakBody(bool $isPatch): ?JSONResponse
    {
        return $this->zaakValidationHandler->preValidateZaakBody(isPatch: $isPatch, request: $this->request);
    }//end preValidateZaakBody()

    /**
     * Pre-validate productenOfDiensten against zaaktype (zrc-015).
     *
     * @param array  $producten   The productenOfDiensten URLs
     * @param string $zaaktypeUrl The zaaktype URL
     *
     * @return JSONResponse|null A 400 response if invalid, null if valid
     */
    private function preValidateProductenOfDiensten(
        array $producten,
        string $zaaktypeUrl
    ): ?JSONResponse {
        return $this->zaakValidationHandler->preValidateProductenOfDiensten(producten: $producten, zaaktypeUrl: $zaaktypeUrl);
    }//end preValidateProductenOfDiensten()

    /**
     * Delete a zaak with cascade delete of all sub-resources (zrc-023).
     *
     * @param string $uuid The zaak UUID to delete
     *
     * @return JSONResponse
     */
    private function destroyZaak(string $uuid): JSONResponse
    {
        return $this->zaakDeleteHandler->destroyZaak(uuid: $uuid, request: $this->request);
    }//end destroyZaak()

    /**
     * Resolve zaak-closed state and geforceerd scope for an existing resource.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return array{0: ?bool, 1: bool} [zaakClosed, hasGeforceerd]
     */
    private function resolveZaakClosedForExisting(string $resource, string $uuid): array
    {
        $zaakClosed    = null;
        $hasGeforceerd = true;

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig !== null && $this->zgwService->getObjectService() !== null) {
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

                $zaakClosed    = $this->zgwService->resolveZaakClosed($resource, $existingData);
                $hasGeforceerd = true;
                if ($zaakClosed === true) {
                    $hasGeforceerd = $this->zgwService->consumerHasScope(
                        $this->request,
                        'zrc',
                        'zaken.geforceerd-bijwerken'
                    );
                }
            } catch (\Throwable $e) {
                // Proceed without zaak closed info.
                $this->zgwService->getLogger()->debug(
                    'Could not resolve zaakClosed for '.$resource.'/'.$uuid.': '.$e->getMessage()
                );
            }//end try
        }//end if

        return [$zaakClosed, $hasGeforceerd];
    }//end resolveZaakClosedForExisting()

    /**
     * Check if creating a status would reopen a closed zaak and require the
     * zaken.heropenen scope (zrc-008c).
     *
     * @param array $body The original request body
     *
     * @return JSONResponse|null A 403 response if scope is missing, null otherwise
     */
    private function checkReopenScope(array $body): ?JSONResponse
    {
        return $this->eindstatusHandler->checkReopenScope(body: $body, request: $this->request);
    }//end checkReopenScope()

    /**
     * Set indicatieGebruiksrecht on all linked IOs and then verify none remain
     * null before allowing an eindstatus (zrc-007b + zrc-007q).
     *
     * @param array $body The original request body
     *
     * @return JSONResponse|null A 400 response if any IO has null indicatieGebruiksrecht, null otherwise
     */
    private function checkIndicatieGebruiksrechtBeforeClose(array $body): ?JSONResponse
    {
        return $this->eindstatusHandler->checkIndicatieGebruiksrechtBeforeClose(body: $body);
    }//end checkIndicatieGebruiksrechtBeforeClose()

    /**
     * Handle eindstatus side effect when creating a status.
     *
     * @param array $body       The original request body
     * @param array $objectData The created object data
     *
     * @return void
     */
    private function handleEindstatusEffect(array $body, array $objectData): void
    {
        $this->eindstatusHandler->handleEindstatusEffect(body: $body, objectData: $objectData);
    }//end handleEindstatusEffect()

    /**
     * Handle resultaat creation side-effects (zrc-021).
     *
     * @param array $body       The original request body (Dutch names)
     * @param array $objectData The created resultaat object data
     *
     * @return void
     */
    private function handleResultaatCreated(array $body, array $objectData): void
    {
        $this->eindstatusHandler->handleResultaatCreated(body: $body, objectData: $objectData);
    }//end handleResultaatCreated()

    /**
     * Enrich a ZaakInformatieObject outbound-mapped array with aardRelatieWeergave and registratiedatum.
     *
     * @param array $mapped The outbound-mapped data
     * @param array $body   The enriched request body (from business rules)
     *
     * @return array The enriched mapped data
     */
    private function enrichZioResponse(array $mapped, array $body): array
    {
        // Zrc-004a: aardRelatieWeergave is always "Hoort bij, omgekeerd: kent".
        $mapped['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';

        // Zrc-004a: registratiedatum from the enriched body (set by business rules).
        if (isset($body['registratiedatum']) === true
            && isset($mapped['registratiedatum']) === false
        ) {
            $mapped['registratiedatum'] = $body['registratiedatum'];
        }

        return $mapped;
    }//end enrichZioResponse()

    /**
     * Enrich a ZaakInformatieObject JSONResponse with aardRelatieWeergave (zrc-004b/c).
     *
     * Used for update/patch responses where we intercept the JSONResponse from handleUpdate.
     *
     * @param JSONResponse $response The response to enrich
     *
     * @return JSONResponse The enriched response
     */
    private function enrichZioJsonResponse(JSONResponse $response): JSONResponse
    {
        $data = $response->getData();
        if (is_array($data) === true) {
            $data['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';
            $response->setData($data);
        }

        return $response;
    }//end enrichZioJsonResponse()

    /**
     * Create an ObjectInformatieObject in the DRC when a ZaakInformatieObject is created (zrc-005a).
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return void
     */
    private function syncCreateObjectInformatieObject(string $zaakUrl, string $ioUrl): void
    {
        if ($zaakUrl === '' || $ioUrl === '') {
            return;
        }

        try {
            $oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
            if ($oioConfig === null) {
                $this->zgwService->getLogger()->debug(
                    'zrc-005a: objectinformatieobject mapping not configured'
                );
                return;
            }

            $oioData = [
                'object'           => $zaakUrl,
                'objectType'       => 'zaak',
                'informatieobject' => $ioUrl,
            ];

            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $oioConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $oioData,
                mapping: $inboundMapping,
                mappingConfig: $oioConfig
            );

            // @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
            if (is_array($englishData) === false) {
                $englishData = $oioData;
            }

            $this->zgwService->getObjectService()->saveObject(
                register: $oioConfig['sourceRegister'],
                schema: $oioConfig['sourceSchema'],
                object: $englishData
            );

            $this->zgwService->getLogger()->info(
                'zrc-005a: Created ObjectInformatieObject for zaak/io sync'
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-005a: Failed to create ObjectInformatieObject: '.$e->getMessage()
            );
        }//end try
    }//end syncCreateObjectInformatieObject()

    /**
     * Get ZaakInformatieObject data needed for OIO sync before deletion.
     *
     * @param string $uuid The ZaakInformatieObject UUID
     *
     * @return array|null The zaakUrl and ioUrl, or null if not found
     */
    private function getZioDataForOioSync(string $uuid): ?array
    {
        return $this->zaakDeleteHandler->getZioDataForOioSync(uuid: $uuid, request: $this->request);
    }//end getZioDataForOioSync()

    /**
     * Delete the ObjectInformatieObject in DRC when a ZaakInformatieObject is deleted (zrc-005b).
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return void
     */
    private function syncDeleteObjectInformatieObject(string $zaakUrl, string $ioUrl): void
    {
        $this->zaakDeleteHandler->syncDeleteObjectInformatieObject(zaakUrl: $zaakUrl, ioUrl: $ioUrl);
    }//end syncDeleteObjectInformatieObject()
}//end class
