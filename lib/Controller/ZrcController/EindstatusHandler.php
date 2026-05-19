<?php

/**
 * Eindstatus Handler
 *
 * Handles eindstatus and archive-related side effects extracted from ZrcController.
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

use DateInterval;
use DateTime;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Handles eindstatus side effects and archive derivation for ZRC statussen/resultaten.
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
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class EindstatusHandler
{
    /**
     * Constructor.
     *
     * @param ZgwService $zgwService The shared ZGW service
     * @param IL10N      $l10n       The localization service
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function __construct(
        private readonly ZgwService $zgwService,
        private readonly IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Check if creating a status would reopen a closed zaak and require the
     * zaken.heropenen scope (zrc-008c).
     *
     * @param array    $body    The original request body
     * @param IRequest $request The incoming request
     *
     * @return JSONResponse|null A 403 response if scope is missing, null otherwise
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function checkReopenScope(array $body, IRequest $request): ?JSONResponse
    {
        try {
            $zaakUrl       = $body['zaak'] ?? '';
            $statustypeUrl = $body['statustype'] ?? '';
            if ($zaakUrl === '' || $statustypeUrl === '') {
                return null;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

            // Find the zaak.
            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return null;
            }

            $zaakConfig = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            if ($zaakConfig === null) {
                return null;
            }

            $zaak = $this->zgwService->getObjectService()->find(
                $zaakMatches[1],
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            $endDate = $zaakData['endDate'] ?? null;

            // Zaak is not closed — no reopen check needed.
            if ($endDate === null || $endDate === '') {
                return null;
            }

            // Zaak is closed. Check if statustype is eindstatus.
            if (preg_match($uuidPattern, $statustypeUrl, $stMatches) !== 1) {
                return null;
            }

            $stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
            if ($stConfig === null) {
                return null;
            }

            $statustype = $this->zgwService->getObjectService()->find(
                $stMatches[1],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            if (is_array($statustype) === true) {
                $stData = $statustype;
            } else {
                $stData = $statustype->jsonSerialize();
            }

            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
                return null;
            }

            // Non-eindstatus on a closed zaak = reopen attempt → check scope.
            $hasScope = $this->zgwService->consumerHasScope($request, 'zrc', 'zaken.heropenen');
            if ($hasScope === false) {
                return $this->permissionDeniedResponse();
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-008c: Could not check reopen scope: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkReopenScope()

    /**
     * Set indicatieGebruiksrecht on all linked IOs and then verify none remain
     * null before allowing an eindstatus (zrc-007b + zrc-007q).
     *
     * First attempts to set indicatieGebruiksrecht on all linked IOs (zrc-007b).
     * Then checks that all linked IOs have indicatieGebruiksrecht set. If any
     * still have null after setting, returns 400 (zrc-007q).
     *
     * @param array $body The original request body
     *
     * @return JSONResponse|null A 400 response if any IO has null indicatieGebruiksrecht, null otherwise
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function checkIndicatieGebruiksrechtBeforeClose(array $body): ?JSONResponse
    {
        try {
            $zaakUrl       = $body['zaak'] ?? '';
            $statustypeUrl = $body['statustype'] ?? '';
            if ($zaakUrl === '' || $statustypeUrl === '') {
                return null;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

            // Check if this is an eindstatus.
            if (preg_match($uuidPattern, $statustypeUrl, $stMatches) !== 1) {
                return null;
            }

            $stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
            if ($stConfig === null) {
                return null;
            }

            $statustype = $this->zgwService->getObjectService()->find(
                $stMatches[1],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            if ($statustype === null) {
                return null;
            }

            if (is_array($statustype) === true) {
                $stData = $statustype;
            } else {
                $stData = $statustype->jsonSerialize();
            }

            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            // Normalize boolean.
            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
                $isEindstatus = true;
            }

            // Also check by highest volgnummer if not explicitly set.
            if ($isEindstatus !== true) {
                $isEindstatus = $this->isEindstatusByVolgnummer(
                    stData: $stData,
                    stConfig: $stConfig,
                    uuidPattern: $uuidPattern
                );
            }

            if ($isEindstatus !== true) {
                return null;
            }

            // This is an eindstatus — check indicatieGebruiksrecht (zrc-007q).
            // Only derive values (zrc-007b) on the FIRST close (no endDate yet).
            // If zaak is already closed, just check raw values without deriving.
            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return null;
            }

            // Check if zaak is already closed (has endDate).
            $zaakConfig        = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            $zaakAlreadyClosed = false;
            if ($zaakConfig !== null) {
                $zaakObj = $this->zgwService->getObjectService()->find(
                    $zaakMatches[1],
                    register: $zaakConfig['sourceRegister'],
                    schema: $zaakConfig['sourceSchema']
                );
                if ($zaakObj !== null) {
                    if (is_array($zaakObj) === true) {
                        $zaakData = $zaakObj;
                    } else {
                        $zaakData = $zaakObj->jsonSerialize();
                    }

                    $endDate           = $zaakData['endDate'] ?? ($zaakData['einddatum'] ?? null);
                    $zaakAlreadyClosed = ($endDate !== null && $endDate !== '');
                }
            }

            // Zrc-007b: Only derive indicatieGebruiksrecht on first close.
            if ($zaakAlreadyClosed === false) {
                $this->setIndicatieGebruiksrechtOnClose(zaakUuid: $zaakMatches[1]);
            }

            // Zrc-007q: Now verify all linked IOs have indicatieGebruiksrecht set.
            $zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
            $docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
            if ($zioConfig === null || $docConfig === null) {
                return null;
            }

            $query     = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakMatches[1], '_limit' => 100],
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            $zioResult = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($zioResult['results'] ?? []) as $zioObj) {
                if (is_array($zioObj) === true) {
                    $zioData = $zioObj;
                } else {
                    $zioData = $zioObj->jsonSerialize();
                }

                $docUuid = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

                if (preg_match($uuidPattern, (string) $docUuid, $docMatches) !== 1) {
                    continue;
                }

                $docObj = $this->zgwService->getObjectService()->find(
                    $docMatches[1],
                    register: $docConfig['sourceRegister'],
                    schema: $docConfig['sourceSchema']
                );
                if (is_array($docObj) === true) {
                    $docData = $docObj;
                } else {
                    $docData = $docObj->jsonSerialize();
                }

                $indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));

                if ($indGr === null || $indGr === '') {
                    $detail = 'Zaak kan niet afgesloten worden: niet alle informatieobjecten hebben indicatieGebruiksrecht gezet.';
                    return new JSONResponse(
                        data: [
                            'detail'        => $detail,
                            'code'          => 'indicatiegebruiksrecht-unset',
                            'invalidParams' => [
                                [
                                    'name'   => 'nonFieldErrors',
                                    'code'   => 'indicatiegebruiksrecht-unset',
                                    'reason' => $detail,
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-007q: Could not check indicatieGebruiksrecht: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkIndicatieGebruiksrechtBeforeClose()

    /**
     * Handle eindstatus side effect when creating a status.
     *
     * When the created status's statustype has isEindstatus=true, sets the
     * parent zaak's einddatum to the datumStatusGezet value.
     * Also handles zrc-007b (set indicatieGebruiksrecht on linked documents).
     *
     * @param array $body       The original request body
     * @param array $objectData The created object data
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function handleEindstatusEffect(array $body, array $objectData): void
    {
        try {
            $statustypeUrl = $body['statustype'] ?? '';
            if ($statustypeUrl === '') {
                return;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, $statustypeUrl, $matches) !== 1) {
                return;
            }

            $stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
            if ($stConfig === null) {
                return;
            }

            $statustype = $this->zgwService->getObjectService()->find(
                $matches[1],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            if ($statustype === null) {
                return;
            }

            if (is_array($statustype) === true) {
                $stData = $statustype;
            } else {
                $stData = $statustype->jsonSerialize();
            }

            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            // Normalize boolean from OpenRegister (may be string/int).
            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
                $isEindstatus = true;
            }

            // ZGW standard: if isFinal not explicitly set, the statustype with
            // the highest volgnummer for this zaaktype is the eindstatus.
            if ($isEindstatus !== true) {
                $caseTypeUuid = (string) ($stData['caseType'] ?? '');
                // Extract UUID in case caseType is stored as a URL.
                if (preg_match($uuidPattern, $caseTypeUuid, $ctMatches) === 1) {
                    $caseTypeUuid = $ctMatches[1];
                }

                $thisOrder = (int) ($stData['order'] ?? ($stData['volgnummer'] ?? 0));
                if ($caseTypeUuid !== '' && $thisOrder > 0) {
                    // Search for all statustypen of this zaaktype.
                    try {
                        $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                            requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
                            register: $stConfig['sourceRegister'],
                            schema: $stConfig['sourceSchema']
                        );
                        $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);
                    } catch (\Throwable $e) {
                        // Fallback: try direct query without buildSearchQuery.
                        $result = $this->zgwService->getObjectService()->searchObjectsPaginated(
                            query: [
                                '@self'    => [
                                    'register' => (int) $stConfig['sourceRegister'],
                                    'schema'   => (int) $stConfig['sourceSchema'],
                                ],
                                'caseType' => $caseTypeUuid,
                            ]
                        );
                    }

                    $maxOrder = 0;
                    foreach (($result['results'] ?? []) as $st) {
                        if (is_array($st) === true) {
                            $stObj = $st;
                        } else {
                            $stObj = $st->jsonSerialize();
                        }

                        $order = (int) ($stObj['order'] ?? ($stObj['volgnummer'] ?? 0));
                        if ($order > $maxOrder) {
                            $maxOrder = $order;
                        }
                    }

                    if ($thisOrder >= $maxOrder && $maxOrder > 0) {
                        $isEindstatus = true;
                    }
                }//end if
            }//end if

            $zaakUrl = $body['zaak'] ?? '';
            if ($zaakUrl === '') {
                return;
            }

            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return;
            }

            $zaakConfig = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            if ($zaakConfig === null) {
                return;
            }

            $zaak = $this->zgwService->getObjectService()->find(
                $zaakMatches[1],
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if ($zaak === null) {
                return;
            }

            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            // Strip metadata that confuses saveObject on re-save.
            unset($zaakData['@self'], $zaakData['organisation']);

            // Ensure field types match schema expectations for re-save.
            // OpenRegister may store numeric-looking strings as integers, but the
            // schema expects string types for fields like bronorganisatie.
            $stringFields = ['title', 'assignee', 'sourceOrganisation', 'identifier'];
            foreach ($stringFields as $field) {
                if (isset($zaakData[$field]) === true && is_int($zaakData[$field]) === true) {
                    $zaakData[$field] = (string) $zaakData[$field];
                }

                if ($field === 'title' && isset($zaakData[$field]) === false) {
                    $zaakData[$field] = '';
                }
            }

            if ($isEindstatus === true) {
                // Zrc-007a: Set zaak einddatum when eindstatus is created.
                $datumStatusGezet = $body['datumStatusGezet'] ?? ($objectData['statusSetDate'] ?? date('Y-m-d'));
                if (strlen($datumStatusGezet) > 10) {
                    $datumStatusGezet = substr($datumStatusGezet, 0, 10);
                }

                $zaakData['endDate'] = $datumStatusGezet;

                // Zrc-021: Derive archiefactiedatum from resultaat.resultaattype.brondatumArchiefprocedure.
                $zaakData = $this->deriveArchiefactiedatum(
                    zaakData: $zaakData,
                    zaakConfig: $zaakConfig,
                    datumStatusGezet: $datumStatusGezet
                );

                $zaakData['id'] = $zaakMatches[1];
                $this->zgwService->getObjectService()->saveObject(
                    register: $zaakConfig['sourceRegister'],
                    schema: $zaakConfig['sourceSchema'],
                    object: $zaakData,
                    uuid: $zaakMatches[1]
                );

                // Zrc-007b: Set indicatieGebruiksrecht on all related informatieobjecten.
                $this->setIndicatieGebruiksrechtOnClose(zaakUuid: $zaakMatches[1]);
            }//end if

            if ($isEindstatus === false) {
                // Zrc-008: Heropenen zaak — when a non-eindstatus is created on
                // a zaak that already has an endDate, clear endDate, archiefactiedatum,
                // and archiefnominatie (reopen the zaak).
                $existingEndDate = $zaakData['endDate'] ?? null;
                if ($existingEndDate !== null && $existingEndDate !== '') {
                    $zaakData['endDate']           = null;
                    $zaakData['archiveActionDate'] = null;
                    $zaakData['archiveNomination'] = null;
                    $zaakData['id'] = $zaakMatches[1];
                    $this->zgwService->getObjectService()->saveObject(
                        register: $zaakConfig['sourceRegister'],
                        schema: $zaakConfig['sourceSchema'],
                        object: $zaakData,
                        uuid: $zaakMatches[1]
                    );

                    $this->zgwService->getLogger()->info(
                        'zrc-008: Heropened zaak '.$zaakMatches[1].' — cleared endDate, archiveActionDate, archiveNomination'
                    );
                }
            }//end if
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'handleEindstatusEffect failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try
    }//end handleEindstatusEffect()

    /**
     * Handle resultaat creation side-effects (zrc-021).
     *
     * When a resultaat is created, derive archiefactiedatum and
     * archiefnominatie on the parent zaak from the resultaattype.
     *
     * @param array $body       The original request body (Dutch names)
     * @param array $objectData The created resultaat object data
     *
     * @return void
     *
     * @psalm-suppress UnusedParam — $objectData reserved for future use in result processing
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $objectData reserved for future result processing
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function handleResultaatCreated(array $body, array $objectData): void
    {
        try {
            $zaakUrl = $body['zaak'] ?? '';
            if ($zaakUrl === '') {
                return;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return;
            }

            $zaakConfig = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            if ($zaakConfig === null) {
                return;
            }

            $zaakObj = $this->zgwService->getObjectService()->find(
                $zaakMatches[1],
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if (is_array($zaakObj) === true) {
                $zaakData = $zaakObj;
            } else {
                $zaakData = $zaakObj->jsonSerialize();
            }

            // Use the zaak endDate as einddatum (may be null if zaak isn't closed yet).
            $einddatum = $zaakData['endDate'] ?? date('Y-m-d');

            $zaakData = $this->deriveArchiefactiedatum(
                zaakData: $zaakData,
                zaakConfig: $zaakConfig,
                datumStatusGezet: $einddatum
            );

            // Type coercion for re-save (OpenRegister stores numeric strings as ints).
            $stringFields = ['title', 'assignee', 'sourceOrganisation', 'identifier'];
            foreach ($stringFields as $field) {
                if (isset($zaakData[$field]) === true && is_int($zaakData[$field]) === true) {
                    $zaakData[$field] = (string) $zaakData[$field];
                }

                if ($field === 'title' && isset($zaakData[$field]) === false) {
                    $zaakData[$field] = '';
                }
            }

            // Save the updated zaak.
            $zaakData['id'] = $zaakMatches[1];
            $this->zgwService->getObjectService()->saveObject(
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema'],
                object: $zaakData,
                uuid: $zaakMatches[1]
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'zrc-021: handleResultaatCreated failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try
    }//end handleResultaatCreated()

    /**
     * Build a permission denied response.
     *
     * @return JSONResponse
     */
    private function permissionDeniedResponse(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'detail' => $this->l10n->t('You do not have the correct permissions for this action.'),
                'code'   => 'permission_denied',
            ],
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end permissionDeniedResponse()

    /**
     * Check if a statustype is the eindstatus by having the highest volgnummer.
     *
     * @param array  $stData      The statustype data
     * @param array  $stConfig    The statustype mapping config
     * @param string $uuidPattern The UUID regex pattern
     *
     * @return bool True if this statustype has the highest volgnummer
     */
    private function isEindstatusByVolgnummer(array $stData, array $stConfig, string $uuidPattern): bool
    {
        $caseTypeUuid = (string) ($stData['caseType'] ?? '');
        if (preg_match($uuidPattern, $caseTypeUuid, $ctMatches) === 1) {
            $caseTypeUuid = $ctMatches[1];
        }

        $thisOrder = (int) ($stData['order'] ?? ($stData['volgnummer'] ?? 0));
        if ($caseTypeUuid === '' || $thisOrder <= 0) {
            return false;
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);
        } catch (\Throwable $e) {
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(
                query: [
                    '@self'    => [
                        'register' => (int) $stConfig['sourceRegister'],
                        'schema'   => (int) $stConfig['sourceSchema'],
                    ],
                    'caseType' => $caseTypeUuid,
                ]
            );
        }

        $maxOrder = 0;
        foreach (($result['results'] ?? []) as $st) {
            if (is_array($st) === true) {
                $stObj = $st;
            } else {
                $stObj = $st->jsonSerialize();
            }

            $order = (int) ($stObj['order'] ?? ($stObj['volgnummer'] ?? 0));
            if ($order > $maxOrder) {
                $maxOrder = $order;
            }
        }

        return $thisOrder >= $maxOrder && $maxOrder > 0;
    }//end isEindstatusByVolgnummer()

    /**
     * Set indicatieGebruiksrecht on all informatieobjecten linked to a zaak (zrc-007b).
     *
     * When a zaak is closed, all related informatieobjecten must have
     * indicatieGebruiksrecht set (not null).
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return void
     */
    private function setIndicatieGebruiksrechtOnClose(string $zaakUuid): void
    {
        try {
            $zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
            $docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
            if ($zioConfig === null || $docConfig === null) {
                return;
            }

            // Find all ZIOs for this zaak.
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, '_limit' => 100],
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $zioObj) {
                if (is_array($zioObj) === true) {
                    $zioData = $zioObj;
                } else {
                    $zioData = $zioObj->jsonSerialize();
                }

                $docUuid = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

                $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
                if (preg_match($uuidPattern, (string) $docUuid, $docMatches) !== 1) {
                    continue;
                }

                try {
                    $docObj = $this->zgwService->getObjectService()->find(
                        $docMatches[1],
                        register: $docConfig['sourceRegister'],
                        schema: $docConfig['sourceSchema']
                    );
                    if (is_array($docObj) === true) {
                        $docData = $docObj;
                    } else {
                        $docData = $docObj->jsonSerialize();
                    }

                    // Check if indicatieGebruiksrecht is already set.
                    $indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));

                    if ($indGr === null || $indGr === '') {
                        // Check if gebruiksrechten exist for this document.
                        $grConfig = $this->zgwService->getZgwMappingService()->getMapping('gebruiksrechten');
                        $hasGr    = false;
                        if ($grConfig !== null) {
                            try {
                                $grQuery  = $this->zgwService->getObjectService()->buildSearchQuery(
                                    requestParams: ['document' => $docMatches[1], '_limit' => 1],
                                    register: $grConfig['sourceRegister'],
                                    schema: $grConfig['sourceSchema']
                                );
                                $grResult = $this->zgwService->getObjectService()
                                    ->searchObjectsPaginated(query: $grQuery);
                                $hasGr    = empty($grResult['results'] ?? []) === false;
                            } catch (\Throwable $e) {
                                // No gebruiksrechten schema — default to false.
                            }
                        }

                        // Set indicatieGebruiksrecht based on whether gebruiksrechten exist.
                        unset($docData['@self'], $docData['organisation']);
                        $docData['usageRightsIndication'] = $hasGr;
                        $docData['id'] = $docMatches[1];
                        $this->zgwService->getObjectService()->saveObject(
                            register: $docConfig['sourceRegister'],
                            schema: $docConfig['sourceSchema'],
                            object: $docData,
                            uuid: $docMatches[1]
                        );
                    }//end if
                } catch (\Throwable $e) {
                    $this->zgwService->getLogger()->debug(
                        'zrc-007b: Could not update indicatieGebruiksrecht for doc '.$docMatches[1].': '.$e->getMessage()
                    );
                }//end try
            }//end foreach
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-007b: Failed to set indicatieGebruiksrecht: '.$e->getMessage()
            );
        }//end try
    }//end setIndicatieGebruiksrechtOnClose()

    /**
     * Derive archiefactiedatum from resultaat's resultaattype brondatumArchiefprocedure (zrc-021).
     *
     * @param array  $zaakData         The zaak data
     * @param array  $zaakConfig       The zaak mapping config
     * @param string $datumStatusGezet The datumStatusGezet (einddatum)
     *
     * @return array The zaak data with derived archiving parameters
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function deriveArchiefactiedatum(array $zaakData, array $zaakConfig, string $datumStatusGezet): array
    {
        try {
            // Find the zaak's resultaat to get the resultaattype.
            $zaakUuid = $zaakData['id'] ?? ($zaakData['@self']['id'] ?? '');
            if ($zaakUuid === '') {
                return $zaakData;
            }

            $resultaatConfig = $this->zgwService->getZgwMappingService()->getMapping('resultaat');
            if ($resultaatConfig === null) {
                return $zaakData;
            }

            // Search for resultaat linked to this zaak.
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, '_limit' => 1],
                register: $resultaatConfig['sourceRegister'],
                schema: $resultaatConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === true) {
                return $zaakData;
            }

            $resultaat = $results[0];
            if (is_array($resultaat) === true) {
                $resultaatData = $resultaat;
            } else {
                $resultaatData = $resultaat->jsonSerialize();
            }

            // Get the resultaattype to find brondatumArchiefprocedure.
            $resultaattypeId = $resultaatData['resultType'] ?? ($resultaatData['resultaattype'] ?? '');
            if (empty($resultaattypeId) === true) {
                return $zaakData;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, (string) $resultaattypeId, $rtMatches) !== 1) {
                return $zaakData;
            }

            $rtConfig = $this->zgwService->getZgwMappingService()->getMapping('resultaattype');
            if ($rtConfig === null) {
                return $zaakData;
            }

            $rtObj = $this->zgwService->getObjectService()->find(
                $rtMatches[1],
                register: $rtConfig['sourceRegister'],
                schema: $rtConfig['sourceSchema']
            );
            if ($rtObj === null) {
                return $zaakData;
            }

            if (is_array($rtObj) === true) {
                $rtData = $rtObj;
            } else {
                $rtData = $rtObj->jsonSerialize();
            }

            // Get brondatumArchiefprocedure.
            $brondatum = $rtData['sourceDateArchiveProcedure'] ?? ($rtData['brondatumArchiefprocedure'] ?? null);
            if (is_string($brondatum) === true) {
                $brondatum = json_decode($brondatum, true);
            }

            if ($brondatum === null || is_array($brondatum) === false) {
                return $zaakData;
            }

            $afleidingswijze = $brondatum['derivationMethod'] ?? ($brondatum['afleidingswijze'] ?? '');
            // Archiefactietermijn lives on the ResultaatType, not inside brondatumArchiefprocedure.
            $procestermijn = $rtData['archivalPeriod'] ?? ($rtData['archiefactietermijn'] ?? null);

            // Determine the base date based on afleidingswijze.
            $baseDate = $this->resolveArchiveBaseDate(
                afleidingswijze: $afleidingswijze,
                einddatum: $datumStatusGezet,
                zaakData: $zaakData,
                zaakConfig: $zaakConfig,
                brondatum: $brondatum
            );

            if ($baseDate === null) {
                // Base date unresolvable — set archiefactiedatum to null but still derive archiefnominatie.
                $zaakData['archiveActionDate'] = null;

                $nomination = $rtData['archivalAction'] ?? ($rtData['archiveNomination'] ?? ($rtData['archiefnominatie'] ?? ''));
                if ($nomination !== '') {
                    $zaakData['archiveNomination'] = $nomination;
                }

                return $zaakData;
            }

            // Add procestermijn (ISO 8601 duration) to the base date.
            $archiefactiedatum = $baseDate;
            if ($procestermijn !== null && $procestermijn !== '') {
                try {
                    $dateObj  = new DateTime($baseDate);
                    $interval = new DateInterval($procestermijn);
                    $dateObj->add($interval);
                    $archiefactiedatum = $dateObj->format('Y-m-d');
                } catch (\Throwable $e) {
                    $this->zgwService->getLogger()->debug(
                        'zrc-021: Invalid procestermijn: '.$procestermijn
                    );
                }
            }

            $zaakData['archiveActionDate'] = $archiefactiedatum;

            // Zrc-021: Also set archiveNomination from the resultaattype.
            $nomination = $rtData['archivalAction'] ?? ($rtData['archiveNomination'] ?? ($rtData['archiefnominatie'] ?? ''));
            if ($nomination !== '') {
                $zaakData['archiveNomination'] = $nomination;
            }

            $this->zgwService->getLogger()->info(
                'zrc-021: Derived archiefactiedatum='.$archiefactiedatum.' (afleidingswijze='.$afleidingswijze.')'
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-021: Failed to derive archiefactiedatum: '.$e->getMessage()
            );
        }//end try

        return $zaakData;
    }//end deriveArchiefactiedatum()

    /**
     * Resolve the base date for archive action date derivation (zrc-021).
     *
     * @param string $afleidingswijze The derivation method
     * @param string $einddatum       The zaak end date
     * @param array  $zaakData        The zaak data
     * @param array  $zaakConfig      The zaak mapping config
     * @param array  $brondatum       The brondatumArchiefprocedure data
     *
     * @return string|null The base date, or null if not resolvable
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function resolveArchiveBaseDate(
        string $afleidingswijze,
        string $einddatum,
        array $zaakData,
        array $zaakConfig,
        array $brondatum
    ): ?string {
        switch ($afleidingswijze) {
            case 'afgehandeld':
            case 'termijn':
                return $einddatum;

            case 'ander_datumkenmerk':
                // Cannot be automatically determined — requires external datumkenmerk.
                return null;

            case 'hoofdzaak':
                $mainCaseId = $zaakData['parentCase'] ?? ($zaakData['mainCase'] ?? ($zaakData['hoofdzaak'] ?? ''));
                if (empty($mainCaseId) === true) {
                    return $einddatum;
                }

                $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
                if (preg_match($uuidPattern, (string) $mainCaseId, $matches) === 1) {
                    try {
                        $mainZaak = $this->zgwService->getObjectService()->find(
                            $matches[1],
                            register: $zaakConfig['sourceRegister'],
                            schema: $zaakConfig['sourceSchema']
                        );
                        if (is_array($mainZaak) === true) {
                            $mainData = $mainZaak;
                        } else {
                            $mainData = $mainZaak->jsonSerialize();
                        }

                        $mainEnd = $mainData['endDate'] ?? null;
                        if ($mainEnd !== null && $mainEnd !== '') {
                            if (is_string($mainEnd) === true) {
                                return substr($mainEnd, 0, 10);
                            }

                            return $einddatum;
                        }
                    } catch (\Throwable $e) {
                        // Fall through to einddatum.
                    }//end try
                }//end if
                return $einddatum;

            case 'eigenschap':
                $datumkenmerk = $brondatum['objectAttribute'] ?? ($brondatum['datumkenmerk'] ?? '');
                if ($datumkenmerk !== '' && $this->zgwService->getObjectService() !== null) {
                    return $this->resolveEigenschapDate(zaakData: $zaakData, datumkenmerk: $datumkenmerk) ?? $einddatum;
                }
                return $einddatum;

            case 'ingangsdatum_besluit':
                return $this->resolveBesluitDate(
                    zaakData: $zaakData,
                    englishField: 'effectiveDate',
                    dutchField: 'ingangsdatum'
                ) ?? $einddatum;

            case 'vervaldatum_besluit':
                return $this->resolveBesluitDate(
                    zaakData: $zaakData,
                    englishField: 'expiryDate',
                    dutchField: 'vervaldatum'
                ) ?? $einddatum;

            default:
                return null;
        }//end switch
    }//end resolveArchiveBaseDate()

    /**
     * Resolve a zaakeigenschap date value for archive derivation (zrc-021 eigenschap).
     *
     * @param array  $zaakData     The zaak data
     * @param string $datumkenmerk The eigenschap name/key to look up
     *
     * @return string|null The date value, or null if not found
     */
    private function resolveEigenschapDate(array $zaakData, string $datumkenmerk): ?string
    {
        $zaakUuid = $zaakData['id'] ?? ($zaakData['@self']['id'] ?? '');
        if ($zaakUuid === '') {
            return null;
        }

        $propConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakeigenschap');
        if ($propConfig === null) {
            return null;
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, 'name' => $datumkenmerk],
                register: $propConfig['sourceRegister'],
                schema: $propConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === false) {
                $propObj = $results[0];
                if (is_array($propObj) === true) {
                    $propData = $propObj;
                } else {
                    $propData = $propObj->jsonSerialize();
                }

                $value = $propData['value'] ?? ($propData['waarde'] ?? '');
                if ($value !== '' && strtotime($value) !== false) {
                    return substr($value, 0, 10);
                }
            }
        } catch (\Throwable $e) {
            // Not found — return null.
        }//end try

        return null;
    }//end resolveEigenschapDate()

    /**
     * Resolve a besluit date field for archive derivation (zrc-021 ingangsdatum/vervaldatum).
     *
     * @param array  $zaakData     The zaak data
     * @param string $englishField The English field name
     * @param string $dutchField   The Dutch field name (fallback)
     *
     * @return string|null The date value, or null if not found
     */
    private function resolveBesluitDate(array $zaakData, string $englishField, string $dutchField): ?string
    {
        $zaakUuid = $zaakData['id'] ?? ($zaakData['@self']['id'] ?? '');
        if ($zaakUuid === '') {
            return null;
        }

        $besluitConfig = $this->zgwService->getZgwMappingService()->getMapping('besluit');
        if ($besluitConfig === null) {
            return null;
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, '_limit' => 100],
                register: $besluitConfig['sourceRegister'],
                schema: $besluitConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === true) {
                return null;
            }

            // Find the latest (maximum) date among all besluiten for this zaak.
            $latestDate = null;
            foreach ($results as $besluitObj) {
                if (is_array($besluitObj) === true) {
                    $besluitData = $besluitObj;
                } else {
                    $besluitData = $besluitObj->jsonSerialize();
                }

                $dateVal = $besluitData[$englishField] ?? ($besluitData[$dutchField] ?? '');
                if ($dateVal !== '' && strtotime($dateVal) !== false) {
                    $dateStr = substr($dateVal, 0, 10);
                    if ($latestDate === null || $dateStr > $latestDate) {
                        $latestDate = $dateStr;
                    }
                }
            }

            return $latestDate;
        } catch (\Throwable $e) {
            // Not found — return null.
        }//end try

        return null;
    }//end resolveBesluitDate()
}//end class
