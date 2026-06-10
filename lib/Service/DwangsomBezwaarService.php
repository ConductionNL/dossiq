<?php

/**
 * Procest DwangsomBezwaarService.
 *
 * Handles bezwaar (AWB 7:1) against a DwangsomBerekening:
 *   - registerBezwaar freezes the berekening (status=bezwaar-bevroren),
 *     sets the linked DwangsomUitbetaling to on-hold-bezwaar, and
 *     emits dwangsom-bezwaar-registered.
 *   - resolveBezwaar adjusts definitievBedrag + Uitbetaling.bedrag,
 *     restores Uitbetaling.status to voorbereid, and emits
 *     dwangsom-bezwaar-resolved.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bezwaar lifecycle for a DwangsomBerekening.
 */
class DwangsomBezwaarService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param TermijnService  $termijnService  Termijn service for events.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly TermijnService $termijnService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register a bezwaar against a DwangsomBerekening.
     *
     * Freezes the berekening (status=bezwaar-bevroren) and puts the
     * linked uitbetaling on hold.
     *
     * @param string $berekeningId DwangsomBerekening id.
     * @param string $grondslag    Legal basis citation.
     * @param string $motivering   Reasoning.
     *
     * @return array<string, mixed> The frozen berekening row.
     *
     * @throws RuntimeException When the berekening is missing.
     */
    public function registerBezwaar(string $berekeningId, string $grondslag, string $motivering): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $bSchema       = (string) $this->settingsService->getConfigValue('dwangsom_berekening_schema');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
            throw new RuntimeException('Dwangsom services not configured');
        }

        try {
            $berekening = $objectService->find($berekeningId, register: $register, schema: $bSchema);
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomBerekening lookup failed: '.$e->getMessage());
        }
        if (is_array($berekening) === false) {
            throw new RuntimeException('DwangsomBerekening not found: '.$berekeningId);
        }

        $berekening['status'] = 'bezwaar-bevroren';
        try {
            $berekening = $objectService->saveObject($register, $bSchema, $berekening);
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomBerekening persist failed: '.$e->getMessage());
        }

        // Move all linked uitbetalingen to on-hold-bezwaar.
        try {
            $uitbetalingen = $objectService->findObjects($register, $uSchema, ['dwangsomBerekening' => $berekeningId]);
        } catch (\Throwable $e) {
            $uitbetalingen = [];
        }
        foreach ((array) $uitbetalingen as $u) {
            if (is_array($u) === false) {
                continue;
            }
            $u['status'] = 'on-hold-bezwaar';
            try {
                $objectService->saveObject($register, $uSchema, $u);
            } catch (\Throwable $e) {
                $this->logger->warning('Bezwaar freeze on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
            }
        }

        // Record event on termijn.
        $instanceId = (string) ($berekening['termijnInstance'] ?? '');
        if ($instanceId !== '') {
            $this->termijnService->recordEvent(
                termijnInstanceId: $instanceId,
                type: 'bezwaar-ingediend',
                grondslag: $grondslag,
                motivering: $motivering,
                dagenImpact: 0,
            );
        }

        $this->logger->info('Dwangsom bezwaar registered', ['berekening' => $berekeningId]);
        return is_array($berekening) === true ? $berekening : [];
    }//end registerBezwaar()

    /**
     * Resolve a bezwaar with a corrected amount.
     *
     * @param string $berekeningId  Berekening id.
     * @param int    $newBedragCents Corrected amount in EUR cents.
     * @param string $grondslag     Legal basis.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When berekening missing or amount invalid.
     */
    public function resolveBezwaar(string $berekeningId, int $newBedragCents, string $grondslag): array
    {
        if ($newBedragCents < 0) {
            throw new RuntimeException('newBedragCents must be >= 0');
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $bSchema       = (string) $this->settingsService->getConfigValue('dwangsom_berekening_schema');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
            throw new RuntimeException('Dwangsom services not configured');
        }

        try {
            $berekening = $objectService->find($berekeningId, register: $register, schema: $bSchema);
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomBerekening lookup failed: '.$e->getMessage());
        }
        if (is_array($berekening) === false) {
            throw new RuntimeException('DwangsomBerekening not found: '.$berekeningId);
        }

        $berekening['definitievBedrag'] = $newBedragCents;
        $berekening['status']           = 'voltooid';
        try {
            $berekening = $objectService->saveObject($register, $bSchema, $berekening);
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomBerekening persist failed: '.$e->getMessage());
        }

        try {
            $uitbetalingen = $objectService->findObjects($register, $uSchema, ['dwangsomBerekening' => $berekeningId]);
        } catch (\Throwable $e) {
            $uitbetalingen = [];
        }
        foreach ((array) $uitbetalingen as $u) {
            if (is_array($u) === false) {
                continue;
            }
            $u['bedrag'] = $newBedragCents;
            $u['status'] = 'voorbereid';
            try {
                $objectService->saveObject($register, $uSchema, $u);
            } catch (\Throwable $e) {
                $this->logger->warning('Bezwaar resolve on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
            }
        }

        $instanceId = (string) ($berekening['termijnInstance'] ?? '');
        if ($instanceId !== '') {
            $this->termijnService->recordEvent(
                termijnInstanceId: $instanceId,
                type: 'bezwaar-opgelost',
                grondslag: $grondslag,
                motivering: 'Bezwaar opgelost; bedrag herzien',
                dagenImpact: 0,
            );
        }

        $this->logger->info('Dwangsom bezwaar resolved', ['berekening' => $berekeningId, 'newBedrag' => $newBedragCents]);
        return is_array($berekening) === true ? $berekening : [];
    }//end resolveBezwaar()
}//end class
