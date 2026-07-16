<?php

/**
 * Procest Initialize Settings Repair Step
 *
 * Repair step that initializes Procest register and schemas on install/upgrade.
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
 * @spec openspec/specs/procest-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Procest configuration via ConfigurationService.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getName(): string
    {
        return 'Initialize Procest register and schemas via ConfigurationService';
    }//end getName()

    /**
     * Run the repair step to initialize Procest configuration.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing Procest configuration...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not installed or enabled. Skipping auto-configuration.'
            );
            $this->logger->warning(
                'Procest: OpenRegister not available, skipping register initialization'
            );
            return;
        }

        try {
            $result = $this->settingsService->loadConfiguration(force: true);

            // Always reconcile EVERY *_schema appconfig key directly from
            // OpenRegister (idempotent). loadConfiguration() only maps schema
            // IDs that appear in the import RESULT; an already-imported instance
            // returns an empty schema list, which previously left
            // case_type_schema/status_type_schema/status_record_schema/
            // workflow_template_schema unset and broke status-name resolution +
            // the WorkflowBoard on a fresh deploy. Running the reconcile here
            // guarantees the keys are provisioned even when the import is a
            // no-op (or partially succeeds).
            $reconciled = $this->settingsService->reconcileSchemaConfig();
            $output->info('Procest schema config keys reconciled ('.$reconciled.' written)');

            // Reconcile the declarative `x-openregister-*` annotation blocks
            // (calculations / references / lifecycle) onto the live schema
            // configuration. OpenRegister's import maps schema properties but
            // does not reliably round-trip these schema-level annotation blocks
            // on an already-imported instance, which would silently disable
            // auto-deadline / auto-identifier / initial-status on create.
            $declarativeReconciled = $this->settingsService->reconcileSchemaDeclarativeConfig();
            $output->info(
                'Procest declarative schema configuration reconciled ('.$declarativeReconciled.' written)'
            );

            if ($result['success'] === true) {
                $version = ($result['version'] ?? 'unknown');
                $output->info(
                    'Procest configuration imported successfully (version: '.$version.')'
                );
                return;
            }

            $message = ($result['message'] ?? 'unknown error');
            $output->warning(
                'Procest configuration import issue: '.$message
            );
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Procest: '.$e->getMessage());
            $this->logger->error(
                'Procest initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
