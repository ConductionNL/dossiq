<?php

/**
 * Procest brondatumArchiefprocedure validator.
 *
 * The cross-field constraints on a resultaattype's nested
 * `brondatumArchiefprocedure` value object (ztc-003 to ztc-008). Which of
 * datumkenmerk / objecttype / registratie / procestermijn are required and
 * which are forbidden is decided entirely by `afleidingswijze`, and ztc-003
 * additionally cross-checks that afleidingswijze against the selectielijst-
 * klasse's own procestermijn. That is a self-contained rule table over one
 * nested object, so it lives here rather than inside the resultaattype rules
 * that happen to invoke it.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
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
 * Validates brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrondatumArchiefValidator extends ZgwRulesBase
{
    /**
     * Afleidingswijze values that REQUIRE datumkenmerk (ztc-004).
     *
     * @var array<string>
     */
    private const AFLEIDINGSWIJZE_REQUIRES_DATUMKENMERK = [
        'eigenschap',
        'zaakobject',
        'ander_datumkenmerk',
    ];

    /**
     * Afleidingswijze values that REQUIRE objecttype (ztc-006).
     *
     * @var array<string>
     */
    private const AFLEIDINGSWIJZE_REQUIRES_OBJECTTYPE = [
        'zaakobject',
        'ander_datumkenmerk',
    ];

    /**
     * Afleidingswijze values that FORBID einddatumBekend=true (ztc-005).
     *
     * @var array<string>
     */
    private const AFLEIDINGSWIJZE_FORBIDS_EINDDATUM_BEKEND = [
        'afgehandeld',
        'termijn',
    ];

    /**
     * Validate brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
     *
     * @param array      $archief           The brondatumArchiefprocedure data
     * @param array|null $selectielijstData The fetched selectielijstklasse data
     *
     * @return array<array{name: string, code: string, reason: string}> Validation errors
     *
     * @spec openspec/specs/zgw-business-rules-compliance/spec.md
     */
    public function validate(array $archief, ?array $selectielijstData): array
    {
        $afleidingswijze = $archief['afleidingswijze'] ?? '';
        $errors          = [];

        // Ztc-004: datumkenmerk required/forbidden.
        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.datumkenmerk',
                fieldValue: ($archief['datumkenmerk'] ?? ''),
                requiredFor: self::AFLEIDINGSWIJZE_REQUIRES_DATUMKENMERK
            )
        );

        // Ztc-005: einddatumBekend must be false for afgehandeld/termijn.
        $einddatumBekend = $archief['einddatumBekend'] ?? false;
        if (($einddatumBekend === true || $einddatumBekend === 'true')
            && in_array($afleidingswijze, self::AFLEIDINGSWIJZE_FORBIDS_EINDDATUM_BEKEND, true) === true
        ) {
            $errors[] = $this->fieldError(
                fieldName: 'brondatumArchiefprocedure.einddatumBekend',
                code: 'must-be-empty',
                reason: "einddatumBekend moet false zijn voor afleidingswijze \"{$afleidingswijze}\"."
            );
        }

        // Ztc-006: objecttype required/forbidden.
        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.objecttype',
                fieldValue: ($archief['objecttype'] ?? ''),
                requiredFor: self::AFLEIDINGSWIJZE_REQUIRES_OBJECTTYPE
            )
        );

        // Ztc-007: registratie required only for ander_datumkenmerk.
        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.registratie',
                fieldValue: ($archief['registratie'] ?? ''),
                requiredFor: ['ander_datumkenmerk']
            )
        );

        // Ztc-008: procestermijn required only for termijn.
        $procestermijn = $archief['procestermijn'] ?? null;

        $ptValue = '';
        if (is_string($procestermijn) === true) {
            $ptValue = $procestermijn;
        }

        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.procestermijn',
                fieldValue: $ptValue,
                requiredFor: ['termijn']
            )
        );

        // Ztc-003: Validate afleidingswijze against selectielijstklasse.procestermijn.
        if ($selectielijstData !== null) {
            $slProcestermijn = $selectielijstData['procestermijn'] ?? null;
            $ptCheck         = $this->checkProcestermijnCompatibility(
                afleidingswijze: $afleidingswijze,
                procestermijn: $slProcestermijn
            );
            if ($ptCheck !== null) {
                $errors[] = $ptCheck;
            }
        }

        return $errors;
    }//end validate()

    /**
     * Validate field presence based on afleidingswijze (required vs forbidden).
     *
     * @param string        $afleidingswijze The afleidingswijze value
     * @param string        $fieldName       The full field path for error reporting
     * @param string        $fieldValue      The field value
     * @param array<string> $requiredFor     Afleidingswijze values that require this field
     *
     * @return array<array{name: string, code: string, reason: string}> Validation errors
     *
     * @spec openspec/specs/zgw-business-rules-compliance/spec.md
     */
    private function validateFieldPresence(
        string $afleidingswijze,
        string $fieldName,
        string $fieldValue,
        array $requiredFor
    ): array {
        $hasValue = ($fieldValue !== '' && $fieldValue !== null);

        $isRequired = in_array($afleidingswijze, $requiredFor, true);

        if ($isRequired === true && $hasValue === false) {
            return [
                $this->fieldError(
                    fieldName: $fieldName,
                    code: 'required',
                    reason: "{$fieldName} is vereist voor afleidingswijze \"{$afleidingswijze}\"."
                ),
            ];
        }

        if ($isRequired === false && $hasValue === true) {
            return [
                $this->fieldError(
                    fieldName: $fieldName,
                    code: 'must-be-empty',
                    reason: "{$fieldName} mag niet ingevuld zijn voor afleidingswijze \"{$afleidingswijze}\"."
                ),
            ];
        }

        return [];
    }//end validateFieldPresence()

    /**
     * Check afleidingswijze compatibility with selectielijstklasse.procestermijn (ztc-003).
     *
     * @param string      $afleidingswijze The afleidingswijze value
     * @param string|null $procestermijn   The selectielijstklasse procestermijn value
     *
     * @return array|null Field error array, or null if compatible
     *
     * @spec openspec/specs/zgw-business-rules-compliance/spec.md
     */
    private function checkProcestermijnCompatibility(
        string $afleidingswijze,
        ?string $procestermijn
    ): ?array {
        if ($procestermijn === 'nihil' && $afleidingswijze !== 'afgehandeld') {
            return $this->fieldError(
                fieldName: 'nonFieldErrors',
                code: 'invalid-afleidingswijze-for-procestermijn',
                reason: "Afleidingswijze \"{$afleidingswijze}\" is niet geldig".' bij selectielijstklasse met procestermijn "nihil".'
            );
        }

        if ($procestermijn === 'bestaansduur_procesobject' && $afleidingswijze !== 'termijn') {
            $reason = "Afleidingswijze \"{$afleidingswijze}\" is niet geldig"
                .' bij selectielijstklasse met procestermijn "bestaansduur_procesobject".';
            return $this->fieldError(
                fieldName: 'nonFieldErrors',
                code: 'invalid-afleidingswijze-for-procestermijn',
                reason: $reason
            );
        }

        if (($procestermijn === '' || $procestermijn === null) && $afleidingswijze === 'termijn') {
            $reason = 'brondatumArchiefprocedure.procestermijn is vereist voor'
                .' afleidingswijze "termijn" maar selectielijstklasse heeft geen procestermijn.';
            return $this->fieldError(
                fieldName: 'brondatumArchiefprocedure.procestermijn',
                code: 'required',
                reason: $reason
            );
        }

        return null;
    }//end checkProcestermijnCompatibility()
}//end class
