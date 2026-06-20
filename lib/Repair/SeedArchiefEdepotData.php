<?php

/**
 * Procest Seed Archief E-Depot Data Repair Step.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
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

namespace OCA\Procest\Repair;

use OCA\Procest\Service\ArchiefEdepotSeedDataService;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seeds VNG default retention rules into OpenRegister.
 */
class SeedArchiefEdepotData implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param ArchiefEdepotSeedDataService $seedService Seed.
     * @param SettingsService              $settings    Settings.
     * @param LoggerInterface              $logger      Logger.
     */
    public function __construct(
        private readonly ArchiefEdepotSeedDataService $seedService,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Seed VNG default retention rules for Procest archief';
    }//end getName()

    /**
     * @param  IOutput $output Output.
     * @return void
     *
     * @spec openspec/changes/archief-edepot-handover-01-schema-config/tasks.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding archief retention rules...');

        if ($this->settings->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping archief seed.');
            return;
        }

        try {
            $r = $this->seedService->seed();
            if (($r['success'] ?? false) === true) {
                $output->info(
                    'Archief seed complete: '
                    .((int) ($r['regels'] ?? 0)).' regels ('
                    .((int) ($r['skipped'] ?? 0)).' overgeslagen)'
                );
                return;
            }

            $output->warning('Archief seed issue: '.((string) ($r['message'] ?? 'unknown error')));
        } catch (\Throwable $e) {
            $output->warning('Could not seed archief data: '.$e->getMessage());
            $this->logger->error('Procest archief seed failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
