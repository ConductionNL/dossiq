<?php

/**
 * Procest Seed VTH Workflow Templates Repair Step
 *
 * Repair step that seeds six canonical VTH (Vergunningen, Toezicht &
 * Handhaving) workflow templates as published `workflowTemplate` v1 objects
 * via `WorkflowDefinitionService::createDraft()` + `publish()`. Idempotent
 * on re-run.
 *
 * The repair step routes every mutation of a `workflowTemplate` through
 * `WorkflowDefinitionService` to respect the immutability invariant of
 * published rows established by `workflow-definition-model`. It NEVER
 * writes `workflowTemplate` rows directly through `ObjectService`.
 *
 * Soft-deps on `base-register-seed-data`: when a caseType slug cannot be
 * resolved, the template is logged + skipped (warning only), and the rest
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
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCA\Procest\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds six canonical VTH workflow templates.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister + WorkflowDefinitionService.
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class SeedVthWorkflowTemplates implements IRepairStep
{

    use SearchesObjects;

    /**
     * Catalog directory relative to lib/.
     */
    private const CATALOG_DIR = __DIR__.'/../Settings/seed/vth-workflow-templates';

    /**
     * UUID5 namespace for deterministic step/transition ids derived from
     * template slug + child slug.
     */
    private const NS_UUID = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Constructor for SeedVthWorkflowTemplates.
     *
     * @param SettingsService           $settingsService   Settings service for OR access
     * @param WorkflowDefinitionService $definitionService Workflow lifecycle service
     * @param LoggerInterface           $logger            Logger
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly WorkflowDefinitionService $definitionService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/specs/vth-workflow-templates/spec.md
     */
    public function getName(): string
    {
        return 'Seed VTH (Vergunningen, Toezicht, Handhaving) workflow templates for Procest';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding VTH workflow templates...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not available. Skipping VTH workflow templates seed.'
            );
            return;
        }

        if (is_dir(self::CATALOG_DIR) === false) {
            $output->warning(
                'VTH workflow templates catalog directory not found at '
                .self::CATALOG_DIR
            );
            return;
        }

        $files = glob(self::CATALOG_DIR.'/*.json');
        if ($files === false || $files === []) {
            $output->warning('No VTH workflow template catalog files found.');
            return;
        }

        $summary = [
            'seeded'    => 0,
            'skipped'   => 0,
            'crossLink' => 0,
            'failed'    => 0,
        ];

        // This repair step runs without a Nextcloud user session — anonymous
        // callers are fail-closed by OpenRegister RBAC (#1955) on every
        // boot. Without this, every caseType/statusType lookup below reads
        // as empty and every template is (mis)reported as "caseType not
        // found", never actually seeding. runAsSystem() also covers the
        // nested WorkflowDefinitionService::createDraft()/publish() calls,
        // since the elevation is scoped to this callable for the whole
        // process, not to one ObjectService instance.
        $objectService = $this->settingsService->getObjectService();
        $runner        = function () use ($files, &$summary, $output): void {
            foreach ($files as $file) {
                try {
                    $result           = $this->processCatalogFile(file: $file, output: $output);
                    $summary[$result] = ($summary[$result] ?? 0) + 1;
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    $this->logger->error(
                        'Procest: failed to process VTH workflow template catalog file',
                        [
                            'app'       => Application::APP_ID,
                            'file'      => basename($file),
                            'exception' => $e->getMessage(),
                        ]
                    );
                    $output->warning(
                        'Skipping catalog file '.basename($file)
                        .' due to processing error (see log).'
                    );
                }//end try
            }//end foreach
        };

        // Seeding runs with system privileges when OpenRegister exposes
        // runAsSystem(); without an ObjectService there is nothing to elevate,
        // so the closure is invoked directly.
        if ($objectService === null) {
            $runner();
        }

        if ($objectService !== null) {
            $this->runAsSystemIfAvailable(objectService: $objectService, operation: $runner);
        }

        $output->info(
            'VTH workflow templates seed complete: '
            .$summary['seeded'].' seeded, '
            .$summary['skipped'].' skipped (already present or unresolved), '
            .$summary['crossLink'].' cross-link entries logged, '
            .$summary['failed'].' failed.'
        );
    }//end run()

    /**
     * Process a single catalog file.
     *
     * @param string  $file   Absolute path to the JSON catalog file
     * @param IOutput $output The output interface
     *
     * @return string One of seeded|skipped|crossLink|failed
     */
    private function processCatalogFile(string $file, IOutput $output): string
    {
        $data = $this->loadCatalogEntry(file: $file);
        if ($data === null) {
            return 'failed';
        }

        $slug  = (string) ($data['slug'] ?? '');
        $title = (string) ($data['title'] ?? '');

        // Cross-link entries (e.g. bezwaar) do not create a new
        // workflowTemplate; they only document VTH-specific guards that
        // a downstream change should attach to the canonical workflow.
        if ((bool) ($data['crossLink'] ?? false) === true) {
            $this->logger->info(
                'Procest: VTH workflow template — cross-link entry, no new workflow created',
                [
                    'app'                      => Application::APP_ID,
                    'slug'                     => $slug,
                    'targetWorkflowIdentifier' => (string) ($data['targetWorkflowIdentifier'] ?? ''),
                ]
            );
            $output->info(
                'VTH catalog: cross-link entry "'.$slug.'" — no new workflow created.'
            );
            return 'crossLink';
        }

        // Resolve caseType slug → UUID and the statusType map (soft-fail).
        $context = $this->resolveTemplateContext(
            data: $data,
            slug: $slug,
            title: $title,
            output: $output,
        );
        if ($context === null) {
            return 'skipped';
        }

        // Resolve steps and transitions. On any unresolved status, skip
        // the entire template (no partial seed).
        $graph = $this->resolveWorkflowGraph(
            data: $data,
            slug: $slug,
            statusMap: (array) $context['statusMap'],
        );
        if ($graph === null) {
            return 'skipped';
        }

        return $this->createAndPublishTemplate(
            data: $data,
            slug: $slug,
            title: $title,
            caseTypeId: (string) $context['caseTypeId'],
            graph: $graph,
            output: $output,
        );
    }//end processCatalogFile()

    /**
     * Read and validate one catalog file, returning its decoded entry.
     *
     * Returns null on any condition that makes the file unusable (unreadable,
     * invalid JSON, or missing slug/title) — the caller reports those as failed.
     *
     * @param string $file Absolute path to the JSON catalog file
     *
     * @return array<string, mixed>|null The decoded catalog entry, or null when unusable
     */
    private function loadCatalogEntry(string $file): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            $this->logger->error(
                'Procest: VTH workflow template — unable to read catalog file',
                ['app' => Application::APP_ID, 'file' => basename($file)]
            );
            return null;
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
            $this->logger->error(
                'Procest: VTH workflow template — invalid JSON in catalog file',
                ['app' => Application::APP_ID, 'file' => basename($file)]
            );
            return null;
        }

        $slug  = (string) ($data['slug'] ?? '');
        $title = (string) ($data['title'] ?? '');
        if ($slug === '' || $title === '') {
            $this->logger->warning(
                'Procest: VTH workflow template — missing slug or title',
                ['app' => Application::APP_ID, 'file' => basename($file)]
            );
            return null;
        }

        return $data;
    }//end loadCatalogEntry()

    /**
     * Resolve the caseType UUID and statusType map a template needs, applying
     * the idempotency check.
     *
     * Returns null for every soft-fail precondition — the caller reports those
     * as skipped.
     *
     * @param array<string, mixed> $data   The decoded catalog entry
     * @param string               $slug   The template slug
     * @param string               $title  The template title
     * @param IOutput              $output The output interface
     *
     * @return array<string, mixed>|null {caseTypeId, statusMap}, or null when the template must be skipped
     */
    private function resolveTemplateContext(array $data, string $slug, string $title, IOutput $output): ?array
    {
        $caseTypeSlug = (string) ($data['caseTypeSlug'] ?? '');
        if ($caseTypeSlug === '') {
            $this->logger->warning(
                'Procest: VTH workflow template — missing caseTypeSlug',
                ['app' => Application::APP_ID, 'slug' => $slug]
            );
            return null;
        }

        $caseTypeId = $this->resolveCaseTypeId(slug: $caseTypeSlug);
        if ($caseTypeId === '') {
            // Expected precondition on every boot until base-register-seed-data
            // has run (or while the anonymous repair context cannot read the
            // caseType) — debug, not warning, so it does not spam the log.
            $this->logger->debug(
                'Procest: VTH workflow template — caseType not found, skipping',
                [
                    'app'          => Application::APP_ID,
                    'slug'         => $slug,
                    'caseTypeSlug' => $caseTypeSlug,
                ]
            );
            $output->info(
                'VTH catalog: caseType "'.$caseTypeSlug.'" not found for template "'
                .$slug.'" — skipping (run base-register-seed-data first).'
            );
            return null;
        }

        // Idempotency: skip if a workflow template with the same title +
        // caseType is already present.
        if ($this->isAlreadySeeded(caseTypeId: $caseTypeId, title: $title) === true) {
            $this->logger->info(
                'Procest: VTH workflow template already present, skipping',
                [
                    'app'      => Application::APP_ID,
                    'slug'     => $slug,
                    'caseType' => $caseTypeId,
                ]
            );
            return null;
        }

        // Build the name → UUID map for statusTypes belonging to this caseType.
        $statusMap = $this->buildStatusMap(caseTypeId: $caseTypeId);
        if ($statusMap === []) {
            $this->logger->warning(
                'Procest: VTH workflow template — no statusTypes found for caseType',
                [
                    'app'      => Application::APP_ID,
                    'slug'     => $slug,
                    'caseType' => $caseTypeId,
                ]
            );
            return null;
        }

        return [
            'caseTypeId' => $caseTypeId,
            'statusMap'  => $statusMap,
        ];
    }//end resolveTemplateContext()

    /**
     * Resolve the steps[] and transitions[] blocks against the status map.
     *
     * Returns null when any status name does not resolve — the caller reports
     * that as skipped (no partial seed).
     *
     * @param array<string, mixed>  $data      The decoded catalog entry
     * @param string                $slug      The template slug
     * @param array<string, string> $statusMap Status name → UUID map
     *
     * @return array<string, mixed>|null {steps, transitions}, or null when unresolved
     */
    private function resolveWorkflowGraph(array $data, string $slug, array $statusMap): ?array
    {
        $resolvedSteps = $this->resolveSteps(
            slug: $slug,
            rawSteps: ($data['steps'] ?? []),
            statusMap: $statusMap,
        );
        if ($resolvedSteps === null) {
            $this->logger->warning(
                'Procest: VTH workflow template — unresolved status in steps, skipping',
                ['app' => Application::APP_ID, 'slug' => $slug]
            );
            return null;
        }

        $resolvedTransitions = $this->resolveTransitions(
            slug: $slug,
            rawTransitions: ($data['transitions'] ?? []),
            statusMap: $statusMap,
        );
        if ($resolvedTransitions === null) {
            $this->logger->warning(
                'Procest: VTH workflow template — unresolved status in transitions, skipping',
                ['app' => Application::APP_ID, 'slug' => $slug]
            );
            return null;
        }

        return [
            'steps'       => $resolvedSteps,
            'transitions' => $resolvedTransitions,
        ];
    }//end resolveWorkflowGraph()

    /**
     * Create the draft via the lifecycle service and publish it.
     *
     * @param array<string, mixed> $data       The decoded catalog entry
     * @param string               $slug       The template slug
     * @param string               $title      The template title
     * @param string               $caseTypeId The resolved caseType UUID
     * @param array<string, mixed> $graph      The resolved {steps, transitions}
     * @param IOutput              $output     The output interface
     *
     * @return string One of seeded|failed
     */
    private function createAndPublishTemplate(
        array $data,
        string $slug,
        string $title,
        string $caseTypeId,
        array $graph,
        IOutput $output
    ): string {
        // Create draft via the lifecycle service.
        $draft = $this->definitionService->createDraft(
            payload: [
                'title'       => $title,
                'description' => (string) ($data['description'] ?? ''),
                'caseType'    => $caseTypeId,
                'version'     => (int) ($data['version'] ?? 1),
                'steps'       => $graph['steps'],
                'transitions' => $graph['transitions'],
            ]
        );

        if ($draft === null || isset($draft['id']) === false) {
            $this->logger->error(
                'Procest: VTH workflow template — createDraft returned null',
                ['app' => Application::APP_ID, 'slug' => $slug]
            );
            return 'failed';
        }

        // Publish — flips to lifecycleStatus=published, isActive=true and
        // pins caseType.workflowDefinition only when no previous definition
        // was pinned (handled inside publish()).
        $published = $this->definitionService->publish(id: (string) $draft['id']);
        if ($published === null) {
            $this->logger->error(
                'Procest: VTH workflow template — publish returned null',
                ['app' => Application::APP_ID, 'slug' => $slug, 'draftId' => (string) $draft['id']]
            );
            return 'failed';
        }

        $output->info('VTH catalog: seeded "'.$title.'" v'.(int) ($data['version'] ?? 1).'.');
        return 'seeded';
    }//end createAndPublishTemplate()

    /**
     * Resolve a caseType by its slug — uses the `identifier` field on the
     * caseType schema (the canonical slug-like field across procest seed
     * data). Returns the empty string when not found.
     *
     * @param string $slug The caseType slug / identifier
     *
     * @return string The caseType UUID or empty string
     */
    private function resolveCaseTypeId(string $slug): string
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $register       = $this->settingsService->getConfigValue('register');
        $caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');

        if ($register === '' || $caseTypeSchema === '') {
            return '';
        }

        // Try `identifier` first (used by bezwaar/beroep seeds), then
        // `slug` (used by VTH seeds via base-register-seed-data).
        foreach (['identifier', 'slug'] as $field) {
            try {
                $rows = $this->searchObjectsAsArrays(
                    objectService: $objectService,
                    register: $register,
                    schema: $caseTypeSchema,
                    filters: [$field => $slug, '_limit' => 5],
                );
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'Procest: VTH workflow template — caseType lookup failed',
                    [
                        'app'       => Application::APP_ID,
                        'field'     => $field,
                        'slug'      => $slug,
                        'exception' => $e->getMessage(),
                    ]
                );
                continue;
            }

            $id = $this->extractFirstId(rows: $rows);
            if ($id !== '') {
                return $id;
            }
        }//end foreach

        return '';
    }//end resolveCaseTypeId()

    /**
     * Check whether a workflowTemplate with the given title is already
     * present for the given caseType. Used for idempotency.
     *
     * @param string $caseTypeId The caseType UUID
     * @param string $title      The template title
     *
     * @return bool True when an existing template matches
     */
    private function isAlreadySeeded(string $caseTypeId, string $title): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === '' || $schema === '') {
            return false;
        }

        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: [
                    'caseType' => $caseTypeId,
                    'title'    => $title,
                    '_limit'   => 1,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Procest: VTH workflow template — idempotency lookup failed',
                [
                    'app'       => Application::APP_ID,
                    'caseType'  => $caseTypeId,
                    'title'     => $title,
                    'exception' => $e->getMessage(),
                ]
            );
            return false;
        }//end try

        return $this->extractFirstId(rows: $rows) !== '';
    }//end isAlreadySeeded()

    /**
     * Build a status name → UUID map for the statusTypes belonging to a
     * given caseType.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return array<string, string> Map of statusType name to UUID
     */
    private function buildStatusMap(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register     = $this->settingsService->getConfigValue('register');
        $statusSchema = $this->settingsService->getConfigValue('status_type_schema');

        if ($register === '' || $statusSchema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $statusSchema,
                filters: ['caseType' => $caseTypeId, '_limit' => 500],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: VTH workflow template — statusType listing failed',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $rowsList = [];
        if (is_array($rows) === true) {
            $rowsList = $rows;
        }

        return $this->mapStatusTypeRows(rows: $rowsList);
    }//end buildStatusMap()

    /**
     * Reduce statusType rows to a name → UUID map, dropping unusable rows.
     *
     * @param array<int, mixed> $rows The raw statusType rows
     *
     * @return array<string, string> Map of statusType name to UUID
     */
    private function mapStatusTypeRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeRow(row: $row);
            if ($normalized === null) {
                continue;
            }

            $name = (string) ($normalized['name'] ?? '');
            $id   = (string) ($normalized['id'] ?? ($normalized['uuid'] ?? ''));
            if ($name !== '' && $id !== '') {
                $map[$name] = $id;
            }
        }

        return $map;
    }//end mapStatusTypeRows()

    /**
     * Resolve the steps[] block against the status name → UUID map.
     * Returns null when any status name does not resolve.
     *
     * @param string                           $slug      The template slug (for UUID5 ids)
     * @param array<int, array<string, mixed>> $rawSteps  Steps from the catalog file
     * @param array<string, string>            $statusMap Name → UUID
     *                                                    map
     *
     * @return array<int, array<string, mixed>>|null Resolved steps, or null
     */
    private function resolveSteps(string $slug, array $rawSteps, array $statusMap): ?array
    {
        $resolved = [];
        foreach ($rawSteps as $step) {
            if (is_array($step) === false) {
                continue;
            }

            $statusName = (string) ($step['statusName'] ?? '');
            if ($statusName === '' || isset($statusMap[$statusName]) === false) {
                return null;
            }

            $stepSlug   = (string) ($step['slug'] ?? '');
            $resolved[] = [
                'id'           => $this->deterministicId(template: $slug, child: 'step-'.$stepSlug),
                'slug'         => $stepSlug,
                'title'        => (string) ($step['title'] ?? ''),
                'status'       => $statusMap[$statusName],
                'statusName'   => $statusName,
                'order'        => (int) ($step['order'] ?? 0),
                'isInitial'    => (bool) ($step['isInitial'] ?? false),
                'isFinal'      => (bool) ($step['isFinal'] ?? false),
                'assigneeRole' => ($step['assigneeRole'] ?? null),
                'description'  => (string) ($step['description'] ?? ''),
            ];
        }//end foreach

        return $resolved;
    }//end resolveSteps()

    /**
     * Resolve the transitions[] block against the status name → UUID map.
     * Accepts "*" as a wildcard for fromStatus (any status). Returns null
     * when any non-wildcard status name does not resolve.
     *
     * @param string                           $slug           The template slug (for UUID5 ids)
     * @param array<int, array<string, mixed>> $rawTransitions Transitions from the catalog file
     * @param array<string, string>            $statusMap      Name → UUID
     *                                                         map
     *
     * @return array<int, array<string, mixed>>|null Resolved transitions, or null
     */
    private function resolveTransitions(string $slug, array $rawTransitions, array $statusMap): ?array
    {
        $resolved = [];
        foreach ($rawTransitions as $transition) {
            if (is_array($transition) === false) {
                continue;
            }

            $fromName = (string) ($transition['fromStatus'] ?? '');
            $toName   = (string) ($transition['toStatus'] ?? '');

            if ($toName === '' || isset($statusMap[$toName]) === false) {
                return null;
            }

            $fromId = '*';
            if ($fromName !== '*') {
                if ($fromName === '' || isset($statusMap[$fromName]) === false) {
                    return null;
                }

                $fromId = $statusMap[$fromName];
            }

            $transitionSlug = (string) ($transition['slug'] ?? '');
            $resolved[]     = [
                'id'               => $this->deterministicId(template: $slug, child: 'transition-'.$transitionSlug),
                'slug'             => $transitionSlug,
                'label'            => (string) ($transition['label'] ?? ''),
                'fromStatus'       => $fromId,
                'fromStatusName'   => $fromName,
                'toStatus'         => $statusMap[$toName],
                'toStatusName'     => $toName,
                'allowedRoles'     => ($transition['allowedRoles'] ?? []),
                'guards'           => ($transition['guards'] ?? []),
                'automaticActions' => ($transition['automaticActions'] ?? []),
                'deadline'         => ($transition['deadline'] ?? null),
            ];
        }//end foreach

        return $resolved;
    }//end resolveTransitions()

    /**
     * Generate a deterministic UUID5 from a template slug + child slug.
     * Re-running the repair step therefore produces stable step / transition
     * ids per template.
     *
     * @param string $template The template slug
     * @param string $child    The child slug (e.g. "step-ontvangen")
     *
     * @return string The deterministic UUID5
     */
    private function deterministicId(string $template, string $child): string
    {
        $namespace = str_replace('-', '', self::NS_UUID);
        $nameBytes = hex2bin($namespace).$template.':'.$child;
        $hash      = sha1($nameBytes);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }//end deterministicId()

    /**
     * Extract the first row id from an OpenRegister result set.
     *
     * @param mixed $rows Raw result from searchObjectsAsArrays()
     *
     * @return string The first id or empty string
     */
    private function extractFirstId(mixed $rows): string
    {
        if (is_array($rows) === false) {
            return '';
        }

        // Handle paginated `{ results: [...] }` shape.
        if (isset($rows['results']) === true && is_array($rows['results']) === true) {
            $rows = $rows['results'];
        }

        foreach ($rows as $row) {
            $normalized = $this->normalizeRow(row: $row);
            if ($normalized === null) {
                continue;
            }

            $id = (string) ($normalized['id'] ?? ($normalized['uuid'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }//end extractFirstId()

    /**
     * Coerce an OpenRegister result row to an associative array.
     *
     * @param mixed $row Result row from ObjectService
     *
     * @return array<string, mixed>|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $serialized = $row->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($row) === true && method_exists($row, 'getId') === true) {
            return ['id' => (string) $row->getId()];
        }

        return null;
    }//end normalizeRow()
}//end class
