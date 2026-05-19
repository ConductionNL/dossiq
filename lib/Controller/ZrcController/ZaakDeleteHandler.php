<?php

/**
 * Zaak Delete Handler
 *
 * Handles cascade zaak deletion and OIO sync helpers extracted from ZrcController.
 *
 * @category Controller
 * @package  OCA\Procest\Controller\ZrcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller\ZrcController;

use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Handles cascade deletion of a zaak and OIO cross-register sync for ZRC.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class ZaakDeleteHandler
{
    /**
     * The ZGW API group identifier.
     *
     * @var string
     */
    private const ZGW_API = 'zaken';

    /**
     * Constructor.
     *
     * @param ZgwService $zgwService The shared ZGW service
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function __construct(
        private readonly ZgwService $zgwService,
    ) {
    }//end __construct()

    /**
     * Cascade-delete a zaak and all its sub-resources.
     *
     * Syncs OIO deletions in DRC for any linked ZaakInformatieObjecten before
     * removing the zaak itself from OpenRegister (zrc-005b, zrc-023).
     *
     * @param string   $uuid    The zaak UUID to delete
     * @param IRequest $request The incoming request
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function destroyZaak(string $uuid, IRequest $request): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaken');
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, 'zaken');
        }

        try {
            // Verify the zaak exists.
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

        // Zrc-005b: Before deleting the zaak, sync-delete OIOs in DRC
        // for any linked ZaakInformatieObjecten. This cross-component
        // side-effect cannot be handled by OpenRegister's cascade delete.
        $zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
        if ($zioConfig !== null) {
            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['case' => $uuid, '_limit' => 100],
                    register: $zioConfig['sourceRegister'],
                    schema: $zioConfig['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                foreach (($result['results'] ?? []) as $obj) {
                    if (is_array($obj) === true) {
                        $data = $obj;
                    } else {
                        $data = $obj->jsonSerialize();
                    }

                    $subUuid = $data['id'] ?? ($data['@self']['id'] ?? '');
                    if ($subUuid === '') {
                        continue;
                    }

                    $zioData = $this->getZioDataForOioSync(uuid: $subUuid, request: $request);
                    if ($zioData !== null) {
                        $this->syncDeleteObjectInformatieObject(
                            zaakUrl: $zioData['zaakUrl'],
                            ioUrl: $zioData['ioUrl']
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->zgwService->getLogger()->warning(
                    'zrc-023: Failed to sync-delete OIOs for zaak '.$uuid.': '.$e->getMessage()
                );
            }//end try
        }//end if

        // Cascade delete of sub-resources (rol, status, resultaat, etc.)
        // is handled by OpenRegister via onDelete: CASCADE in schema definitions.
        try {
            $objectService->deleteObject(uuid: $uuid);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['detail' => 'Failed to delete zaak: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $baseUrl = $this->zgwService->buildBaseUrl($request, self::ZGW_API, 'zaken');
        $this->zgwService->publishNotification(
            self::ZGW_API,
            'zaken',
            $baseUrl.'/'.$uuid,
            'destroy'
        );

        $this->zgwService->getLogger()->info(
            'zrc-023: Cascade deleted zaak '.$uuid.' with all sub-resources'
        );

        return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
    }//end destroyZaak()

    /**
     * Get ZaakInformatieObject data needed for OIO sync before deletion.
     *
     * @param string   $uuid    The ZaakInformatieObject UUID
     * @param IRequest $request The incoming request
     *
     * @return array|null The zaakUrl and ioUrl, or null if not found
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function getZioDataForOioSync(string $uuid, IRequest $request): ?array
    {
        try {
            $zioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaakinformatieobjecten');
            if ($zioConfig === null) {
                return null;
            }

            $zioObj = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            if (is_array($zioObj) === true) {
                $zioData = $zioObj;
            } else {
                $zioData = $zioObj->jsonSerialize();
            }

            // The ZIO stores 'case' as a UUID (format: uuid with $ref) and
            // 'document' as a full URL (format: uri). Build the zaak URL from
            // the case UUID, and use the document URL directly.
            $zaakUuid = $zioData['case'] ?? ($zioData['zaak'] ?? '');
            $ioUrl    = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

            if ($zaakUuid === '' || $ioUrl === '') {
                return null;
            }

            // Build zaak URL from the UUID (case field stores UUID).
            $zaakBaseUrl = $this->zgwService->buildBaseUrl($request, 'zaken', 'zaken');

            return [
                'zaakUrl' => $zaakBaseUrl.'/'.$zaakUuid,
                'ioUrl'   => $ioUrl,
            ];
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-005b: Could not get ZIO data for OIO sync: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end getZioDataForOioSync()

    /**
     * Delete the ObjectInformatieObject in DRC when a ZaakInformatieObject is deleted (zrc-005b).
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function syncDeleteObjectInformatieObject(string $zaakUrl, string $ioUrl): void
    {
        try {
            $oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
            if ($oioConfig === null) {
                return;
            }

            // The OIO schema (documentLink) stores 'object' and 'document' as
            // full URLs (format: uri). Search by the full URL values directly.
            if ($zaakUrl === '' || $ioUrl === '') {
                return;
            }

            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['object' => $zaakUrl, 'document' => $ioUrl],
                register: $oioConfig['sourceRegister'],
                schema: $oioConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $oioObj) {
                if (is_array($oioObj) === true) {
                    $oioData = $oioObj;
                } else {
                    $oioData = $oioObj->jsonSerialize();
                }

                $oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
                if ($oioUuid !== '') {
                    $this->zgwService->getObjectService()->deleteObject(uuid: $oioUuid);
                    $this->zgwService->getLogger()->info(
                        'zrc-005b: Deleted ObjectInformatieObject '.$oioUuid
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-005b: Failed to delete ObjectInformatieObject: '.$e->getMessage()
            );
        }//end try
    }//end syncDeleteObjectInformatieObject()
}//end class
