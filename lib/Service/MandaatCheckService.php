<?php

/**
 * Procest MandaatCheckService.
 *
 * Authorization decision engine for mandates. Answers the question:
 * is user X authorized to take decision Y on case Z right now?
 *
 *   - Resolves the user's active role (primair vs. waarnemer vs. tijdelijk).
 *   - Walks Mandaat rows for the role, filtered by decisionType + caseType +
 *     temporal validity (validFrom <= date <= validUntil).
 *   - Evaluates voorwaarden (plafondCents, subdelegatie, decisionTypes,
 *     caseTypes) against the case properties.
 *   - Returns {authorized, mandaatId|reden}.
 *
 * Money values: integer EUR cents throughout (ADR-031).
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
 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Mandate authorization engine.
 */
class MandaatCheckService
{
    use SearchesObjects;

    public const REDEN_NIET_BEVOEGD                = 'niet_bevoegd';
    public const REDEN_PLAFOND_OVERSCHREDEN        = 'plafond_overschreden';
    public const REDEN_SUBDELEGATIE_NIET_TOEGESTAAN = 'subdelegatie_niet_toegestaan';
    public const REDEN_BELANGENCONFLICT            = 'belangenconflict';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly ?ConflictOfInterestService $conflictService = null,
    ) {
    }//end __construct()

    /**
     * Decide whether the user is authorized for the (decisionType, case) pair.
     *
     * @param string                 $userId           Nextcloud user id.
     * @param string                 $decisionType     Decision type slug.
     * @param string                 $caseId           Case id.
     * @param array<string, mixed>   $caseProperties   Case properties for condition matching.
     * @param DateTimeImmutable|null $decisionDate     Optional override (defaults to now).
     *
     * @return array{authorized:bool, mandaatId?:string, reden?:string, failedConditions?:array<int,string>}
     *
     * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
     */
    public function isAuthorized(
        string $userId,
        string $decisionType,
        string $caseId,
        array $caseProperties = [],
        ?DateTimeImmutable $decisionDate = null
    ): array {
        $decisionDate = ($decisionDate ?? new DateTimeImmutable());

        // Optional belangenconflict check (REQ-MANDAAT-006).
        if ($this->conflictService !== null) {
            $conflict = $this->conflictService->checkConflict($userId, $caseId, $caseProperties);
            if (($conflict['conflict'] ?? false) === true) {
                return [
                    'authorized' => false,
                    'reden'      => self::REDEN_BELANGENCONFLICT,
                    'conflictReason' => (string) ($conflict['reason'] ?? ''),
                ];
            }
        }

        $role = $this->resolveUserRole($userId, $decisionDate);
        if ($role === null) {
            return ['authorized' => false, 'reden' => self::REDEN_NIET_BEVOEGD];
        }

        $caseType  = (string) ($caseProperties['caseType'] ?? '');
        $mandaten  = $this->getApplicableMandaten($decisionType, $caseType, $decisionDate);

        $relevant = array_values(array_filter(
            $mandaten,
            static fn (array $m): bool => (string) ($m['gemandateerdeRol'] ?? '') === (string) $role['rolId']
        ));

        if (count($relevant) === 0) {
            return ['authorized' => false, 'reden' => self::REDEN_NIET_BEVOEGD];
        }

        // Pick the first mandaat whose voorwaarden pass; surface the most-specific
        // failure reason when none pass.
        $lastFailure = ['reden' => self::REDEN_NIET_BEVOEGD, 'failedConditions' => []];
        foreach ($relevant as $m) {
            $eval = $this->evaluateConditions($m, $caseProperties);
            if ($eval['passed'] === true) {
                return [
                    'authorized'   => true,
                    'mandaatId'    => (string) ($m['id'] ?? ''),
                    'reden'        => null,
                ];
            }
            $lastFailure = [
                'reden'            => $eval['reden'],
                'failedConditions' => $eval['failedConditions'],
            ];
        }

        return [
            'authorized'      => false,
            'reden'           => $lastFailure['reden'],
            'failedConditions'=> $lastFailure['failedConditions'],
        ];
    }//end isAuthorized()

    /**
     * Get the applicable mandaten for a decision-type + case-type pair,
     * active at the given date.
     *
     * @param string                 $decisionType Decision type slug.
     * @param string                 $caseType     Case type slug (may be empty).
     * @param DateTimeImmutable|null $date         Date (default today).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
     */
    public function getApplicableMandaten(string $decisionType, string $caseType, ?DateTimeImmutable $date = null): array
    {
        $date          = ($date ?? new DateTimeImmutable());
        $dateStr       = $date->format('Y-m-d');
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('mandaat_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['status' => 'active']);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $vf = (string) ($row['validFrom']  ?? '1970-01-01');
            $vu = (string) ($row['validUntil'] ?? '');
            if ($vf > $dateStr) {
                continue;
            }
            if ($vu !== '' && $vu < $dateStr) {
                continue;
            }

            $voorw = (array) ($row['voorwaarden'] ?? []);
            $decTypes = (array) ($voorw['decisionTypes'] ?? []);
            if (count($decTypes) > 0 && in_array($decisionType, $decTypes, true) === false) {
                continue;
            }
            $caseTypes = (array) ($voorw['caseTypes'] ?? []);
            if ($caseType !== '' && count($caseTypes) > 0 && in_array($caseType, $caseTypes, true) === false) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }//end getApplicableMandaten()

    /**
     * Resolve the user's *primary* active role at the given date.
     *
     * Returns an array {rolId, toewijzingType, waarnemerVoor} when found.
     *
     * @param string            $userId User id.
     * @param DateTimeImmutable $date   Date.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
     */
    public function resolveUserRole(string $userId, DateTimeImmutable $date): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('medewerker_rol_toewijzing_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['userId' => $userId]);
        } catch (\Throwable $e) {
            // Fail closed: log and surface "no role" instead of swallowing.
            $this->logger->error(
                'MandaatCheckService.resolveUserRole lookup failed (fail-closed)',
                ['userId' => $userId, 'error' => $e->getMessage()]
            );
            $rows = [];
        }

        $dateStr = $date->format('Y-m-d');
        $active  = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $vf = (string) ($row['validFrom']  ?? '1970-01-01');
            $vu = (string) ($row['validUntil'] ?? '');
            if ($vf > $dateStr) {
                continue;
            }
            if ($vu !== '' && $vu < $dateStr) {
                continue;
            }
            $active[] = $row;
        }

        if (count($active) === 0) {
            return null;
        }

        // Sort: primair first, then waarnemer, then tijdelijk.
        $order = ['primair' => 0, 'waarnemer' => 1, 'tijdelijk' => 2];
        usort(
            $active,
            static fn (array $a, array $b): int =>
                ($order[(string) ($a['toewijzingType'] ?? 'primair')] ?? 99)
                <=> ($order[(string) ($b['toewijzingType'] ?? 'primair')] ?? 99)
        );

        return $active[0];
    }//end resolveUserRole()

    /**
     * Evaluate voorwaarden (plafond, subdelegatie) against the case properties.
     *
     * @param array<string, mixed> $mandaat        Mandaat row.
     * @param array<string, mixed> $caseProperties Case properties (e.g. bedragCents, subdelegatieRequested).
     *
     * @return array{passed:bool, reden:string, failedConditions:array<int,string>}
     *
     * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
     */
    public function evaluateConditions(array $mandaat, array $caseProperties): array
    {
        $voorw = (array) ($mandaat['voorwaarden'] ?? []);
        $failed = [];
        $reden  = '';

        // Plafond check (cents).
        if (isset($voorw['plafondCents']) === true && isset($caseProperties['bedragCents']) === true) {
            $plafond = (int) $voorw['plafondCents'];
            $bedrag  = (int) $caseProperties['bedragCents'];
            if ($bedrag > $plafond) {
                $failed[] = 'plafond';
                $reden    = self::REDEN_PLAFOND_OVERSCHREDEN;
            }
        }

        // Subdelegation check.
        if (isset($caseProperties['subdelegatieRequested']) === true && $caseProperties['subdelegatieRequested'] === true) {
            $allowed = (bool) ($voorw['subdelegatie'] ?? false);
            if ($allowed === false) {
                $failed[] = 'subdelegatie';
                $reden    = $reden === '' ? self::REDEN_SUBDELEGATIE_NIET_TOEGESTAAN : $reden;
            }
        }

        if (count($failed) > 0) {
            return ['passed' => false, 'reden' => ($reden !== '' ? $reden : self::REDEN_NIET_BEVOEGD), 'failedConditions' => $failed];
        }

        return ['passed' => true, 'reden' => '', 'failedConditions' => []];
    }//end evaluateConditions()
}//end class
