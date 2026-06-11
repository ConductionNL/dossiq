<?php

/**
 * Procest ArchiefEdepotSeedDataService.
 *
 * Seeds VNG default BewaarTermijnRegel rows (omgevingsvergunning 5y,
 * wmo-melding 10y, subsidie-verlening permanent) into OpenRegister.
 * Idempotent: existing rules with the same id are skipped.
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
 * @spec openspec/changes/archief-edepot-handover-01-schema-config/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Seeds VNG retention rules.
 */
class ArchiefEdepotSeedDataService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Seed the BewaarTermijnRegel rows.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/archief-edepot-handover-01-schema-config/tasks.md
     */
    public function seed(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['success' => false, 'message' => 'OpenRegister is not available'];
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        $schema   = (string) $this->settingsService->getConfigValue('bewaar_termijn_regel_schema');
        if ($register === '' || $schema === '') {
            return ['success' => false, 'message' => 'Archief schemas not configured'];
        }

        $seedPath = __DIR__.'/../Settings/archief_edepot_seed_data.json';
        if (file_exists($seedPath) === false) {
            return ['success' => false, 'message' => 'Seed file not found'];
        }

        $data = json_decode((string) file_get_contents($seedPath), true);
        if (is_array($data) === false) {
            return ['success' => false, 'message' => 'Invalid seed JSON'];
        }

        $existing = $this->existingIds($objectService, $register, $schema);
        $counts   = ['regels' => 0, 'skipped' => 0];

        foreach (($data['bewaarTermijnRegels'] ?? []) as $row) {
            $rowId = (string) ($row['id'] ?? '');
            if ($rowId !== '' && in_array($rowId, $existing, true) === true) {
                $counts['skipped']++;
                continue;
            }
            try {
                $objectService->saveObject($register, $schema, $row);
                $counts['regels']++;
            } catch (\Throwable $e) {
                $this->logger->warning('Archief seed row failed', ['id' => $rowId, 'error' => $e->getMessage()]);
            }
        }

        return array_merge(['success' => true], $counts);
    }//end seed()

    /**
     * @param object $objectService Object service.
     * @param string $register      Register.
     * @param string $schema        Schema.
     * @return array<int, string>
     */
    private function existingIds(object $objectService, string $register, string $schema): array
    {
        if (method_exists($objectService, 'findObjects') === false) {
            return [];
        }
        try {
            $rows = $this->searchObjectsAsArrays($objectService, $register, $schema);
        } catch (\Throwable $e) {
            return [];
        }
        $ids = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) === true && isset($row['id']) === true) {
                $ids[] = (string) $row['id'];
            }
        }
        return $ids;
    }
}//end class
