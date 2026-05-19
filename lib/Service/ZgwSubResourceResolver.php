<?php

/**
 * ZGW Sub-Resource Resolver Service
 *
 * Resolves sub-resource state lookups such as whether a zaak is closed or
 * whether its parent zaaktype is still in draft, extracted from ZgwService.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for resolving ZGW sub-resource state (zaak closed, zaaktype draft).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */
class ZgwSubResourceResolver
{
    /**
     * Constructor.
     *
     * @param object|null       $objectService     The OpenRegister ObjectService (nullable)
     * @param ZgwMappingService $zgwMappingService The ZGW mapping service
     * @param LoggerInterface   $logger            The logger
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function __construct(
        private readonly ?object $objectService,
        private readonly ZgwMappingService $zgwMappingService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve whether a zaak is closed (has einddatum set).
     *
     * @param string $resource     The ZGW resource name
     * @param array  $existingData The existing object data
     *
     * @return bool|null True if closed, false if open, null if N/A
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — sub-resource lookup with multiple guard clauses
     * @SuppressWarnings(PHPMD.NPathComplexity)      — sub-resource lookup with multiple guard clauses
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function resolveZaakClosed(string $resource, array $existingData): ?bool
    {
        if ($resource === 'zaken') {
            $endDate = $existingData['endDate'] ?? ($existingData['einddatum'] ?? null);
            return $endDate !== null && $endDate !== '';
        }

        $zaakSubResources = [
            'statussen',
            'resultaten',
            'rollen',
            'zaakeigenschappen',
            'zaakinformatieobjecten',
            'zaakobjecten',
            'klantcontacten',
        ];
        if (in_array($resource, $zaakSubResources, true) === false) {
            return null;
        }

        $zaakUuid = $existingData['case'] ?? ($existingData['zaak'] ?? null);
        if ($zaakUuid === null || $zaakUuid === '') {
            return null;
        }

        if (preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaakUuid,
                $matches
            ) === 1
        ) {
            $zaakUuid = $matches[1];
        }

        try {
            $zaakConfig = $this->zgwMappingService->getMapping('zaak');
            if ($zaakConfig === null) {
                return null;
            }

            $zaak = $this->objectService->find(
                $zaakUuid,
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if ($zaak === null) {
                return null;
            }

            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            $endDate = $zaakData['endDate'] ?? ($zaakData['einddatum'] ?? null);

            return $endDate !== null && $endDate !== '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not resolve zaak closed status: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end resolveZaakClosed()

    /**
     * Resolve whether a zaak is closed from a request body (for sub-resource creation).
     *
     * @param string $resource The ZGW resource name
     * @param array  $body     The request body
     *
     * @return bool|null True if closed, false if open, null if N/A
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — sub-resource lookup with multiple guard clauses
     * @SuppressWarnings(PHPMD.NPathComplexity)      — sub-resource lookup with multiple guard clauses
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function resolveZaakClosedFromBody(string $resource, array $body): ?bool
    {
        if ($resource === 'zaken') {
            return null;
        }

        $zaakSubResources = [
            'statussen',
            'resultaten',
            'rollen',
            'zaakeigenschappen',
            'zaakinformatieobjecten',
            'zaakobjecten',
            'klantcontacten',
        ];
        if (in_array($resource, $zaakSubResources, true) === false) {
            return null;
        }

        $zaakUrl = $body['zaak'] ?? null;
        if ($zaakUrl === null || $zaakUrl === '') {
            return null;
        }

        if (preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaakUrl,
                $matches
            ) !== 1
        ) {
            return null;
        }

        $zaakUuid = $matches[1];

        try {
            $zaakConfig = $this->zgwMappingService->getMapping('zaak');
            if ($zaakConfig === null) {
                return null;
            }

            $zaak = $this->objectService->find(
                $zaakUuid,
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if ($zaak === null) {
                return null;
            }

            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            $endDate = $zaakData['endDate'] ?? ($zaakData['einddatum'] ?? null);

            return $endDate !== null && $endDate !== '';
        } catch (\Throwable $e) {
            return null;
        }//end try
    }//end resolveZaakClosedFromBody()

    /**
     * Resolve whether the parent zaaktype is in draft (concept) state.
     *
     * @param string $resource     The ZGW resource name
     * @param array  $existingData The existing sub-resource object data
     *
     * @return bool|null True if draft, false if published, null if N/A
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — sub-resource lookup with multiple guard clauses
     * @SuppressWarnings(PHPMD.NPathComplexity)      — sub-resource lookup with multiple guard clauses
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function resolveParentZaaktypeDraft(string $resource, array $existingData): ?bool
    {
        $subResources = [
            'statustypen',
            'resultaattypen',
            'roltypen',
            'eigenschappen',
            'zaaktype-informatieobjecttypen',
        ];

        if (in_array($resource, $subResources, true) === false) {
            return null;
        }

        $zaaktypeUuid = $existingData['caseType'] ?? ($existingData['zaaktype'] ?? null);
        if ($zaaktypeUuid === null || $zaaktypeUuid === '') {
            return null;
        }

        if (preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaaktypeUuid,
                $matches
            ) === 1
        ) {
            $zaaktypeUuid = $matches[1];
        }

        try {
            $zaaktypeConfig = $this->zgwMappingService->getMapping('zaaktype');
            if ($zaaktypeConfig === null) {
                return null;
            }

            $zaaktype = $this->objectService->find(
                $zaaktypeUuid,
                register: $zaaktypeConfig['sourceRegister'],
                schema: $zaaktypeConfig['sourceSchema']
            );
            if ($zaaktype === null) {
                return null;
            }

            if (is_array($zaaktype) === true) {
                $ztData = $zaaktype;
            } else {
                $ztData = $zaaktype->jsonSerialize();
            }

            $isDraft = $ztData['isDraft'] ?? ($ztData['concept'] ?? true);

            if ($isDraft === false || $isDraft === 'false' || $isDraft === '0' || $isDraft === 0) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not resolve parent zaaktype draft status: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end resolveParentZaaktypeDraft()

    /**
     * Resolve parent zaaktype draft status from a request body (for sub-resource creation).
     *
     * Extracts the zaaktype URL/UUID from the body and looks up whether
     * the zaaktype is still in draft (concept) state.
     *
     * @param string $resource The ZGW resource name
     * @param array  $body     The request body (Dutch field names)
     *
     * @return bool|null True if draft, false if published, null if N/A
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — sub-resource lookup with multiple guard clauses
     * @SuppressWarnings(PHPMD.NPathComplexity)      — sub-resource lookup with multiple guard clauses
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function resolveParentZaaktypeDraftFromBody(string $resource, array $body): ?bool
    {
        $subResources = [
            'statustypen',
            'resultaattypen',
            'roltypen',
            'eigenschappen',
            'zaaktype-informatieobjecttypen',
        ];

        if (in_array($resource, $subResources, true) === false) {
            return null;
        }

        $zaaktypeRef = $body['zaaktype'] ?? null;
        if ($zaaktypeRef === null || $zaaktypeRef === '') {
            return null;
        }

        // Extract UUID from URL or plain UUID.
        if (preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaaktypeRef,
                $matches
            ) !== 1
        ) {
            return null;
        }

        $zaaktypeUuid = $matches[1];

        try {
            $zaaktypeConfig = $this->zgwMappingService->getMapping('zaaktype');
            if ($zaaktypeConfig === null) {
                return null;
            }

            $zaaktype = $this->objectService->find(
                $zaaktypeUuid,
                register: $zaaktypeConfig['sourceRegister'],
                schema: $zaaktypeConfig['sourceSchema']
            );
            if ($zaaktype === null) {
                return null;
            }

            if (is_array($zaaktype) === true) {
                $ztData = $zaaktype;
            } else {
                $ztData = $zaaktype->jsonSerialize();
            }

            $isDraft = $ztData['isDraft'] ?? ($ztData['concept'] ?? true);

            if ($isDraft === false || $isDraft === 'false' || $isDraft === '0' || $isDraft === 0) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not resolve parent zaaktype draft from body: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end resolveParentZaaktypeDraftFromBody()
}//end class
