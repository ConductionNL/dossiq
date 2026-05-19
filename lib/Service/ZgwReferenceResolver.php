<?php

/**
 * ZGW Reference Resolver
 *
 * Resolves ZGW type reference arrays (informatieobjecttypen, besluittypen,
 * deelzaaktypen, gerelateerdeZaaktypen) from omschrijving/identificatie to
 * UUIDs. Extracted from ZgwZtcRulesService to reduce its class complexity.
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
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Resolves ZGW type reference arrays from omschrijving/identificatie to UUIDs.
 *
 * Extends ZgwRulesBase to inherit shared utility methods (extractUuid,
 * findAllObjectsByField, etc.) without duplicating them.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */
class ZgwReferenceResolver extends ZgwRulesBase
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          PSR-3 logger.
     * @param SettingsService $settingsService Settings service for schema keys.
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        SettingsService $settingsService,
    ) {
        parent::__construct(logger: $logger, settingsService: $settingsService);
    }//end __construct()

    /**
     * Resolve an array of type references from omschrijving/identificatie to UUIDs.
     *
     * For each entry in body[$field]:
     * - URL containing UUID → extract and store UUID
     * - Name/identificatie string → look up by $lookupField in OpenRegister
     * - Bare UUID → keep as-is
     *
     * @param array  $body        The request body
     * @param string $field       The body field containing the array of references
     * @param string $schemaKey   The settings key for the target schema
     * @param string $lookupField The OR field to search by name
     *
     * @return array The body with the resolved reference array
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-4
     */
    public function resolveTypeReferences(
        array $body,
        string $field,
        string $schemaKey,
        string $lookupField
    ): array {
        if (isset($body[$field]) === false || is_array($body[$field]) === false
            || $this->objectService === null
        ) {
            return $body;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->settingsService->getConfigValue(key: $schemaKey);

        if (empty($register) === true || empty($schema) === true) {
            return $body;
        }

        $resolved = [];
        foreach ($body[$field] as $ref) {
            if (is_string($ref) === false || $ref === '') {
                continue;
            }

            // URL containing a UUID — extract and store just the UUID.
            if (str_starts_with($ref, 'http://') === true
                || str_starts_with($ref, 'https://') === true
            ) {
                $urlUuid = $this->extractUuid(url: $ref);
                if ($urlUuid !== null) {
                    $resolved[] = $urlUuid;
                    continue;
                }
            }

            // Search by omschrijving/identificatie in OpenRegister.
            $foundIds = $this->findAllObjectsByField(
                register: $register,
                schema: $schema,
                field: $lookupField,
                value: $ref
            );
            if (empty($foundIds) === false) {
                foreach ($foundIds as $id) {
                    $resolved[] = $id;
                }

                continue;
            }

            // Fallback: if name lookup found nothing and it looks like a UUID, use as-is.
            $bareUuid = $this->extractUuid(url: $ref);
            if ($bareUuid !== null) {
                $resolved[] = $bareUuid;
            }
        }//end foreach

        $body[$field] = $resolved;

        return $body;
    }//end resolveTypeReferences()

    /**
     * Resolve gerelateerdeZaaktypen reference array from identificatie to UUIDs.
     *
     * @param array $body The request body
     *
     * @return array The body with resolved zaaktype references
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-4
     */
    public function resolveGerelateerdeZaaktypen(array $body): array
    {
        if (isset($body['gerelateerdeZaaktypen']) === false
            || is_array($body['gerelateerdeZaaktypen']) === false
            || $this->objectService === null
        ) {
            return $body;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->settingsService->getConfigValue(key: 'case_type_schema');

        if (empty($register) === true || empty($schema) === true) {
            return $body;
        }

        $resolved = [];
        foreach ($body['gerelateerdeZaaktypen'] as $rel) {
            $zaaktypeRef = $rel['zaaktype'] ?? '';
            if ($zaaktypeRef === '' || is_string($zaaktypeRef) === false) {
                continue;
            }

            if (str_starts_with($zaaktypeRef, 'http://') === true
                || str_starts_with($zaaktypeRef, 'https://') === true
            ) {
                $resolved[] = $rel;
                continue;
            }

            $foundIds = $this->findAllObjectsByField(
                register: $register,
                schema: $schema,
                field: 'identifier',
                value: $zaaktypeRef
            );
            foreach ($foundIds as $id) {
                $entry = $rel;
                $entry['zaaktype'] = $id;
                $resolved[]        = $entry;
            }
        }//end foreach

        $body['gerelateerdeZaaktypen'] = $resolved;

        return $body;
    }//end resolveGerelateerdeZaaktypen()
}//end class
