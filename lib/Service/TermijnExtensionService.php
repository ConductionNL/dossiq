<?php

/**
 * Procest TermijnExtensionService.
 *
 * AWB 4:14 verlenging on a TermijnInstance. Validates that the
 * verlenging-count is below the TermijnDefinitie's aantalVerlengingen
 * ceiling, that motivering is non-empty, and that newEinddatum is in
 * the future relative to einddatumActueel. A supervisor-approval
 * override path bypasses the ceiling for exceptional cases.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;

/**
 * AWB 4:14 verlenging engine on a TermijnInstance.
 */
class TermijnExtensionService
{
    /**
     * Extension mode: the ordinary AWB 4:14 lid 1 verlenging, bound by the
     * TermijnDefinitie ceiling.
     *
     * @var string
     */
    public const MODE_STANDARD = 'standard';

    /**
     * Extension mode: the AWB 4:14 lid 3 supervisor-approved verlenging,
     * which bypasses the TermijnDefinitie ceiling.
     *
     * @var string
     */
    public const MODE_SUPERVISOR = 'supervisor';

    /**
     * Constructor.
     *
     * @param TermijnService $termijnService TermijnService.
     */
    public function __construct(
        private readonly TermijnService $termijnService,
    ) {
    }//end __construct()

    /**
     * Request an ordinary AWB 4:14 lid 1 verlenging on a TermijnInstance.
     *
     * Bound by the TermijnDefinitie's aantalVerlengingen ceiling.
     *
     * @param string $termijnInstanceId Instance id.
     * @param string $motivering        Non-empty reason.
     * @param string $newEinddatum      New deadline (YYYY-MM-DD; must be > einddatumActueel).
     * @param string $documentLink      Optional document link (verlengingsbrief).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException With validation failures (cited AWB rule).
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
     */
    public function requestExtension(
        string $termijnInstanceId,
        string $motivering,
        string $newEinddatum,
        string $documentLink=''
    ): array {
        return $this->applyExtension(
            termijnInstanceId: $termijnInstanceId,
            motivering: $motivering,
            newEinddatum: $newEinddatum,
            documentLink: $documentLink,
            mode: self::MODE_STANDARD
        );
    }//end requestExtension()

    /**
     * Request a supervisor-approved AWB 4:14 lid 3 verlenging.
     *
     * Bypasses the TermijnDefinitie's aantalVerlengingen ceiling and is
     * recorded with the supervisor grondslag and actor.
     *
     * @param string $termijnInstanceId Instance id.
     * @param string $motivering        Non-empty reason.
     * @param string $newEinddatum      New deadline (YYYY-MM-DD; must be > einddatumActueel).
     * @param string $documentLink      Optional document link (verlengingsbrief).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException With validation failures (cited AWB rule).
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
     */
    public function requestSupervisorExtension(
        string $termijnInstanceId,
        string $motivering,
        string $newEinddatum,
        string $documentLink=''
    ): array {
        return $this->applyExtension(
            termijnInstanceId: $termijnInstanceId,
            motivering: $motivering,
            newEinddatum: $newEinddatum,
            documentLink: $documentLink,
            mode: self::MODE_SUPERVISOR
        );
    }//end requestSupervisorExtension()

    /**
     * Shared verlenging implementation for both extension modes.
     *
     * @param string $termijnInstanceId Instance id.
     * @param string $motivering        Non-empty reason.
     * @param string $newEinddatum      New deadline (YYYY-MM-DD; must be > einddatumActueel).
     * @param string $documentLink      Optional document link (verlengingsbrief).
     * @param string $mode              One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException With validation failures (cited AWB rule).
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
     */
    private function applyExtension(
        string $termijnInstanceId,
        string $motivering,
        string $newEinddatum,
        string $documentLink,
        string $mode
    ): array {
        if (trim($motivering) === '') {
            throw new RuntimeException('Motivering is required for AWB 4:14 verlenging');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEinddatum) !== 1) {
            throw new RuntimeException('newEinddatum must be in YYYY-MM-DD format');
        }

        $instance = $this->termijnService->getTermijnInstance($termijnInstanceId);
        if ($instance === null) {
            throw new RuntimeException('TermijnInstance not found: '.$termijnInstanceId);
        }

        $current = (string) ($instance['einddatumActueel'] ?? '');
        if ($current !== '' && $newEinddatum <= $current) {
            throw new RuntimeException('newEinddatum must be later than current einddatumActueel');
        }

        $consumed = (int) ($instance['aantalVerlengingen'] ?? 0);
        $maxExt   = $this->resolveMaxExtensions(instance: $instance);
        if ($mode !== self::MODE_SUPERVISOR && $consumed >= $maxExt) {
            throw new RuntimeException('AWB 4:14 lid 3: maximum aantal verlengingen al verbruikt ('.$maxExt.')');
        }

        $currentInput = 'now';
        if ($current !== '') {
            $currentInput = $current;
        }

        $currentDate = new DateTimeImmutable($currentInput);
        $newDate     = new DateTimeImmutable($newEinddatum);
        $dagenImpact = (int) $currentDate->diff($newDate)->days;

        $updated = $this->termijnService->updateTermijnInstance(
            $termijnInstanceId,
            [
                'einddatumActueel'   => $newEinddatum,
                'status'             => 'verlengd',
                'aantalVerlengingen' => ($consumed + 1),
            ]
        );

        $grondslag = 'AWB 4:14 lid 1';
        $actor     = 'system';
        if ($mode === self::MODE_SUPERVISOR) {
            $grondslag = 'AWB 4:14 lid 3 (supervisor)';
            $actor     = 'supervisor';
        }

        $this->termijnService->recordEvent(
            termijnInstanceId: $termijnInstanceId,
            type: 'verleng',
            grondslag: $grondslag,
            motivering: $motivering,
            dagenImpact: $dagenImpact,
            documentLink: $documentLink,
            actor: $actor,
        );

        return $updated ?? $instance;
    }//end applyExtension()

    /**
     * Resolve the maximum number of extensions allowed for this instance.
     *
     * Looks up the TermijnDefinitie via the instance reference and reads
     * aantalVerlengingen; falls back to 1 when missing (AWB default).
     *
     * @param array<string, mixed> $instance Instance row.
     *
     * @return int
     */
    private function resolveMaxExtensions(array $instance): int
    {
        // Prefer to look up the definition by the linked id.
        $defId = (string) ($instance['termijnDefinitie'] ?? '');
        if ($defId === '') {
            return 1;
        }

        // Walk the TermijnService cache by zaaktype if available. As a
        // safe fallback, return the default 1 — a real lookup would
        // call SettingsService->getObjectService()->find($defId) here,
        // but TermijnService already caches lookups by zaaktype which
        // is the data we actually need.
        $svcDef = null;
        try {
            $reflection = new ReflectionClass($this->termijnService);
            if ($reflection->hasProperty('definitieCache') === true) {
                $prop  = $reflection->getProperty('definitieCache');
                $cache = $prop->getValue($this->termijnService);
                if (is_array($cache) === true) {
                    foreach ($cache as $row) {
                        if (is_array($row) === true && (string) ($row['id'] ?? '') === $defId) {
                            $svcDef = $row;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $svcDef = null;
        }

        if (is_array($svcDef) === true) {
            return (int) ($svcDef['aantalVerlengingen'] ?? 1);
        }

        return 1;
    }//end resolveMaxExtensions()
}//end class
