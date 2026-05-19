<?php

/**
 * CrossReferenceEnricher — Enriches ZTC resources with cross-reference URLs.
 *
 * Extracted from ZtcController: enriches besluittypen and zaaktypen responses
 * with linked informatieobjecttypen, zaaktypen, and besluittypen URLs.
 *
 * @category Controller
 * @package  OCA\Procest\Controller\ZtcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Controller\ZtcController;

use OCA\Procest\Service\ZgwService;
use OCP\IRequest;

/**
 * Enriches ZTC resource responses with cross-reference URLs.
 *
 * Handles besluittypen (expanding documentTypes/caseTypes UUIDs to URLs) and
 * zaaktypen (expanding subCaseTypes, relatedCaseTypes, ZIOT, and besluittypen links).
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-5
 */
class CrossReferenceEnricher
{

    /**
     * The ZGW API identifier for the Catalogi register.
     *
     * @var string
     */
    private const ZGW_API = 'catalogi';

    /**
     * Constructor.
     *
     * @param ZgwService $zgwService The shared ZGW service.
     * @param IRequest   $request    The incoming HTTP request.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function __construct(
        private readonly ZgwService $zgwService,
        private readonly IRequest $request,
    ) {
    }//end __construct()

    /**
     * Enrich cross-references for besluittypen and zaaktypen resources.
     *
     * For besluittypen: expands stored UUID arrays (documentTypes, caseTypes) to
     * full ZGW URLs so that the response includes informatieobjecttypen/zaaktypen.
     * For zaaktypen: queries ZIOT records and besluittype records to populate
     * informatieobjecttypen and besluittypen arrays.
     *
     * @param string $resource The ZGW resource name.
     * @param array  $data     The outbound-mapped response data.
     *
     * @return array The enriched response data with cross-reference URLs.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function enrichCrossReferences(string $resource, array $data): array
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $data;
        }

        $baseUrl = $this->request->getServerProtocol().'://'.$this->request->getServerHost().'/index.php/apps/procest/api/zgw/catalogi/v1';
        $uuid    = $data['uuid'] ?? '';

        if ($resource === 'besluittypen' && $uuid !== '') {
            $data = $this->enrichBesluittype(
                data: $data,
                baseUrl: $baseUrl,
                objectService: $objectService,
                uuid: $uuid
            );
        }

        if ($resource === 'zaaktypen' && $uuid !== '') {
            $data = $this->enrichZaaktype(data: $data, baseUrl: $baseUrl, objectService: $objectService, uuid: $uuid);

            // Ensure array fields default to [] instead of null.
            $arrayFields = [
                'deelzaaktypen',
                'gerelateerdeZaaktypen',
                'besluittypen',
                'informatieobjecttypen',
                'eigenschappen',
                'statustypen',
                'resultaattypen',
                'roltypen',
            ];
            foreach ($arrayFields as $field) {
                if (isset($data[$field]) === false) {
                    $data[$field] = [];
                }
            }
        }

        return $data;
    }//end enrichCrossReferences()

    /**
     * Enrich besluittype with informatieobjecttypen and zaaktypen URLs.
     *
     * Reads stored UUIDs from the documentTypes/caseTypes fields and
     * expands them to full ZGW URLs.
     *
     * @param array  $data          The response data.
     * @param string $baseUrl       The base URL for building ZGW resource URLs.
     * @param object $objectService The OpenRegister object service.
     * @param string $uuid          The besluittype UUID.
     *
     * @return array The enriched response data.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function enrichBesluittype(
        array $data,
        string $baseUrl,
        object $objectService,
        string $uuid
    ): array {
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'besluittypen');
        if ($mappingConfig === null) {
            return $data;
        }

        try {
            $object = $objectService->find(
                id: $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            // Expand documentTypes UUIDs to informatieobjecttypen URLs.
            $docTypes   = $objectData['documentTypes'] ?? '';
            $docTypeIds = [];
            if (is_string($docTypes) === true && $docTypes !== '') {
                $docTypeIds = json_decode($docTypes, true);
            } else if (is_array($docTypes) === true) {
                $docTypeIds = $docTypes;
            }

            if (empty($docTypeIds) === false) {
                $urls = [];
                foreach ($docTypeIds as $iotUuid) {
                    if (is_string($iotUuid) === true && $iotUuid !== '') {
                        $urls[] = $baseUrl.'/informatieobjecttypen/'.$iotUuid;
                    }
                }

                $data['informatieobjecttypen'] = $urls;
            }

            // Expand caseTypes to zaaktypen URLs.
            $caseTypes   = $objectData['caseTypes'] ?? '';
            $caseTypeIds = [];
            if (is_string($caseTypes) === true && $caseTypes !== '') {
                $caseTypeIds = json_decode($caseTypes, true);
            } else if (is_array($caseTypes) === true) {
                $caseTypeIds = $caseTypes;
            }

            if (empty($caseTypeIds) === false) {
                $urls = [];
                foreach ($caseTypeIds as $ztUuid) {
                    if (is_string($ztUuid) === true && $ztUuid !== '') {
                        $urls[] = $baseUrl.'/zaaktypen/'.$ztUuid;
                    }
                }

                $data['zaaktypen'] = $urls;
            }
        } catch (\Throwable $e) {
            // Proceed without enrichment.
        }//end try

        return $data;
    }//end enrichBesluittype()

    /**
     * Enrich zaaktype with informatieobjecttypen and besluittypen URLs.
     *
     * Queries ZIOT records to find linked informatieobjecttypen, and
     * queries besluittypen by caseType to find linked besluittypen.
     *
     * @param array  $data          The response data.
     * @param string $baseUrl       The base URL for building ZGW resource URLs.
     * @param object $objectService The OpenRegister object service.
     * @param string $uuid          The zaaktype UUID.
     *
     * @return array The enriched response data.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function enrichZaaktype(
        array $data,
        string $baseUrl,
        object $objectService,
        string $uuid
    ): array {
        // Populate deelzaaktypen from stored subCaseTypes UUIDs.
        $ztMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktypen');
        if ($ztMapping !== null) {
            try {
                $object = $objectService->find(
                    id: $uuid,
                    register: $ztMapping['sourceRegister'],
                    schema: $ztMapping['sourceSchema']
                );
                if (is_array($object) === true) {
                    $objectData = $object;
                } else {
                    $objectData = $object->jsonSerialize();
                }

                $subCases = $objectData['subCaseTypes'] ?? [];
                if (is_array($subCases) === true && empty($subCases) === false) {
                    // Expand each stored UUID to all ZTs with the same identifier.
                    $urls = [];
                    foreach ($subCases as $ztUuid) {
                        if (is_string($ztUuid) === false || $ztUuid === '') {
                            continue;
                        }

                        try {
                            $refObj = $objectService->find(
                                id: $ztUuid,
                                register: $ztMapping['sourceRegister'],
                                schema: $ztMapping['sourceSchema']
                            );
                            if (is_array($refObj) === true) {
                                $refData = $refObj;
                            } else {
                                $refData = $refObj->jsonSerialize();
                            }

                            $ident = $refData['identifier'] ?? '';

                            if ($ident !== '') {
                                $query  = $objectService->buildSearchQuery(
                                    requestParams: ['identifier' => $ident, '_limit' => 100],
                                    register: $ztMapping['sourceRegister'],
                                    schema: $ztMapping['sourceSchema']
                                );
                                $result = $objectService->searchObjectsPaginated(query: $query);
                                foreach (($result['results'] ?? []) as $match) {
                                    if (is_array($match) === true) {
                                        $mData = $match;
                                    } else {
                                        $mData = $match->jsonSerialize();
                                    }

                                    $mId = $mData['id'] ?? ($mData['@self']['id'] ?? '');
                                    if ($mId !== '') {
                                        $urls[] = $baseUrl.'/zaaktypen/'.$mId;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            $urls[] = $baseUrl.'/zaaktypen/'.$ztUuid;
                        }//end try
                    }//end foreach

                    $urls = array_values(array_unique($urls));
                    $data['deelzaaktypen'] = $urls;
                }//end if

                // Populate besluittypen from stored decisionTypes UUIDs.
                $decTypes = $objectData['decisionTypes'] ?? [];
                if (is_array($decTypes) === true && empty($decTypes) === false) {
                    $urls = [];
                    foreach ($decTypes as $btUuid) {
                        if (is_string($btUuid) === true && $btUuid !== '') {
                            $urls[] = $baseUrl.'/besluittypen/'.$btUuid;
                        }
                    }

                    $data['besluittypen'] = $urls;
                }
            } catch (\Throwable $e) {
                // Proceed without deelzaaktypen enrichment.
            }//end try
        }//end if

        // Expand UUIDs in gerelateerdeZaaktypen to all ZTs with same identifier.
        // Read from raw object's relatedCaseTypes (JSON-encoded string) since Twig
        // outbound mapping cannot handle array-of-objects.
        $relatedRaw = null;
        if (isset($objectData) === true) {
            $relatedRaw = $objectData['relatedCaseTypes'] ?? null;
        }

        if ($relatedRaw === null) {
            $relatedRaw = $data['gerelateerdeZaaktypen'] ?? null;
        }

        if (is_string($relatedRaw) === true) {
            $relatedRaw = json_decode($relatedRaw, true);
        }

        if (is_array($relatedRaw) === true
            && empty($relatedRaw) === false
            && $ztMapping !== null
        ) {
            $expanded = [];
            foreach ($relatedRaw as $rel) {
                $ztRef = $rel['zaaktype'] ?? '';
                if (is_string($ztRef) === false || $ztRef === '') {
                    continue;
                }

                // Already a URL — keep as-is.
                if (str_starts_with($ztRef, 'http') === true) {
                    $expanded[] = $rel;
                    continue;
                }

                // Look up identifier, find all matching ZTs.
                try {
                    $refObj = $objectService->find(
                        id: $ztRef,
                        register: $ztMapping['sourceRegister'],
                        schema: $ztMapping['sourceSchema']
                    );
                    if (is_array($refObj) === true) {
                        $refData = $refObj;
                    } else {
                        $refData = $refObj->jsonSerialize();
                    }

                    $ident = $refData['identifier'] ?? '';

                    if ($ident !== '') {
                        $query  = $objectService->buildSearchQuery(
                            requestParams: ['identifier' => $ident, '_limit' => 100],
                            register: $ztMapping['sourceRegister'],
                            schema: $ztMapping['sourceSchema']
                        );
                        $result = $objectService->searchObjectsPaginated(query: $query);
                        foreach (($result['results'] ?? []) as $match) {
                            if (is_array($match) === true) {
                                $mData = $match;
                            } else {
                                $mData = $match->jsonSerialize();
                            }

                            $mId = $mData['id'] ?? ($mData['@self']['id'] ?? '');
                            if ($mId !== '') {
                                $entry = $rel;
                                $entry['zaaktype'] = $baseUrl.'/zaaktypen/'.$mId;
                                $expanded[]        = $entry;
                            }
                        }
                    }//end if
                } catch (\Throwable $e) {
                    $rel['zaaktype'] = $baseUrl.'/zaaktypen/'.$ztRef;
                    $expanded[]      = $rel;
                }//end try
            }//end foreach

            // Deduplicate by zaaktype URL.
            $seen   = [];
            $unique = [];
            foreach ($expanded as $entry) {
                $ztUrl = $entry['zaaktype'] ?? '';
                if (isset($seen[$ztUrl]) === false) {
                    $seen[$ztUrl] = true;
                    $unique[]     = $entry;
                }
            }

            $data['gerelateerdeZaaktypen'] = $unique;
        }//end if

        // Populate informatieobjecttypen from ZIOT records.
        // For each ZIOT, find the referenced IOT, then find ALL IOTs with the
        // same name (omschrijving) so filterValidUrls can select the valid ones.
        $ziotMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktype-informatieobjecttypen');
        $iotMapping  = $this->zgwService->loadMappingConfig(self::ZGW_API, 'informatieobjecttypen');
        if ($ziotMapping !== null && $iotMapping !== null) {
            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['zaaktype' => $uuid, '_limit' => 100],
                    register: $ziotMapping['sourceRegister'],
                    schema: $ziotMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                $iotUrls = [];
                foreach (($result['results'] ?? []) as $ziot) {
                    if (is_array($ziot) === true) {
                        $ziotData = $ziot;
                    } else {
                        $ziotData = $ziot->jsonSerialize();
                    }

                    $iotRef = $ziotData['informatieobjecttype'] ?? '';
                    if ($iotRef === '') {
                        continue;
                    }

                    // Look up the IOT to get its name, then find all IOTs with that name.
                    try {
                        $iotObj = $objectService->find(
                            id: $iotRef,
                            register: $iotMapping['sourceRegister'],
                            schema: $iotMapping['sourceSchema']
                        );
                        if (is_array($iotObj) === true) {
                            $iotData = $iotObj;
                        } else {
                            $iotData = $iotObj->jsonSerialize();
                        }

                        $iotName = $iotData['name'] ?? '';

                        if ($iotName !== '') {
                            // Find ALL IOTs with this name.
                            $iotQuery  = $objectService->buildSearchQuery(
                                requestParams: ['name' => $iotName, '_limit' => 100],
                                register: $iotMapping['sourceRegister'],
                                schema: $iotMapping['sourceSchema']
                            );
                            $iotResult = $objectService->searchObjectsPaginated(query: $iotQuery);
                            foreach (($iotResult['results'] ?? []) as $matchingIot) {
                                if (is_array($matchingIot) === true) {
                                    $mData = $matchingIot;
                                } else {
                                    $mData = $matchingIot->jsonSerialize();
                                }

                                $mId = $mData['id'] ?? ($mData['@self']['id'] ?? '');
                                if ($mId !== '') {
                                    $iotUrls[] = $baseUrl.'/informatieobjecttypen/'.$mId;
                                }
                            }
                        }//end if
                    } catch (\Throwable $e) {
                        // If IOT lookup fails, fall back to direct UUID.
                        $iotUrls[] = $baseUrl.'/informatieobjecttypen/'.$iotRef;
                    }//end try
                }//end foreach

                // Deduplicate URLs.
                $iotUrls = array_values(array_unique($iotUrls));
                if (empty($iotUrls) === false) {
                    $data['informatieobjecttypen'] = $iotUrls;
                }
            } catch (\Throwable $e) {
                // Proceed without ZIOT enrichment.
            }//end try
        }//end if

        // Fallback: populate besluittypen from BT records with caseType = this UUID.
        // Only if not already populated from stored decisionTypes.
        $btMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'besluittypen');
        if ($btMapping !== null
            && (isset($data['besluittypen']) === false || empty($data['besluittypen']) === true)
        ) {
            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['caseType' => $uuid, '_limit' => 100],
                    register: $btMapping['sourceRegister'],
                    schema: $btMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                $btUrls = [];
                foreach (($result['results'] ?? []) as $bt) {
                    if (is_array($bt) === true) {
                        $btData = $bt;
                    } else {
                        $btData = $bt->jsonSerialize();
                    }

                    $btUuid = $btData['id'] ?? ($btData['@self']['id'] ?? '');
                    if ($btUuid !== '') {
                        $btUrls[] = $baseUrl.'/besluittypen/'.$btUuid;
                    }
                }

                if (empty($btUrls) === false) {
                    $data['besluittypen'] = $btUrls;
                }
            } catch (\Throwable $e) {
                // Proceed without BT enrichment.
            }//end try
        }//end if

        // Populate eigenschappen, statustypen, resultaattypen, roltypen
        // by searching for sub-resources with caseType = this zaaktype UUID.
        $subResourceTypes = [
            'eigenschappen'  => 'eigenschappen',
            'statustypen'    => 'statustypen',
            'resultaattypen' => 'resultaattypen',
            'roltypen'       => 'roltypen',
        ];
        foreach ($subResourceTypes as $zgwField => $resourceName) {
            $subMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, $resourceName);
            if ($subMapping === null) {
                continue;
            }

            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['caseType' => $uuid, '_limit' => 100],
                    register: $subMapping['sourceRegister'],
                    schema: $subMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                $urls = [];
                foreach (($result['results'] ?? []) as $sub) {
                    if (is_array($sub) === true) {
                        $subData = $sub;
                    } else {
                        $subData = $sub->jsonSerialize();
                    }

                    $subUuid = $subData['id'] ?? ($subData['@self']['id'] ?? '');
                    if ($subUuid !== '') {
                        $urls[] = $baseUrl.'/'.$resourceName.'/'.$subUuid;
                    }
                }

                if (empty($urls) === false) {
                    $data[$zgwField] = $urls;
                }
            } catch (\Throwable $e) {
                // Proceed without sub-resource enrichment.
            }//end try
        }//end foreach

        return $data;
    }//end enrichZaaktype()

    /**
     * Resolve informatieobjecttype by omschrijving when not a UUID/URL (ztc-010m).
     *
     * The ZGW standard allows referencing an IOT by omschrijving in ZIOT creation.
     * This method looks up the IOT by omschrijving and replaces it with its UUID.
     *
     * @param array $body The request body (modified in-place via cached body).
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function resolveIotByOmschrijving(array $body): void
    {
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
            $query  = $objectService->buildSearchQuery(
                requestParams: ['name' => $iotValue, '_limit' => 1],
                register: $iotMapping['sourceRegister'],
                schema: $iotMapping['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(
                query: $query,
                _rbac: false,
                _multitenancy: false
            );

            if (($result['total'] ?? 0) === 0) {
                // Fallback: full-text search.
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['_search' => $iotValue, '_limit' => 1],
                    register: $iotMapping['sourceRegister'],
                    schema: $iotMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(
                    query: $query,
                    _rbac: false,
                    _multitenancy: false
                );
            }

            if (($result['total'] ?? 0) > 0) {
                $iot = $result['results'][0];
                if (is_array($iot) === true) {
                    $iotData = $iot;
                } else {
                    $iotData = $iot->jsonSerialize();
                }

                $iotUuid = $iotData['id'] ?? ($iotData['@self']['id'] ?? '');
                if ($iotUuid !== '') {
                    $this->zgwService->updateCachedBodyField('informatieobjecttype', $iotUuid);
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'ztc-010m: Failed to resolve IOT by omschrijving: '.$e->getMessage()
            );
        }//end try
    }//end resolveIotByOmschrijving()
}//end class
