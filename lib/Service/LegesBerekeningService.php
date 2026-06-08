<?php

/**
 * Procest Leges Berekening Service
 *
 * Read-side service for persisted leges calculations: fetch the current
 * calculation for a case, build the structured audit trail, and delegate
 * per-case access control to CaseSharingService so leges reads honour the
 * same authorisation as the rest of the case (IDOR-safe, ADR-005).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-008
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Read-side service for leges calculations and their audit trail.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LegesBerekeningService
{
    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService    Settings + ObjectService access.
     * @param CaseSharingService $caseSharingService Per-case access control.
     * @param LoggerInterface    $logger             Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly CaseSharingService $caseSharingService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a user may access the calculation of a given case.
     *
     * @param string $caseId The case UUID.
     * @param string $userId The user id.
     *
     * @return bool
     */
    public function userCanAccessCase(string $caseId, string $userId): bool
    {
        return $this->caseSharingService->canUserAccessCase(caseId: $caseId, userId: $userId);
    }//end userCanAccessCase()

    /**
     * Get the most recent calculation for a case, or null when none exists.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<string, mixed>|null
     */
    public function getForCase(string $caseId): ?array
    {
        $rows = $this->loadForCase(caseId: $caseId);
        if ($rows === []) {
            return null;
        }

        // Most recent by berekendeOp (then last in list as a tiebreaker).
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['berekendeOp'] ?? ''), (string) ($b['berekendeOp'] ?? ''))
        );

        // $rows is non-empty here (guarded above), so end() returns the last row.
        return end($rows);
    }//end getForCase()

    /**
     * Build a structured audit trail for a case calculation.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<string, mixed>
     */
    public function getAuditTrail(string $caseId): array
    {
        $berekening = $this->getForCase(caseId: $caseId);
        if ($berekening === null) {
            return ['caseId' => $caseId, 'entries' => []];
        }

        return [
            'caseId'                 => $caseId,
            'tariefTabelId'          => (string) ($berekening['tariefTabelId'] ?? ''),
            'tariefId'               => (string) ($berekening['tariefId'] ?? ''),
            'variantId'              => (string) ($berekening['variantId'] ?? ''),
            'appliedKortingen'       => (array) ($berekening['appliedKortingen'] ?? []),
            'bedragExclBtw'          => (int) ($berekening['bedragExclBtw'] ?? 0),
            'btwBedrag'              => (int) ($berekening['btwBedrag'] ?? 0),
            'bedragInclBtw'          => (int) ($berekening['bedragInclBtw'] ?? 0),
            'berekendeOp'            => (string) ($berekening['berekendeOp'] ?? ''),
            'berekendDoor'           => (string) ($berekening['berekendDoor'] ?? ''),
            'berekeningsToelichting' => (string) ($berekening['berekeningsToelichting'] ?? ''),
            'status'                 => (string) ($berekening['status'] ?? ''),
        ];
    }//end getAuditTrail()

    /**
     * Load all calculations for a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function loadForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('leges_berekening_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $records = $objectService->findAll($register, $schema, ['filters' => ['zaakId' => $caseId]]);
        } catch (\Throwable $e) {
            $this->logger->warning('Procest leges: could not load calculations for case: '.$e->getMessage());
            return [];
        }

        $rows = [];
        foreach ((array) $records as $record) {
            if (is_array($record) === true) {
                $rows[] = $record;
                continue;
            }

            if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
                $serialized = $record->jsonSerialize();
                if (is_array($serialized) === true) {
                    $rows[] = $serialized;
                }
            }
        }

        return $rows;
    }//end loadForCase()
}//end class
