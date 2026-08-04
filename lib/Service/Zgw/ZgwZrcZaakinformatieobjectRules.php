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
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function validateZioInformatieobjecttype(string $zaakUrl, string $ioUrl): ?array
    {
        // Get the informatieobject to find its informatieobjecttype.
        $docTypeId = $this->resolveDocumentTypeId(ioUrl: $ioUrl);
        if ($docTypeId === null) {
            return null;
        }

        // Get the zaak's zaaktype.
        $zaaktypeUuid = $this->resolveCaseTypeUuid(zaakUrl: $zaakUrl);
        if ($zaaktypeUuid === null) {
            return null;
        }

        // Check if a ZaakType-InformatieObjectType record links this zaaktype
        // to the document's informatieobjecttype.
        $docTypeUuid = $this->extractUuid(url: $docTypeId);
        if ($docTypeUuid === null) {
            return null;
        }

        $isMissing = $this->isZaaktypeInformatieobjecttypeMissing(
            zaaktypeUuid: $zaaktypeUuid,
            docTypeUuid: $docTypeUuid
        );
        if ($isMissing === false) {
            return null;
        }

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
    }//end validateZioInformatieobjecttype()

    /**
     * Resolve the informatieobjecttype reference carried by an informatieobject.
     *
     * @param string $ioUrl The informatieobject URL
     *
     * @return string|null The raw documentType reference, or null if unresolvable
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function resolveDocumentTypeId(string $ioUrl): ?string
    {
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

        return (string) $docTypeId;
    }//end resolveDocumentTypeId()

    /**
     * Resolve the zaaktype UUID a zaak is registered under.
     *
     * @param string $zaakUrl The zaak URL
     *
     * @return string|null The zaaktype UUID, or null if unresolvable
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function resolveCaseTypeUuid(string $zaakUrl): ?string
    {
        $zaakUuid = $this->extractUuid(url: $zaakUrl);
        if ($zaakUuid === null) {
            return null;
        }

        $zaakData = $this->findBySchemaKey(uuid: $zaakUuid, schemaKey: 'case_schema');
        if ($zaakData === null) {
            return null;
        }

        $zaaktypeId = $zaakData['caseType'] ?? '';
        return $this->extractUuid(url: (string) $zaaktypeId);
    }//end resolveCaseTypeUuid()

    /**
     * Check whether the ZaakType-InformatieObjectType link is provably absent (zrc-017).
     *
     * Returns true only when a lookup actually ran and found nothing. An
     * unconfigured register/schema or a failing query is "not established",
     * not "absent", and yields false so the caller raises no error — an
     * unavailable lookup must never be reported to the client as a rule breach.
     *
     * @param string $zaaktypeUuid The zaaktype UUID
     * @param string $docTypeUuid  The informatieobjecttype UUID
     *
     * @return bool True when the link is provably missing
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function isZaaktypeInformatieobjecttypeMissing(string $zaaktypeUuid, string $docTypeUuid): bool
    {
        $ziotSchemaId = $this->settingsService->getConfigValue(key: 'zaaktype_informatieobjecttype_schema');
        $register     = $this->settingsService->getConfigValue(key: 'register');
        if ($ziotSchemaId === '' || $register === '') {
            return false;
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
            return false;
        }

        return $found === false;
    }//end isZaaktypeInformatieobjecttypeMissing()

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
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function checkZioImmutability(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body = $result['enrichedBody'];

        // Zrc-004: zaak is immutable.
        $zaakChanged = $this->isRelationFieldChanged(
            body: $body,
            existingObject: $existingObject,
            field: 'zaak',
            storedKey: 'case'
        );
        if ($zaakChanged === true) {
            return $this->fieldImmutableError(fieldName: 'zaak');
        }

        // Zrc-004: informatieobject is immutable.
        $ioChanged = $this->isRelationFieldChanged(
            body: $body,
            existingObject: $existingObject,
            field: 'informatieobject',
            storedKey: 'document'
        );
        if ($ioChanged === true) {
            return $this->fieldImmutableError(fieldName: 'informatieobject');
        }

        return $result;
    }//end checkZioImmutability()

    /**
     * Check whether a request body changes an immutable relation field (zrc-004).
     *
     * The stored object may carry the relation under the procest-side key
     * ($storedKey) or under the ZGW field name, so both are consulted in that
     * order. Both sides are reduced to a UUID before comparing, so the same
     * relation expressed as a bare UUID and as a full URL is not a change.
     * An unresolvable UUID on either side is not treated as a change.
     *
     * @param array  $body           The request body
     * @param array  $existingObject The stored object data
     * @param string $field          The ZGW field name in the body
     * @param string $storedKey      The procest-side key on the stored object
     *
     * @return bool True when the field is present and points at a different object
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function isRelationFieldChanged(
        array $body,
        array $existingObject,
        string $field,
        string $storedKey
    ): bool {
        if (isset($body[$field]) === false) {
            return false;
        }

        $existing = $existingObject[$storedKey] ?? ($existingObject[$field] ?? '');
        $newUuid  = $this->extractUuid(url: $body[$field]);

        $existingId = $existing;
        if (is_string($existing) === true) {
            $existingId = $this->extractUuid(url: $existing);
        }

        return ($existingId !== null && $newUuid !== null && $newUuid !== $existingId);
    }//end isRelationFieldChanged()
}//end class
