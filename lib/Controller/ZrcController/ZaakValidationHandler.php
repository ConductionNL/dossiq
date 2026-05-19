<?php

/**
 * ZaakValidationHandler — Pre-validation for zaak request bodies.
 *
 * Extracted from ZrcController: validates communicatiekanaal URL format
 * and productenOfDiensten before delegating to handleUpdate (zrc-010, zrc-015).
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
 * Handles pre-validation of zaak request body fields before persistence.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class ZaakValidationHandler
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
     * Pre-validate zaak body fields before calling handleUpdate (zrc-010/zrc-015).
     *
     * Validates communicatiekanaal URL format and productenOfDiensten
     * without requiring the existing object from OpenRegister.
     * This ensures validation errors are returned with proper invalidParams
     * even when OpenRegister's find() call fails transiently.
     *
     * @param bool     $isPatch Whether this is a PATCH operation
     * @param IRequest $request The incoming request
     *
     * @return JSONResponse|null A 400 response if validation fails, null if valid
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $isPatch reserved for partial-update validation
     *
     * @psalm-suppress UnusedParam — $isPatch reserved for partial-update validation
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function preValidateZaakBody(bool $isPatch, IRequest $request): ?JSONResponse
    {
        try {
            $body = $this->zgwService->getRequestBody($request);

            // Zrc-010: Validate communicatiekanaal URL.
            $commKanaal = $body['communicatiekanaal'] ?? null;
            if ($commKanaal !== null && $commKanaal !== '') {
                if (filter_var($commKanaal, FILTER_VALIDATE_URL) === false) {
                    return new JSONResponse(
                        data: [
                            'detail'        => 'De communicatiekanaal URL is ongeldig.',
                            'invalidParams' => [
                                [
                                    'name'   => 'communicatiekanaal',
                                    'code'   => 'bad-url',
                                    'reason' => 'De communicatiekanaal URL is ongeldig.',
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }

                // Check if URL ends with a valid UUID (resource endpoint, not collection).
                $path    = (string) parse_url($commKanaal, PHP_URL_PATH);
                $hasUuid = preg_match(
                    '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i',
                    $path
                ) === 1;

                if ($hasUuid === false) {
                    // Determine error code: garbled UUID → bad-url, collection endpoint → invalid-resource.
                    $segments      = array_filter(explode('/', trim($path, '/')));
                    $last          = end($segments);
                    $looksLikeUuid = preg_match('/[0-9a-f]{4,}-/i', (string) $last) === 1;
                    if ($looksLikeUuid === true) {
                        $code = 'bad-url';
                    } else {
                        $code = 'invalid-resource';
                    }

                    return new JSONResponse(
                        data: [
                            'detail'        => 'De communicatiekanaal URL is ongeldig.',
                            'invalidParams' => [
                                [
                                    'name'   => 'communicatiekanaal',
                                    'code'   => $code,
                                    'reason' => 'De communicatiekanaal URL is ongeldig.',
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }//end if
            }//end if

            // Zrc-015: Validate productenOfDiensten.
            $producten = $body['productenOfDiensten'] ?? null;
            if (is_array($producten) === true
                && empty($producten) === false
                && $this->zgwService->getObjectService() !== null
            ) {
                $zaaktypeUrl = $body['zaaktype'] ?? '';
                if (empty($zaaktypeUrl) === false) {
                    $error = $this->preValidateProductenOfDiensten(
                        producten: $producten,
                        zaaktypeUrl: $zaaktypeUrl
                    );
                    if ($error !== null) {
                        return $error;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Pre-validation is best-effort; fall through to handleUpdate.
            $this->zgwService->getLogger()->debug(
                'preValidateZaakBody: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end preValidateZaakBody()

    /**
     * Pre-validate productenOfDiensten against zaaktype (zrc-015).
     *
     * @param array  $producten   The productenOfDiensten URLs
     * @param string $zaaktypeUrl The zaaktype URL
     *
     * @return JSONResponse|null A 400 response if invalid, null if valid
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function preValidateProductenOfDiensten(
        array $producten,
        string $zaaktypeUrl
    ): ?JSONResponse {
        $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
        if (preg_match($uuidPattern, $zaaktypeUrl, $matches) !== 1) {
            return null;
        }

        $ztConfig = $this->zgwService->getZgwMappingService()->getMapping('zaaktype');
        if ($ztConfig === null) {
            return null;
        }

        try {
            $ztObj = $this->zgwService->getObjectService()->find(
                $matches[1],
                register: $ztConfig['sourceRegister'],
                schema: $ztConfig['sourceSchema']
            );
            if (is_array($ztObj) === true) {
                $ztData = $ztObj;
            } else {
                $ztData = $ztObj->jsonSerialize();
            }
        } catch (\Throwable $e) {
            return null;
        }

        $allowed = $ztData['productsOrServices'] ?? ($ztData['productsAndServices'] ?? ($ztData['productenOfDiensten'] ?? []));
        if (is_string($allowed) === true) {
            $allowed = json_decode($allowed, true) ?? [];
        }

        if (is_array($allowed) === false || empty($allowed) === true) {
            return null;
        }

        foreach ($producten as $product) {
            if (in_array($product, $allowed, true) === false) {
                return new JSONResponse(
                    data: [
                        'detail'        => $this->l10n->t('productenOfDiensten contains a value not present in the zaaktype.'),
                        'invalidParams' => [
                            [
                                'name'   => 'productenOfDiensten',
                                'code'   => 'invalid-products-services',
                                'reason' => $this->l10n->t('Product \'%s\' is not allowed for this zaaktype.', [$product]),
                            ],
                        ],
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }
        }

        return null;
    }//end preValidateProductenOfDiensten()
}//end class
