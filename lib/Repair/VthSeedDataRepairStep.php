<?php

/**
 * Procest VTH Seed Data Repair Step
 *
 * Idempotent repair step that seeds 9 sample VTH case instances
 * (3 Omgevingsvergunning, 3 Toezichtzaak, 3 Handhavingszaak) into the
 * configured Procest register via OpenRegister's ObjectService. Cases
 * are loaded from lib/Settings/vth-seed-cases.json and keyed on the
 * deterministic `identifier` field so re-runs are no-ops.
 *
 * Soft-dep: requires the VTH workflow templates + LHS matrix to be
 * present so that case-type slugs resolve. When a slug cannot be
 * resolved, the case is logged + skipped (warning only); the rest
 * of the catalog continues.
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
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md#3
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds 9 sample VTH case instances into OpenRegister.
 *
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/spec.md
 */
class VthSeedDataRepairStep implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings bridge for OR access
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the human-readable repair-step name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed sample VTH cases (3 omgevingsvergunning + 3 toezicht + 3 handhaving) for Procest';
    }//end getName()

    /**
     * Run the repair step.
     *
     * Idempotent: skips a case when an object with the same `identifier`
     * already exists in the configured register/schema.
     *
     * @param IOutput $output Output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/specs/vth-workflow-configuration/spec.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding sample VTH cases...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping VTH case seed.');
            return;
        }

        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                $output->warning('ObjectService unavailable. Skipping VTH case seed.');
                return;
            }

            $register   = $this->settingsService->getConfigValue('register');
            $caseSchema = $this->settingsService->getConfigValue('case_schema');
            if ($register === '' || $caseSchema === '') {
                $output->warning(
                    'VTH case seed skipped: register or case_schema not configured.'
                );
                return;
            }

            $seedPath = __DIR__.'/../Settings/vth-seed-cases.json';
            if (file_exists($seedPath) === false) {
                $output->warning('VTH seed-cases file not found: '.$seedPath);
                return;
            }

            $raw     = (string) file_get_contents($seedPath);
            $payload = json_decode($raw, true);
            if (is_array($payload) === false || isset($payload['cases']) === false
                || is_array($payload['cases']) === false
            ) {
                $output->warning('VTH seed-cases file is not valid JSON or missing "cases".');
                return;
            }

            $created = 0;
            $skipped = 0;

            foreach ($payload['cases'] as $caseSpec) {
                if (is_array($caseSpec) === false
                    || isset($caseSpec['identifier']) === false
                ) {
                    $skipped++;
                    continue;
                }

                $identifier = (string) $caseSpec['identifier'];

                if ($this->caseExists(
                    objectService: $objectService,
                    register: $register,
                    schema: $caseSchema,
                    identifier: $identifier,
                ) === true) {
                    $skipped++;
                    continue;
                }

                $object = [
                    'title'           => (string) ($caseSpec['title'] ?? $identifier),
                    'identifier'      => $identifier,
                    'description'     => (string) ($caseSpec['description'] ?? ''),
                    'caseTypeSlug'    => (string) ($caseSpec['caseTypeSlug'] ?? ''),
                    'confidentiality' => (string) ($caseSpec['confidentiality'] ?? 'intern'),
                    'intakeChannel'   => (string) ($caseSpec['intakeChannel'] ?? 'manual'),
                    'startDate'       => (new DateTimeImmutable())->format('Y-m-d'),
                    'address'         => $caseSpec['address'] ?? [],
                    'properties'      => $caseSpec['properties'] ?? [],
                    'createdAt'       => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                ];

                try {
                    $objectService->saveObject(
                        register: $register,
                        schema: $caseSchema,
                        object: $object,
                    );
                    $created++;
                } catch (Throwable $e) {
                    $this->logger->error(
                        'VTH seed case save failed for '.$identifier,
                        ['exception' => $e->getMessage()]
                    );
                    $skipped++;
                }
            }

            $output->info(
                'VTH case seed complete: '.$created.' created, '.$skipped.' skipped (already present or invalid).'
            );
        } catch (Throwable $e) {
            $output->warning('Could not seed VTH cases: '.$e->getMessage());
            $this->logger->error(
                'Procest VTH case seed failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Check whether a case with the given identifier already exists.
     *
     * @param object $objectService OpenRegister ObjectService
     * @param string $register      Register slug or ID
     * @param string $schema        Schema slug or ID
     * @param string $identifier    Case identifier to look up
     *
     * @return bool
     */
    private function caseExists(
        object $objectService,
        string $register,
        string $schema,
        string $identifier,
    ): bool {
        try {
            $existing = $objectService->findAll(
                [
                    'filters' => [
                        'register'   => $register,
                        'schema'     => $schema,
                        'identifier' => $identifier,
                    ],
                    'limit'   => 1,
                ],
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'VTH case exists lookup failed for '.$identifier,
                ['exception' => $e->getMessage()]
            );
            return false;
        }

        if (is_array($existing) === false) {
            return false;
        }

        if (isset($existing[0]) === true) {
            return true;
        }

        if (isset($existing['results']) === true
            && is_array($existing['results']) === true
            && count($existing['results']) > 0
        ) {
            return true;
        }

        return false;
    }//end caseExists()
}//end class
