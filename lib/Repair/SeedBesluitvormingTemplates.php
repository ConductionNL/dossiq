<?php

/**
 * Procest Seed Besluitvorming Templates Repair Step
 *
 * Repair step that seeds the three pre-configured bestuurlijke-besluitvorming
 * zaaktype bundles (College-besluit, Raadsbesluit, Mandaatbesluit) into
 * OpenRegister on install/upgrade. Activation is idempotent — re-running this
 * step does not duplicate records.
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\BesluitvormingTemplateService;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds besluitvorming zaaktype templates into OpenRegister.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class SeedBesluitvormingTemplates implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param BesluitvormingTemplateService $templateService The besluitvorming template service.
     * @param SettingsService               $settingsService The settings service.
     * @param LoggerInterface               $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private BesluitvormingTemplateService $templateService,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/specs/besluitvorming-workflow/spec.md
     */
    public function getName(): string
    {
        return 'Seed besluitvorming zaaktype templates for Procest';
    }//end getName()

    /**
     * Run the repair step to seed besluitvorming templates.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/specs/besluitvorming-workflow/spec.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding besluitvorming zaaktype templates...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping besluitvorming template seed.');
            return;
        }

        try {
            $summary = $this->templateService->activateAll();
            foreach ($summary as $slug => $result) {
                if (($result['skipped'] ?? false) === true) {
                    $output->info('Besluitvorming template '.$slug.' already active, skipped.');
                    continue;
                }

                if (($result['success'] ?? false) === true) {
                    $output->info('Besluitvorming template '.$slug.' activated.');
                    continue;
                }

                $output->warning('Besluitvorming template '.$slug.' issue: '.($result['message'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            $output->warning('Could not seed besluitvorming templates: '.$e->getMessage());
            $this->logger->error(
                'Procest besluitvorming template seed failed',
                ['exception' => $e->getMessage()],
            );
        }//end try
    }//end run()
}//end class
