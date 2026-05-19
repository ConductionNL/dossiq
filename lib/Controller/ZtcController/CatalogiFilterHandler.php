<?php

/**
 * CatalogiFilterHandler — Filters ZTC resources by date validity and URL validity.
 *
 * Extracted from ZtcController: filters catalogus results by datumGeldigheid
 * and removes URL references to unpublished or date-invalid objects.
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

/**
 * Handles date-validity and URL-validity filtering for ZTC resource responses.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-5
 */
class CatalogiFilterHandler
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
     * @param ZgwService $zgwService The shared ZGW service.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function __construct(
        private readonly ZgwService $zgwService,
    ) {
    }//end __construct()

    /**
     * Filter a list of ZTC results by datumGeldigheid (date validity).
     *
     * Returns only items where beginGeldigheid <= datumGeldigheid and
     * (eindeGeldigheid >= datumGeldigheid or eindeGeldigheid is absent).
     *
     * @param array  $results         The array of outbound-mapped result items.
     * @param string $datumGeldigheid The validity date in Y-m-d format.
     *
     * @return array The filtered results (re-indexed).
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function filterByDatumGeldigheid(array $results, string $datumGeldigheid): array
    {
        $filtered = [];
        foreach ($results as $item) {
            $begin = $item['beginGeldigheid'] ?? null;
            $end   = $item['eindeGeldigheid'] ?? null;

            // BeginGeldigheid must be present and <= datumGeldigheid.
            if ($begin !== null && $begin !== '' && $begin > $datumGeldigheid) {
                continue;
            }

            // EindeGeldigheid, if present, must be >= datumGeldigheid.
            if ($end !== null && $end !== '' && $end < $datumGeldigheid) {
                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }//end filterByDatumGeldigheid()

    /**
     * For zaaktypen and besluittypen, removes URLs from array fields that point to
     * objects which are not published or not currently valid (date-wise).
     *
     * @param string $resource The ZGW resource name.
     * @param array  $data     The outbound-mapped response data.
     *
     * @return array The filtered response data.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function filterValidUrls(string $resource, array $data): array
    {
        $fieldConfigs = self::URL_FILTER_FIELDS[$resource] ?? [];
        if (empty($fieldConfigs) === true || $this->zgwService->getObjectService() === null) {
            return $data;
        }

        $today = date('Y-m-d');

        foreach ($fieldConfigs as $field => $config) {
            if (isset($data[$field]) === false || is_array($data[$field]) === false) {
                continue;
            }

            $schemaKey = $config['schemaKey'];
            $nested    = $config['nested'];

            $filtered = [];
            foreach ($data[$field] as $item) {
                if ($nested === true) {
                    // GerelateerdeZaaktypen: array of objects with 'zaaktype' URL field.
                    $url = $item['zaaktype'] ?? '';
                    if ($this->isUrlValid(url: $url, schemaKey: $schemaKey, today: $today) === true) {
                        $filtered[] = $item;
                    }
                }

                if ($nested === false
                    && is_string($item) === true
                    && $this->isUrlValid(url: $item, schemaKey: $schemaKey, today: $today) === true
                ) {
                    $filtered[] = $item;
                }
            }

            $data[$field] = $filtered;
        }//end foreach

        return $data;
    }//end filterValidUrls()

    /**
     * Check if a ZGW URL points to a valid, published, and currently active object.
     *
     * Uses the mapping config's sourceRegister and sourceSchema to look up the object.
     * The schemaKey maps to a ZGW resource name for which we load its mapping config.
     *
     * @param string $url       The URL to validate.
     * @param string $schemaKey The settings config key identifying the target schema.
     * @param string $today     Today's date in Y-m-d format.
     *
     * @return bool True if the referenced object exists, is published, and is date-valid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-5
     */
    public function isUrlValid(string $url, string $schemaKey, string $today): bool
    {
        if (empty($url) === true) {
            return false;
        }

        // Extract UUID from URL.
        if (preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                $url,
                $matches
            ) !== 1
        ) {
            return false;
        }

        $uuid = $matches[1];

        try {
            // Map schema config key to ZGW resource name for mapping lookup.
            $resourceMap = [
                'document_type_schema' => 'informatieobjecttypen',
                'decision_type_schema' => 'besluittypen',
                'case_type_schema'     => 'zaaktypen',
            ];

            $targetResource = $resourceMap[$schemaKey] ?? null;
            if ($targetResource === null) {
                return true;
            }

            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $targetResource);
            if ($mappingConfig === null) {
                return true;
            }

            $object = $this->zgwService->getObjectService()->find(
                id: $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );

            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            // Must be published (isDraft=false / concept=false).
            $isDraft = $objectData['isDraft'] ?? ($objectData['concept'] ?? true);
            if ($isDraft === true || $isDraft === 'true' || $isDraft === '1' || $isDraft === 1) {
                return false;
            }

            // Check date validity: beginGeldigheid <= today.
            $begin = $objectData['validFrom'] ?? ($objectData['beginGeldigheid'] ?? null);
            if ($begin !== null && $begin !== '' && $begin > $today) {
                return false;
            }

            // Check date validity: eindeGeldigheid >= today (or no end date).
            $end = $objectData['validUntil'] ?? ($objectData['eindeGeldigheid'] ?? null);
            if ($end !== null && $end !== '' && $end < $today) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // If we can't look up the object, exclude the URL.
            return false;
        }//end try
    }//end isUrlValid()
}//end class
