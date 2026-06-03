<?php

/**
 * Procest Seed Leges Data Repair Step
 *
 * Repair step that seeds example leges tariff tables, tariffs, variants and
 * discounts into OpenRegister. Idempotent: existing tables are skipped.
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-001
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\LegesSeedDataService;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds example leges data into OpenRegister.
 */
class SeedLegesData implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param LegesSeedDataService $seedService     The leges seed service.
     * @param SettingsService      $settingsService The settings service.
     * @param LoggerInterface      $logger          The logger.
     */
    public function __construct(
        private readonly LegesSeedDataService $seedService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed example leges tariffs for Procest';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding leges tariffs...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping leges seed.');
            return;
        }

        try {
            $result = $this->seedService->seed();
            if (($result['success'] ?? false) === true) {
                $output->info(
                    'Leges seed complete: '.((int) ($result['tabellen'] ?? 0)).' tabellen, '
                    .((int) ($result['tarieven'] ?? 0)).' tarieven, '
                    .((int) ($result['varianten'] ?? 0)).' varianten, '
                    .((int) ($result['kortingen'] ?? 0)).' kortingen ('
                    .((int) ($result['skipped'] ?? 0)).' overgeslagen)'
                );
                return;
            }

            $output->warning('Leges seed issue: '.((string) ($result['message'] ?? 'unknown error')));
        } catch (\Throwable $e) {
            $output->warning('Could not seed leges data: '.$e->getMessage());
            $this->logger->error('Procest leges seed failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
