<?php

/**
 * Procest BRC (Besluiten) Controller
 *
 * Controller for serving ZGW Besluiten API endpoints (besluiten,
 * besluitinformatieobjecten). Implements BRC-specific business rules
 * including cross-register OIO sync, cascade delete, and immutability.
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

use OCA\Procest\Controller\BrcController\BesluitDestroyHandler;
use OCA\Procest\Controller\BrcController\BesluitInformatieObjectHandler;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * BRC (Besluiten) API Controller
 *
 * Handles ZGW Besluiten register resources: besluiten and
 * besluitinformatieobjecten. Implements BRC-specific business rules:
 *
 * - brc-004: PUT/PATCH on besluitinformatieobjecten returns 405
 * - brc-005: Cross-register OIO sync on BIO create/delete
 * - brc-006: Zaak-besluit relation (via ZRC zaakbesluiten endpoint)
 * - brc-009: Cascade delete of BIOs and OIOs when deleting a besluit
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class BrcController extends Controller
{
    /**
     * The ZGW API identifier for the Besluiten register.
     *
     * @var string
     */
    private const ZGW_API = 'besluiten';

    /**
     * Constructor.
     *
     * @param string                         $appName                        The app name.
     * @param IRequest                       $request                        The incoming request.
     * @param ZgwService                     $zgwService                     The shared ZGW service.
     * @param SettingsService                $settingsService                The settings service.
     * @param BesluitInformatieObjectHandler $besluitInformatieObjectHandler The BIO handler.
     * @param BesluitDestroyHandler          $besluitDestroyHandler          The besluit destroy handler.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwService $zgwService,
        private readonly SettingsService $settingsService,
        private readonly BesluitInformatieObjectHandler $besluitInformatieObjectHandler,
        private readonly BesluitDestroyHandler $besluitDestroyHandler,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List resources of the given type.
     *
     * @param string $resource The ZGW resource name (e.g. besluiten).
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

        // BesluitInformatieObjecten returns a plain array per ZGW spec.
        if ($resource === 'besluitinformatieobjecten') {
            return $this->besluitInformatieObjectHandler->indexBesluitInformatieObjecten(request: $this->request);
        }

        return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
    }//end index()

    /**
     * Create a new resource of the given type.
     *
     * For besluitinformatieobjecten, also creates an ObjectInformatieObject
     * in the DRC register (brc-005a).
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

        // For besluitinformatieobjecten: use custom create with OIO sync.
        if ($resource === 'besluitinformatieobjecten') {
            return $this->besluitInformatieObjectHandler->createBesluitInformatieObject(request: $this->request);
        }

        // Brc-006: For besluiten with a zaak, sync zaakbesluit to ZRC after creation.
        if ($resource === 'besluiten') {
            return $this->createBesluitWithZaakSync();
        }

        return $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
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

        return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
    }//end show()

    /**
     * Full update (PUT) a resource by UUID.
     *
     * For besluitinformatieobjecten, returns 405 Method Not Allowed (brc-004a).
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

        // Brc-004a: BesluitInformatieObject is immutable — PUT returns 405.
        if ($resource === 'besluitinformatieobjecten') {
            return new JSONResponse(
                data: ['detail' => 'Method not allowed'],
                statusCode: Http::STATUS_METHOD_NOT_ALLOWED
            );
        }

        return $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            false
        );
    }//end update()

    /**
     * Partial update (PATCH) a resource by UUID.
     *
     * For besluitinformatieobjecten, returns 405 Method Not Allowed (brc-004b).
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

        // Brc-004b: BesluitInformatieObject is immutable — PATCH returns 405.
        if ($resource === 'besluitinformatieobjecten') {
            return new JSONResponse(
                data: ['detail' => 'Method not allowed'],
                statusCode: Http::STATUS_METHOD_NOT_ALLOWED
            );
        }

        return $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            true
        );
    }//end patch()

    /**
     * Delete a resource by UUID.
     *
     * For besluiten: cascade deletes related BesluitInformatieObjecten
     * and their OIOs in DRC (brc-009).
     * For besluitinformatieobjecten: also deletes the OIO in DRC (brc-005b).
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

        // Brc-009: Cascade delete for besluiten.
        if ($resource === 'besluiten') {
            return $this->besluitDestroyHandler->destroyBesluit(uuid: $uuid, request: $this->request);
        }

        // Brc-005b: Delete OIO when deleting BIO.
        if ($resource === 'besluitinformatieobjecten') {
            return $this->besluitInformatieObjectHandler->destroyBesluitInformatieObject(uuid: $uuid, request: $this->request);
        }

        return $this->zgwService->handleDestroy(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid
        );
    }//end destroy()

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

        // Brc-009d: Verify the parent resource exists before returning audit trail.
        $objectService = $this->zgwService->getObjectService();
        if ($objectService !== null) {
            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
            if ($mappingConfig !== null) {
                try {
                    $objectService->find(
                        $uuid,
                        register: $mappingConfig['sourceRegister'],
                        schema: $mappingConfig['sourceSchema']
                    );
                } catch (\Throwable $e) {
                    return new JSONResponse(
                        data: ['detail' => 'Not found'],
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
     * Create a besluit with zaak-besluit sync to ZRC (brc-006).
     *
     * After creating the besluit, if it references a zaak, creates a
     * zaakbesluit relation in the ZRC register.
     *
     * @return JSONResponse
     */
    private function createBesluitWithZaakSync(): JSONResponse
    {
        $response = $this->zgwService->handleCreate($this->request, self::ZGW_API, 'besluiten');

        // Brc-006: If created successfully and has a zaak, sync to ZRC.
        if ($response->getStatus() === Http::STATUS_CREATED) {
            $data    = $response->getData();
            $zaakUrl = '';
            if (is_array($data) === true) {
                $zaakUrl = $data['zaak'] ?? '';

                if ($zaakUrl !== '') {
                    $besluitUrl = $data['url'] ?? '';
                    if ($besluitUrl !== '') {
                        $this->syncZaakBesluitToZrc(zaakUrl: $zaakUrl, besluitUrl: $besluitUrl);
                    }
                }
            }
        }

        return $response;
    }//end createBesluitWithZaakSync()

    /**
     * Sync a zaak-besluit relation to ZRC (brc-006).
     *
     * Creates a "zaakbesluit" record linking the zaak to the besluit
     * in the ZRC register.
     *
     * @param string $zaakUrl    The zaak URL
     * @param string $besluitUrl The besluit URL
     *
     * @return void
     */
    private function syncZaakBesluitToZrc(string $zaakUrl, string $besluitUrl): void
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return;
        }

        // Look for a zaakbesluit mapping/schema.
        $zbSchema = $this->settingsService->getConfigValue(key: 'case_decision_schema');
        if ($zbSchema === '') {
            $this->zgwService->getLogger()->debug(
                'brc-006: case_decision_schema not configured, skipping zaakbesluit sync'
            );
            return;
        }

        $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
        $zaakUuid    = '';
        if (preg_match($uuidPattern, $zaakUrl, $match) === 1) {
            $zaakUuid = $match[1];
        }

        if ($zaakUuid === '') {
            return;
        }

        // Use the zaken mapping config for register.
        $zakenConfig = $this->zgwService->loadMappingConfig('zaken', 'zaken');
        $register    = $zakenConfig['sourceRegister'] ?? '';
        if ($register === '') {
            return;
        }

        try {
            $zbData = [
                'case'     => $zaakUuid,
                'decision' => $besluitUrl,
            ];

            $objectService->saveObject(
                register: $register,
                schema: $zbSchema,
                object: $zbData
            );

            $this->zgwService->getLogger()->info(
                'brc-006: Created zaakbesluit for zaak='.$zaakUuid.' besluit='.$besluitUrl
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'brc-006: Failed to create zaakbesluit: '.$e->getMessage()
            );
        }
    }//end syncZaakBesluitToZrc()
}//end class
