<?php

/**
 * Procest ZGW ZRC ZaakInformatieObject rules.
 *
 * The Zaken API rules for the zaakinformatieobjecten sub-resource — the
 * document↔zaak relation — split out of ZgwZrcRulesService. That service owns
 * the zaak itself and its statussen/resultaten/rollen/eigenschappen; this one
 * owns the one sub-resource whose rules reach across into the Documenten and
 * Catalogi registers, and whose fields are immutable after creation.
 *
 * Business rules implemented:
 *
 * - zrc-003: Valideren informatieobject op ZaakInformatieObject
 * - zrc-004: Zetten relatieinformatie op ZaakInformatieObject; zaak en
 *            informatieobject zijn onveranderlijk na aanmaken
 * - zrc-017: Valideren informatieobjecttype bij Zaak.zaaktype
 *
 * @category Service
 * @package  OCA\Procest\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zgw;

use OCA\Procest\Service\ZgwRulesBase;

/**
 * ZRC zaakinformatieobjecten validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwZrcZaakinformatieobjectRules extends ZgwRulesBase
{
    /**
     * Rules for creating a ZaakInformatieObject (POST /zaken/v1/zaakinformatieobjecten).
     *
     * Implements:
     * - zrc-003: Validate informatieobject URL exists.
     * - zrc-004: Set aardRelatieWeergave and registratiedatum.
     * - zrc-017: Validate informatieobjecttype belongs to Zaak.zaaktype.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function rulesZaakinformatieobjectenCreate(array $body): array
    {
        // Zrc-003: Validate informatieobject URL exists.
        $ioUrl = $body['informatieobject'] ?? '';
        if ($ioUrl !== '') {
            $error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-017: Validate informatieobjecttype belongs to zaak's zaaktype.
        $zaakUrl = $body['zaak'] ?? '';
        if ($ioUrl !== '' && $zaakUrl !== '' && $this->objectService !== null) {
            $error = $this->validateZioInformatieobjecttype(zaakUrl: $zaakUrl, ioUrl: $ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-004: Set aardRelatieWeergave and registratiedatum.
        $body['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';
        $body['registratiedatum']    = date('Y-m-d');

        return $this->isValid(body: $body);
    }//end rulesZaakinformatieobjectenCreate()

    /**
     * Rules for updating a ZaakInformatieObject (PUT).
     *
     * Implements:
     * - zrc-004: Zaak and informatieobject fields are immutable; aardRelatieWeergave is fixed.
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing ZIO data
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function rulesZaakinformatieobjectenUpdate(array $body, ?array $existingObject=null): array
    {
        $result = $this->checkZioImmutability(result: $this->isValid(body: $body), existingObject: $existingObject);
        if ($result['valid'] === false) {
            return $result;
        }

        $body = $result['enrichedBody'];
        $body['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';

        return $this->isValid(body: $body);
    }//end rulesZaakinformatieobjectenUpdate()

    /**
     * Rules for patching a ZaakInformatieObject (PATCH).
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing ZIO data
     *
     * @return array The validation result
     *
     * @see rulesZaakinformatieobjectenUpdate() Same immutability rules apply.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function rulesZaakinformatieobjectenPatch(array $body, ?array $existingObject=null): array
    {
        return $this->rulesZaakinformatieobjectenUpdate(body: $body, existingObject: $existingObject);
    }//end rulesZaakinformatieobjectenPatch()

    /**
     * Validate ZIO informatieobjecttype belongs to zaak's zaaktype (zrc-017).
     *
     * The informatieobjecttype of the linked informatieobject must appear
     * in Zaak.zaaktype.informatieobjecttypen.
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return array|null Validation error, or null if valid
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — ZGW cross-register validation
     * @SuppressWarnings(PHPMD.NPathComplexity)      — ZGW cross-register validation
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function validateZioInformatieobjecttype(string $zaakUrl, string $ioUrl): ?array
    {
        // Get the informatieobject to find its informatieobjecttype.
        $ioUuid = $this->extractUuid(url: $ioUrl);
        if ($ioUuid === null) {
            return null;
        }

        $ioData = $this->findBySchemaKey(uuid: $ioUuid, schemaKey: 'document_schema');
        if ($ioData === null) {
            return null;
        }

        $docTypeId = $ioData['documentType'] ?? '';
        if (empty($docTypeId) === true) {
            return null;
        }

        // Get the zaak's zaaktype.
        $zaakUuid = $this->extractUuid(url: $zaakUrl);
        if ($zaakUuid === null) {
            return null;
        }

        $zaakData = $this->findBySchemaKey(uuid: $zaakUuid, schemaKey: 'case_schema');
        if ($zaakData === null) {
            return null;
        }

        $zaaktypeId   = $zaakData['caseType'] ?? '';
        $zaaktypeUuid = $this->extractUuid(url: (string) $zaaktypeId);
        if ($zaaktypeUuid === null) {
            return null;
        }

        // Check if a ZaakType-InformatieObjectType record links this zaaktype
        // to the document's informatieobjecttype.
        $docTypeUuid = $this->extractUuid(url: (string) $docTypeId);
        if ($docTypeUuid === null) {
            return null;
        }

        $ziotSchemaId = $this->settingsService->getConfigValue(key: 'zaaktype_informatieobjecttype_schema');
        $register     = $this->settingsService->getConfigValue(key: 'register');
        if ($ziotSchemaId === '' || $register === '') {
            return null;
        }

        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: ['zaaktype' => $zaaktypeUuid, 'informatieobjecttype' => $docTypeUuid, '_limit' => 1],
                register: $register,
                schema: $ziotSchemaId
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);
            $found  = empty($result['results'] ?? []) === false;
        } catch (\Throwable $e) {
            return null;
        }

        if ($found === false) {
            $detail = 'Het informatieobjecttype van het informatieobject hoort niet bij het zaaktype van de zaak.';
            return $this->error(
                status: 400,
                detail: $detail,
                invalidParams: [$this->fieldError(
                    fieldName: 'nonFieldErrors',
                    code: 'missing-zaaktype-informatieobjecttype-relation',
                    reason: $detail
                )
                ]
            );
        }

        return null;
    }//end validateZioInformatieobjecttype()

    /**
     * Check ZaakInformatieObject field immutability (zrc-004).
     *
     * Zaak and informatieobject fields are immutable after creation.
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     *
     * @return array The updated validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — immutability check on multiple fields
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function checkZioImmutability(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body = $result['enrichedBody'];

        // Zrc-004: zaak is immutable.
        if (isset($body['zaak']) === true) {
            $existingZaak = $existingObject['case'] ?? ($existingObject['zaak'] ?? '');
            $newZaakUuid  = $this->extractUuid(url: $body['zaak']);

            $existZaakId = $existingZaak;
            if (is_string($existingZaak) === true) {
                $existZaakId = $this->extractUuid(url: $existingZaak);
            }

            if ($existZaakId !== null && $newZaakUuid !== null && $newZaakUuid !== $existZaakId) {
                return $this->fieldImmutableError(fieldName: 'zaak');
            }
        }

        // Zrc-004: informatieobject is immutable.
        if (isset($body['informatieobject']) === true) {
            $existingIo = $existingObject['document'] ?? ($existingObject['informatieobject'] ?? '');
            $newIoUuid  = $this->extractUuid(url: $body['informatieobject']);

            $existIoId = $existingIo;
            if (is_string($existingIo) === true) {
                $existIoId = $this->extractUuid(url: $existingIo);
            }

            if ($existIoId !== null && $newIoUuid !== null && $newIoUuid !== $existIoId) {
                return $this->fieldImmutableError(fieldName: 'informatieobject');
            }
        }

        return $result;
    }//end checkZioImmutability()
}//end class
