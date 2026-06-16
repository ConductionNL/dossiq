<?php

/**
 * Procest Seed Termijnbewaking Data Repair Step.
 *
 * Seeds the three demo `TermijnDefinitie` rows
 * (Omgevingsvergunning-regulier, Wmo-aanvraag, Woo-verzoek) into
 * OpenRegister via {@see TermijnbewakingSeedDataService}.
 * Idempotent: existing definitions are skipped.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnbewakingSeedDataService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds termijnbewaking demo data into OpenRegister.
 */
class SeedTermijnbewakingData implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param TermijnbewakingSeedDataService $seedService     Seed service.
     * @param SettingsService                $settingsService Settings service.
     * @param LoggerInterface                $logger          Logger.
     */
    public function __construct(
        private readonly TermijnbewakingSeedDataService $seedService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the repair-step display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed demo TermijnDefinities for Procest termijnbewaking';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output Output sink.
     *
     * @return void
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding termijnbewaking definitions...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping termijnbewaking seed.');
            return;
        }

        try {
            $result = $this->seedService->seed();
            if (($result['success'] ?? false) === true) {
                $output->info(
                    'Termijnbewaking seed complete: '
                    .((int) ($result['definities'] ?? 0)).' definities ('
                    .((int) ($result['skipped'] ?? 0)).' overgeslagen)'
                );
                return;
            }

            $output->warning('Termijnbewaking seed issue: '.((string) ($result['message'] ?? 'unknown error')));
        } catch (\Throwable $e) {
            $output->warning('Could not seed termijnbewaking data: '.$e->getMessage());
            $this->logger->error('Procest termijnbewaking seed failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
