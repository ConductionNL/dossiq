<?php

/**
 * Procest VTH Seed Data Repair Step.
 *
 * Idempotent loader for `lib/Settings/vth_seed_data.json`. Seeds the
 * VTH (Vergunningen, Toezicht, Handhaving) case-type catalogue and the
 * 3 baseline inspection-checklist templates referenced by the VTH
 * module specs. Re-runs are safe — existing slugs are skipped.
 *
 * Listed in `appinfo/info.xml` between `SeedVthWorkflowTemplates` and
 * `SeedTermijnbewakingData`; runs as `post-migration` after
 * `InitializeSettings` has populated the `case_type_schema` /
 * `status_type_schema` / `role_type_schema` / `document_type_schema` /
 * `property_definition_schema` / `inspection_checklist_template_schema`
 * config keys.
 *
 * The class is intentionally additive: it does NOT touch the workflow
 * templates already shipped by `SeedVthWorkflowTemplates`, nor the LHS
 * matrix already shipped by `SeedVthMatrixCells`. The `lhsMatrix`
 * fragment in `vth_seed_data.json` is descriptive (level glossary)
 * rather than canonical seed rows — it documents the matrix the
 * dedicated repair step seeds.
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
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds VTH case types and inspection-checklist templates
 * into OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister + settings.
 *
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
 */
class VthSeedDataRepairStep implements IRepairStep
{

    use SearchesObjects;

    /**
     * Location of the VTH seed catalogue, relative to this file.
     */
    private const SEED_PATH = __DIR__.'/../Settings/vth_seed_data.json';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings bridge.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
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
        return 'Seed VTH case types and inspection-checklist templates for Procest';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output Output sink.
     *
     * @return void
     *
     * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding VTH case types + inspection checklists...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping VTH seed.');
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->warning('ObjectService unavailable. Skipping VTH seed.');
            return;
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        if ($register === '') {
            $output->warning('Register not configured. Skipping VTH seed.');
            return;
        }

        $caseTypeSchema = (string) $this->settingsService->getConfigValue('case_type_schema');
        if ($caseTypeSchema === '') {
            $output->warning('case_type_schema not configured. Skipping VTH seed.');
            return;
        }

        $data = $this->loadSeed(output: $output);
        if ($data === null) {
            return;
        }

        // Repair steps run without a Nextcloud user session — anonymous
        // callers are fail-closed by OpenRegister RBAC (#1955) on every
        // boot, so the idempotency reads + writes below run inside
        // runAsSystem().
        [$caseSummary, $checklistSummary] = $this->runAsSystemIfAvailable(
            objectService: $objectService,
            operation: function () use ($objectService, $register, $caseTypeSchema, $data, $output): array {
                return [
                    $this->seedCaseTypes(
                        objectService: $objectService,
                        register: $register,
                        caseTypeSchema: $caseTypeSchema,
                        data: $data,
                        output: $output
                    ),
                    $this->seedInspectionChecklists(
                        objectService: $objectService,
                        register: $register,
                        data: $data,
                        output: $output
                    ),
                ];
            }
        );

        $output->info(
            sprintf(
                'VTH seed complete: %d case-types (%d skipped), %d checklists (%d skipped).',
                $caseSummary['seeded'],
                $caseSummary['skipped'],
                $checklistSummary['seeded'],
                $checklistSummary['skipped']
            )
        );
    }//end run()

    /**
     * Load and decode the seed catalogue.
     *
     * @param IOutput $output Output.
     *
     * @return array<string, mixed>|null
     */
    private function loadSeed(IOutput $output): ?array
    {
        if (file_exists(self::SEED_PATH) === false) {
            $output->warning('VTH seed file not found: '.self::SEED_PATH);
            return null;
        }

        $raw  = (string) file_get_contents(self::SEED_PATH);
        $data = json_decode($raw, true);
        if (is_array($data) === false) {
            $output->warning('VTH seed file is not a JSON object.');
            return null;
        }

        return $data;
    }//end loadSeed()

    /**
     * Seed the case-type catalogue.
     *
     * @param object               $objectService  OpenRegister ObjectService.
     * @param string               $register       Register slug.
     * @param string               $caseTypeSchema Case-type schema slug.
     * @param array<string, mixed> $data           Decoded seed data.
     * @param IOutput              $output         Output.
     *
     * @return array{seeded: int, skipped: int}
     */
    private function seedCaseTypes(
        object $objectService,
        string $register,
        string $caseTypeSchema,
        array $data,
        IOutput $output
    ): array {
        $caseTypes = $data['caseTypes'] ?? [];
        if (is_array($caseTypes) === false || $caseTypes === []) {
            return ['seeded' => 0, 'skipped' => 0];
        }

        $existing = $this->existingSlugs(
            objectService: $objectService,
            register: $register,
            schema: $caseTypeSchema
        );

        $seeded  = 0;
        $skipped = 0;
        foreach ($caseTypes as $caseType) {
            if (is_array($caseType) === false) {
                continue;
            }

            $slug = (string) ($caseType['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            if (in_array($slug, $existing, true) === true) {
                $skipped++;
                continue;
            }

            try {
                // Only persist top-level case-type fields here; sub-objects
                // (status/role/document/property) are owned by
                // SeedVthWorkflowTemplates which creates the canonical
                // workflow shape. This keeps the two repair steps from
                // double-writing the same children.
                $row = $this->stripChildren(caseType: $caseType);
                $objectService->saveObject(
                    register: $register,
                    schema: $caseTypeSchema,
                    object: $row
                );
                $seeded++;
            } catch (Throwable $e) {
                $output->warning('VTH case-type seed failed for '.$slug.': '.$e->getMessage());
                $this->logger->warning(
                    'Procest VTH case-type seed failed',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped];
    }//end seedCaseTypes()

    /**
     * Seed the inspection-checklist templates.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register slug.
     * @param array<string, mixed> $data          Decoded seed data.
     * @param IOutput              $output        Output.
     *
     * @return array{seeded: int, skipped: int}
     */
    private function seedInspectionChecklists(
        object $objectService,
        string $register,
        array $data,
        IOutput $output
    ): array {
        $checklists = $data['inspectionChecklists'] ?? [];
        if (is_array($checklists) === false || $checklists === []) {
            return ['seeded' => 0, 'skipped' => 0];
        }

        // Prefer the configured schema slug; fall back to the canonical name.
        $schema = (string) $this->settingsService->getConfigValue('inspection_checklist_template_schema');
        if ($schema === '') {
            $schema = 'inspectionChecklistTemplate';
        }

        $existing = $this->existingSlugs(
            objectService: $objectService,
            register: $register,
            schema: $schema
        );

        $seeded  = 0;
        $skipped = 0;
        foreach ($checklists as $checklist) {
            if (is_array($checklist) === false) {
                continue;
            }

            $slug = (string) ($checklist['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            if (in_array($slug, $existing, true) === true) {
                $skipped++;
                continue;
            }

            try {
                $objectService->saveObject(
                    register: $register,
                    schema: $schema,
                    object: $checklist
                );
                $seeded++;
            } catch (Throwable $e) {
                $output->warning('VTH checklist seed failed for '.$slug.': '.$e->getMessage());
                $this->logger->warning(
                    'Procest VTH checklist seed failed',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped];
    }//end seedInspectionChecklists()

    /**
     * Strip child collections from a case-type payload to avoid double-writing
     * status/role/document/property children already managed by
     * `SeedVthWorkflowTemplates` and `VTHTemplateService`.
     *
     * @param array<string, mixed> $caseType Raw case-type row.
     *
     * @return array<string, mixed>
     */
    private function stripChildren(array $caseType): array
    {
        unset(
            $caseType['statusTypes'],
            $caseType['roleTypes'],
            $caseType['documentTypes'],
            $caseType['propertyDefinitions']
        );
        return $caseType;
    }//end stripChildren()

    /**
     * Read existing slugs for idempotency.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register slug.
     * @param string $schema        Schema slug.
     *
     * @return array<int, string>
     */
    private function existingSlugs(
        object $objectService,
        string $register,
        string $schema
    ): array {
        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema
            );
        } catch (Throwable) {
            return [];
        }

        $slugs = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $slug = (string) ($row['slug'] ?? '');
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }//end existingSlugs()
}//end class
