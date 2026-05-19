<?php

/**
 * BesluitInformatieObject Handler
 *
 * Extracted handler for BRC BesluitInformatieObject create/delete operations
 * including cross-register OIO sync (brc-005a, brc-005b).
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
 * Handler for BesluitInformatieObject create and related OIO sync operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */
class BesluitInformatieObjectHandler
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
     * List BesluitInformatieObjecten as a plain array (per ZGW spec).
     *
     * Unlike paginated resources, besluitinformatieobjecten returns a flat array.
     *
     * @param IRequest $request The incoming request.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function indexBesluitInformatieObjecten(IRequest $request): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $resource      = 'besluitinformatieobjecten';
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $params  = $request->getParams();
            $filters = $this->zgwService->translateQueryParams(
                params: $params,
                mappingConfig: $mappingConfig
            );

            $searchParams = array_merge($filters, ['_limit' => 100]);

            $query  = $objectService->buildSearchQuery(
                requestParams: $searchParams,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(query: $query);

            $objects         = $result['results'] ?? [];
            $baseUrl         = $this->zgwService->buildBaseUrl($request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = [];
            foreach ($objects as $object) {
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
                'BRC list BIO error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end indexBesluitInformatieObjecten()

    /**
     * Create a BesluitInformatieObject with cross-register OIO sync (brc-005a).
     *
     * After creating the BIO, also creates an ObjectInformatieObject in the
     * DRC register with objectType=besluit.
     *
     * @param IRequest $request The incoming request.
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function createBesluitInformatieObject(IRequest $request): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $resource      = 'besluitinformatieobjecten';
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $body = $this->zgwService->getRequestBody($request);

            // Run business rules (brc-003a, brc-008a).
            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: 'create',
                body: $body,
                objectService: $objectService,
                mappingConfig: $mappingConfig
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->zgwService->buildValidationError($ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $enrichedBody = $ruleResult['enrichedBody'];

            // Create the BIO via standard mapping flow.
            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $enrichedBody,
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

            $object = $objectService->saveObject(
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

            $baseUrl         = $this->zgwService->buildBaseUrl($request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            // Brc-005a: Create OIO in DRC.
            $besluitUrl = $enrichedBody['besluit'] ?? '';
            $ioUrl      = $enrichedBody['informatieobject'] ?? '';
            if ($besluitUrl !== '' && $ioUrl !== '') {
                $this->createOioInDrc(besluitUrl: $besluitUrl, ioUrl: $ioUrl);
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
                'BRC create BIO error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end createBesluitInformatieObject()

    /**
     * Delete a BesluitInformatieObject and its OIO in DRC (brc-005b).
     *
     * @param string   $uuid    The BIO UUID to delete.
     * @param IRequest $request The incoming request.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function destroyBesluitInformatieObject(string $uuid, IRequest $request): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $resource      = 'besluitinformatieobjecten';
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            // Read the BIO to get besluit URL before deletion.
            $bioObj = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($bioObj) === true) {
                $bioData = $bioObj;
            } else {
                $bioData = $bioObj->jsonSerialize();
            }

            // Build the besluit URL from the stored decision UUID.
            $decisionUuid = $bioData['decision'] ?? '';
            $besluitUrl   = '';
            if ($decisionUuid !== '') {
                $besluitUrl = $this->zgwService->buildBaseUrl(
                    $request,
                    self::ZGW_API,
                    'besluiten'
                ).'/'.$decisionUuid;
            }

            $ioUrl = $bioData['document'] ?? '';

            // Delete the BIO.
            $objectService->deleteObject(uuid: $uuid);

            // Brc-005b: Delete matching OIO in DRC.
            if ($besluitUrl !== '' && $ioUrl !== '') {
                $this->deleteOioByBesluitAndIo(besluitUrl: $besluitUrl, ioUrl: $ioUrl);
            }

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
                'BRC delete BIO error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end destroyBesluitInformatieObject()

    /**
     * Create an ObjectInformatieObject in the DRC register (brc-005a).
     *
     * @param string $besluitUrl The besluit URL (full ZGW URL).
     * @param string $ioUrl      The informatieobject URL (full ZGW URL).
     *
     * @return void
     */
    private function createOioInDrc(string $besluitUrl, string $ioUrl): void
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
            $oioData = [
                'document'   => $ioUrl,
                'object'     => $besluitUrl,
                'objectType' => 'besluit',
            ];

            $objectService->saveObject(
                register: $oioMappingConfig['sourceRegister'],
                schema: $oioMappingConfig['sourceSchema'],
                object: $oioData
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'brc-005a: Failed to create OIO in DRC: '.$e->getMessage()
            );
        }
    }//end createOioInDrc()

    /**
     * Delete ObjectInformatieObjecten from DRC for a given besluit (brc-005b/009).
     *
     * @param string $besluitUrl The besluit URL to match OIOs against.
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function deleteOiosForBesluit(string $besluitUrl): void
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

    /**
     * Delete an OIO from DRC matching a specific besluit and informatieobject (brc-005b).
     *
     * @param string $besluitUrl The besluit URL.
     * @param string $ioUrl      The informatieobject URL.
     *
     * @return void
     */
    private function deleteOioByBesluitAndIo(string $besluitUrl, string $ioUrl): void
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
                requestParams: [
                    'object'   => $besluitUrl,
                    'document' => $ioUrl,
                ],
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
                'brc-005b: Failed to delete OIO: '.$e->getMessage()
            );
        }//end try
    }//end deleteOioByBesluitAndIo()
}//end class
