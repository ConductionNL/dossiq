<?php

/**
 * BesluitDestroy Handler
 *
 * Extracted handler for BRC besluit cascade-delete operations including
 * OIO cleanup in the DRC register (brc-009).
 *
 * @category Controller
 * @package  OCA\Procest\Controller\BrcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Procest\Controller\BrcController;

use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Handler for besluit delete with cascade OIO cleanup (brc-009).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */
class BesluitDestroyHandler
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
     * @param ZgwService $zgwService The shared ZGW service.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function __construct(
        private readonly ZgwService $zgwService,
    ) {
    }//end __construct()

    /**
     * Delete a besluit with cascade to BIOs and OIOs (brc-009).
     *
     * @param string   $uuid    The besluit UUID to delete.
     * @param IRequest $request The incoming request.
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function destroyBesluit(string $uuid, IRequest $request): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $resource      = 'besluiten';
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            // Validate the besluit exists (will throw if not found).
            $existingObj = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existingObj) === true) {
                $existingData = $existingObj;
            } else {
                $existingData = $existingObj->jsonSerialize();
            }

            // Run destroy business rules.
            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: 'destroy',
                body: [],
                existingObject: $existingData
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->zgwService->buildValidationError($ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            // Build the besluit URL for OIO cleanup.
            $besluitUrl = $this->zgwService->buildBaseUrl(
                $request,
                self::ZGW_API,
                'besluiten'
            ).'/'.$uuid;

            // Cascade delete of BesluitInformatieObjecten is handled by
            // OpenRegister via onDelete: CASCADE on decisionDocument.decision.
            // Brc-009: Sync-delete OIOs in DRC (cross-component side-effect).
            $this->deleteOiosForBesluit(besluitUrl: $besluitUrl);

            // Delete the besluit itself.
            $objectService->deleteObject(uuid: $uuid);

            $baseUrl = $this->zgwService->buildBaseUrl($request, self::ZGW_API, $resource);
            $this->zgwService->publishNotification(
                self::ZGW_API,
                $resource,
                $baseUrl.'/'.$uuid,
                'destroy'
            );

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'BRC delete besluit error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end destroyBesluit()

    /**
     * Delete ObjectInformatieObjecten from DRC for a given besluit (brc-005b/009).
     *
     * @param string $besluitUrl The besluit URL to match OIOs against.
     *
     * @return void
     */
    private function deleteOiosForBesluit(string $besluitUrl): void
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
        if ($oioMappingConfig === null) {
            return;
        }

        try {
            $query  = $objectService->buildSearchQuery(
                requestParams: ['object' => $besluitUrl],
                register: $oioMappingConfig['sourceRegister'],
                schema: $oioMappingConfig['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $oio) {
                if (is_array($oio) === true) {
                    $oioData = $oio;
                } else {
                    $oioData = $oio->jsonSerialize();
                }

                $oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
                if ($oioUuid !== '') {
                    $objectService->deleteObject(
                        uuid: $oioUuid,
                        _rbac: false,
                        _multitenancy: false
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'BRC: Failed to delete OIOs for besluit: '.$e->getMessage()
            );
        }//end try
    }//end deleteOiosForBesluit()
}//end class
