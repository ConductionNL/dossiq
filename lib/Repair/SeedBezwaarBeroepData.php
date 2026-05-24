<?php

/**
 * Procest Seed Bezwaar/Beroep Data Repair Step
 *
 * Repair step that seeds pre-defined bezwaar and beroep case types
 * with status types, role types, and workflow templates.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SeedDataService;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds bezwaar and beroep case types into OpenRegister.
 */
class SeedBezwaarBeroepData implements IRepairStep
{
    /**
     * Constructor for SeedBezwaarBeroepData.
     *
     * @param SeedDataService $seedDataService The seed data service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SeedDataService $seedDataService,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed Bezwaar and Beroep case types for Procest';
    }//end getName()

    /**
     * Run the repair step to seed bezwaar/beroep data.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding bezwaar and beroep case types...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not available. Skipping bezwaar/beroep seed.'
            );
            return;
        }

        try {
            $result = $this->seedDataService->seedBezwaarBeroepData();

            if ($result['success'] === true) {
                $output->info(
                    'Bezwaar/beroep seed complete: '
                    .$result['caseTypes'].' case types, '
                    .$result['statusTypes'].' status types, '
                    .$result['roleTypes'].' role types, '
                    .$result['workflows'].' workflows created ('
                    .$result['skipped'].' skipped)'
                );
                return;
            }

            $message = ($result['message'] ?? 'unknown error');
            $output->warning('Bezwaar/beroep seed issue: '.$message);
        } catch (\Throwable $e) {
            $output->warning(
                'Could not seed bezwaar/beroep data: '.$e->getMessage()
            );
            $this->logger->error(
                'Procest bezwaar/beroep seed failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
