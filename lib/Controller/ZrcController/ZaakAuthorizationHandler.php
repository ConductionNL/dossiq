<?php

/**
 * ZaakAuthorizationHandler — Authorization filtering for zaken access.
 *
 * Extracted from ZrcController: handles consumer-scope-based read access
 * checks and vertrouwelijkheidaanduiding filtering (zrc-006a, zrc-006b).
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
use OCP\IL10N;
use OCP\IRequest;

/**
 * Handles authorization checks and filtering for zaak read access.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class ZaakAuthorizationHandler
{

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
     * Filter zaken results based on consumer's vertrouwelijkheidaanduiding (zrc-006a).
     *
     * @param JSONResponse $response The original index response
     * @param IRequest     $request  The incoming request
     *
     * @return JSONResponse The filtered response
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function filterZakenByAuthorisation(JSONResponse $response, IRequest $request): JSONResponse
    {
        $autorisaties = $this->zgwService->getConsumerAuthorisaties($request, 'zrc');
        if ($autorisaties === null) {
            // Unrestricted — return all.
            return $response;
        }

        // Check if any autorisatie grants zaken.lezen.
        $lezenAuths = [];
        foreach ($autorisaties as $auth) {
            $scopes = $auth['scopes'] ?? [];
            if (in_array('zaken.lezen', $scopes, true) === true) {
                $lezenAuths[] = $auth;
            }
        }

        if (empty($lezenAuths) === true) {
            // No zaken.lezen scope at all — return empty.
            $data = $response->getData();
            if (is_array($data) === true) {
                $data['count']   = 0;
                $data['results'] = [];
                $response->setData($data);
            }

            return $response;
        }

        $data = $response->getData();
        if (is_array($data) === false || isset($data['results']) === false) {
            return $response;
        }

        $filtered = [];
        foreach ($data['results'] as $zaak) {
            $zaakVa    = $zaak['vertrouwelijkheidaanduiding'] ?? 'openbaar';
            $zaakLevel = self::VERTROUWELIJKHEID_LEVELS[$zaakVa] ?? 1;

            foreach ($lezenAuths as $auth) {
                $maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
                if ($maxVa !== null) {
                    $maxLevel = self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99;
                } else {
                    $maxLevel = 99;
                }

                if ($zaakLevel <= $maxLevel) {
                    $filtered[] = $zaak;
                    break;
                }
            }
        }

        $data['count']   = count($filtered);
        $data['results'] = $filtered;
        $response->setData($data);

        return $response;
    }//end filterZakenByAuthorisation()

    /**
     * Check zaak read access based on consumer scopes and vertrouwelijkheidaanduiding (zrc-006b).
     *
     * @param string   $uuid    The zaak UUID
     * @param IRequest $request The incoming request
     *
     * @return JSONResponse|null Permission denied response, or null if access is allowed
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function checkZaakReadAccess(string $uuid, IRequest $request): ?JSONResponse
    {
        $autorisaties = $this->zgwService->getConsumerAuthorisaties($request, 'zrc');
        if ($autorisaties === null) {
            // Unrestricted (superuser or no consumer found).
            return null;
        }

        // Check if any autorisatie grants zaken.lezen.
        $hasLezenScope = false;
        foreach ($autorisaties as $auth) {
            $scopes = $auth['scopes'] ?? [];
            if (in_array('zaken.lezen', $scopes, true) === true) {
                $hasLezenScope = true;
                break;
            }
        }

        if ($hasLezenScope === false) {
            return $this->permissionDeniedResponse();
        }

        // Check vertrouwelijkheidaanduiding of the zaak.
        try {
            $mappingConfig = $this->zgwService->loadMappingConfig('zaken', 'zaken');
            if ($mappingConfig === null) {
                return null;
            }

            $zaakObj = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($zaakObj) === true) {
                $zaakData = $zaakObj;
            } else {
                $zaakData = $zaakObj->jsonSerialize();
            }

            $zaakVa    = $zaakData['confidentiality'] ?? ($zaakData['vertrouwelijkheidaanduiding'] ?? 'openbaar');
            $zaakLevel = self::VERTROUWELIJKHEID_LEVELS[$zaakVa] ?? 1;

            // Check zaaktype + maxVertrouwelijkheidaanduiding from consumer autorisaties.
            foreach ($autorisaties as $auth) {
                $scopes = $auth['scopes'] ?? [];
                if (in_array('zaken.lezen', $scopes, true) === false) {
                    continue;
                }

                $maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
                if ($maxVa !== null) {
                    $maxLevel = self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99;
                } else {
                    $maxLevel = 99;
                }

                if ($zaakLevel <= $maxLevel) {
                    return null;
                }
            }

            // No matching autorisatie allows this vertrouwelijkheidaanduiding.
            return $this->permissionDeniedResponse();
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-006b: Could not check zaak read access: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end checkZaakReadAccess()

    /**
     * Build a permission denied response (zrc-006/zrc-007).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function permissionDeniedResponse(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'detail' => $this->l10n->t('You do not have the correct permissions for this action.'),
                'code'   => 'permission_denied',
            ],
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end permissionDeniedResponse()
}//end class
