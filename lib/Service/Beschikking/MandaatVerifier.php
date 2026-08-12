<?php

/**
 * Procest MandaatVerifier.
 *
 * Mandaat-verificatie for the akkoord step of the beschikking lifecycle:
 * which mandaatregeling applies to a zaaktype, which niveau the approver
 * holds, and whether that niveau actually covers this decision — its
 * zaaktype, its beschikkingType, and its bedrag against the level's
 * `tot_bedrag` ceiling.
 *
 * Split out of BeschikkingService so that service keeps only the lifecycle
 * orchestration: the authority rules — the only thing standing between an
 * approver and a legally binding besluit — live here and nowhere else. A
 * level with no explicit ceiling is unlimited; a level whose zaaktypes or
 * beschikkingTypes list is non-empty must contain this decision's value.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Resolves and verifies the mandaat covering a beschikking approval.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class MandaatVerifier
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings/config service.
     * @param LoggerInterface $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Verify whether a mandaat covers a decision. [T14 verifyMandaat]
     *
     * @param array<string, mixed> $regeling        The mandaatRegeling object.
     * @param string               $niveau          The proposed approver level.
     * @param float                $bedrag          The decision bedrag.
     * @param string               $beschikkingType The decision type.
     * @param string               $zaaktype        The case type.
     *
     * @return bool True when the level may sign this decision within its limit.
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function verifyMandaat(
        array $regeling,
        string $niveau,
        float $bedrag,
        string $beschikkingType,
        string $zaaktype,
    ): bool {
        foreach ((array) ($regeling['mandateGroups'] ?? []) as $groep) {
            if ((string) ($groep['niveau'] ?? '') !== $niveau) {
                continue;
            }

            $zaaktypes = (array) ($groep['zaaktypes'] ?? []);
            if (empty($zaaktypes) === false && in_array($zaaktype, $zaaktypes, true) === false) {
                continue;
            }

            $types = (array) ($groep['beschikkingTypes'] ?? []);
            if (empty($types) === false && in_array($beschikkingType, $types, true) === false) {
                continue;
            }

            $limit = ($groep['tot_bedrag'] ?? null);
            if ($limit === null) {
                return true;
            }

            if ($bedrag <= (float) $limit) {
                return true;
            }
        }//end foreach

        return false;
    }//end verifyMandaat()

    /**
     * Resolve the mandaatRegeling applicable to a zaaktype.
     *
     * @param string $zaaktype The case type slug.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function resolveMandaatRegeling(string $zaaktype): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(key: 'mandaat_regeling_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $regelingen = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: []
            );
        } catch (\Throwable $e) {
            $this->logger->error('BeschikkingService: resolveMandaatRegeling failed', ['exception' => $e->getMessage()]);
            return [];
        }

        foreach ((array) $regelingen as $regeling) {
            $arr = $this->toArray(value: $regeling);
            foreach ((array) ($arr['mandateGroups'] ?? []) as $groep) {
                $zaaktypes = (array) ($groep['zaaktypes'] ?? []);
                if ($zaaktype === '' || in_array($zaaktype, $zaaktypes, true) === true) {
                    return $arr;
                }
            }
        }

        return [];
    }//end resolveMandaatRegeling()

    /**
     * Resolve the highest niveau a user is authorised for, verifying the mandaat.
     *
     * The user-to-niveau mapping is supplied out-of-band (the gemeente maps
     * approvers to groups). For the build we accept the niveau encoded in the
     * approver UID prefix (e.g. `afdelingsmanager-wmo-15`) and verify it covers
     * the beschikking. Returns null when no covering niveau is found.
     *
     * @param array<string, mixed> $regeling    The mandaatRegeling.
     * @param array<string, mixed> $beschikking The beschikking.
     * @param string               $akkoordDoor The approver UID.
     *
     * @return string|null
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function resolveNiveauForUser(array $regeling, array $beschikking, string $akkoordDoor): ?string
    {
        $bedrag          = (float) ($beschikking['legesbedrag'] ?? 0);
        $beschikkingType = (string) ($beschikking['beschikkingType'] ?? '');
        $zaaktype        = (string) ($beschikking['zaaktype'] ?? '');

        foreach ((array) ($regeling['mandateGroups'] ?? []) as $groep) {
            $niveau = (string) ($groep['niveau'] ?? '');
            if ($niveau === '' || str_starts_with($akkoordDoor, $niveau) === false) {
                continue;
            }

            $covered = $this->verifyMandaat(
                regeling: $regeling,
                niveau: $niveau,
                bedrag: $bedrag,
                beschikkingType: $beschikkingType,
                zaaktype: $zaaktype,
            );
            if ($covered === true) {
                return $niveau;
            }
        }

        return null;
    }//end resolveNiveauForUser()

    /**
     * Normalise an ObjectService return value to an array.
     *
     * @param mixed $value The entity, array, or JsonSerializable.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialised = $value->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()
}//end class
