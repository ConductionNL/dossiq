<?php

/**
 * Procest Seed VTH LHS Matrix Cells Repair Step
 *
 * Idempotent repair step that seeds the 16 default LHS matrix cells
 * (gedrag A-D × gevolg 1-4) as `lhsMatrixCell` objects in OpenRegister.
 * Re-runs are no-ops once cells exist.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds the default 16-cell LHS matrix into OpenRegister.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */
class SeedVthMatrixCells implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings bridge
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
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
        return 'Seed default LHS matrix cells (16 cells: gedrag A-D × gevolg 1-4) for Procest VTH module';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output Output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding VTH LHS matrix cells...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available. Skipping VTH LHS matrix cell seed.');
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->warning('ObjectService unavailable. Skipping VTH LHS matrix cell seed.');
            return;
        }

        $register = $this->settingsService->getConfigValue('register');
        if ($register === '') {
            $output->warning('Register not configured. Skipping VTH LHS matrix cell seed.');
            return;
        }

        // Check if cells already exist (idempotent).
        try {
            $existing = $objectService->findObjects(
                register: $register,
                schema: 'lhsMatrixCell',
                params: ['_limit' => 1]
            );
            if (is_array($existing) === true && count($existing) > 0) {
                $output->info('LHS matrix cells already seeded. Skipping.');
                return;
            }
        } catch (Throwable) {
            // Schema may not exist yet; proceed with seeding.
        }

        $seedPath = __DIR__.'/../Settings/lhs_matrix_seed.json';
        if (file_exists($seedPath) === false) {
            $output->warning('VTH LHS matrix seed file not found: '.$seedPath);
            return;
        }

        $raw  = (string) file_get_contents($seedPath);
        $data = json_decode($raw, true);
        if (is_array($data) === false || isset($data['cells']) === false) {
            $output->warning('VTH LHS matrix seed file is invalid JSON or missing cells key.');
            return;
        }

        $seeded = 0;
        foreach ($data['cells'] as $cell) {
            if (is_array($cell) === false) {
                continue;
            }

            try {
                $objectService->saveObject(
                    register: $register,
                    schema: 'lhsMatrixCell',
                    object: $cell
                );
                $seeded++;
            } catch (Throwable $e) {
                $output->warning('Failed to seed LHS cell '.$cell['gedragRow'].':'.$cell['gevolgColumn'].': '.$e->getMessage());
                $this->logger->warning(
                    'VTH LHS cell seed failed',
                    ['exception' => $e->getMessage(), 'cell' => $cell]
                );
            }
        }

        $output->info('VTH LHS matrix cells seeded: '.$seeded.' of '.count($data['cells']).' cells.');
    }//end run()
}//end class
