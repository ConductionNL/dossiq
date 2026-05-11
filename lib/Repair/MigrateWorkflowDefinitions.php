<?php

/**
 * Procest Migrate Workflow Definitions Repair Step
 *
 * Backfill repair step that promotes the implicit lifecycle of every
 * existing caseType into a seeded workflowTemplate published as
 * version 1. Idempotent — skips caseTypes that already have a
 * workflowDefinition reference set.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Backfill workflowTemplate objects from implicit statusType ordering.
 */
class MigrateWorkflowDefinitions implements IRepairStep
{


    /**
     * Constructor.
     *
     * @param SettingsService           $settingsService The settings service
     * @param WorkflowDefinitionService $workflowService The workflow definition service
     * @param LoggerInterface           $logger          The logger interface
     */
    public function __construct(
        private SettingsService $settingsService,
        private WorkflowDefinitionService $workflowService,
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
        return 'Backfill workflowTemplate definitions for existing caseTypes';
    }//end getName()


    /**
     * Run the backfill.
     *
     * @param IOutput $output Repair output channel
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available — skipping workflow backfill.');
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->warning('OpenRegister ObjectService not resolvable — skipping workflow backfill.');
            return;
        }

        $register        = $this->settingsService->getConfigValue('register');
        $caseTypeSchema  = $this->settingsService->getConfigValue('case_type_schema');
        $statusSchema    = $this->settingsService->getConfigValue('status_type_schema');
        $templateSchema  = $this->settingsService->getConfigValue('workflow_template_schema');
        $caseSchema      = $this->settingsService->getConfigValue('case_schema');

        if ($register === ''
            || $caseTypeSchema === ''
            || $statusSchema === ''
            || $templateSchema === ''
        ) {
            $output->warning('Workflow backfill: required schema configuration missing — skipping.');
            return;
        }

        try {
            $caseTypes = $objectService->findObjects($register, $caseTypeSchema, [], [], 500);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: workflow backfill failed to list caseTypes',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            $output->warning('Could not list caseTypes — skipping workflow backfill.');
            return;
        }

        if (is_array($caseTypes) === false) {
            return;
        }

        $migrated = 0;
        $skipped  = 0;
        foreach ($caseTypes as $caseType) {
            $row = $this->normalize($caseType);
            if ($row === null) {
                continue;
            }

            $caseTypeId = (string) ($row['id'] ?? '');
            if ($caseTypeId === '') {
                continue;
            }

            if ((string) ($row['workflowDefinition'] ?? '') !== '') {
                $skipped++;
                continue;
            }

            // Already has at least one workflowTemplate? Skip — admin
            // will set the pin via the UI.
            $existing = $this->workflowService->listVersions($caseTypeId);
            if ($existing !== []) {
                $skipped++;
                continue;
            }

            $template = $this->buildTemplateFor(
                $caseTypeId,
                $row,
                $objectService,
                $register,
                $statusSchema,
            );

            if ($template === null) {
                continue;
            }

            try {
                $created = $objectService->saveObject(
                    $register,
                    $templateSchema,
                    $template,
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: workflow backfill failed to save template',
                    ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
                );
                continue;
            }

            $createdNormalized = $this->normalize($created);
            $newId = (string) ($createdNormalized['id'] ?? '');

            // Pin the caseType to the new template.
            if ($newId !== '') {
                try {
                    $objectService->saveObject(
                        $register,
                        $caseTypeSchema,
                        ['workflowDefinition' => $newId],
                        $caseTypeId,
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Procest: workflow backfill failed to pin caseType',
                        ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
                    );
                }

                // Pin existing open cases to workflowVersion = 1.
                if ($caseSchema !== '') {
                    $this->pinOpenCases(
                        $objectService,
                        $register,
                        $caseSchema,
                        $caseTypeId,
                        $newId,
                    );
                }
            }

            $migrated++;
        }//end foreach

        $output->info(
            'Workflow backfill complete — migrated '.$migrated.', skipped '.$skipped.'.'
        );
    }//end run()


    /**
     * Build a workflowTemplate payload from a caseType's statusType
     * records.
     *
     * @param string                                                $caseTypeId    The caseType UUID
     * @param array<string, mixed>                                  $caseType      Normalized caseType row
     * @param object                                                $objectService Resolved OR ObjectService
     * @param string                                                $register      The register id
     * @param string                                                $statusSchema  The statusType schema id
     *
     * @return array<string, mixed>|null
     */
    private function buildTemplateFor(
        string $caseTypeId,
        array $caseType,
        object $objectService,
        string $register,
        string $statusSchema,
    ): ?array {
        try {
            $statusRows = $objectService->findObjects(
                $register,
                $statusSchema,
                ['caseType' => $caseTypeId],
                [],
                500,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: workflow backfill failed to list statusTypes',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if (is_array($statusRows) === false || $statusRows === []) {
            return null;
        }

        $statuses = [];
        foreach ($statusRows as $raw) {
            $row = $this->normalize($raw);
            if ($row !== null && (string) ($row['id'] ?? '') !== '') {
                $statuses[] = $row;
            }
        }

        if ($statuses === []) {
            return null;
        }

        usort(
            $statuses,
            static function (array $a, array $b): int {
                return (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0);
            },
        );

        $steps = [];
        foreach ($statuses as $status) {
            if ((bool) ($status['isFinal'] ?? false) === true) {
                continue;
            }

            $steps[] = [
                'id'           => $this->uuid(),
                'title'        => (string) ($status['name'] ?? 'Stap'),
                'description'  => (string) ($status['description'] ?? ''),
                'status'       => (string) ($status['id'] ?? ''),
                'order'        => (int) ($status['order'] ?? 0),
                'assigneeRole' => '',
                'isRequired'   => false,
                'checklist'    => [],
                'automaticActions' => [],
            ];
        }

        $transitions = [];
        $count       = count($statuses);
        for ($i = 0; $i < ($count - 1); $i++) {
            $from = $statuses[$i];
            $to   = $statuses[($i + 1)];

            $transitions[] = [
                'id'         => $this->uuid(),
                'fromStatus' => (string) ($from['id'] ?? ''),
                'toStatus'   => (string) ($to['id'] ?? ''),
                'label'      => (string) ($to['name'] ?? 'Door'),
                'guards'     => [],
                'automaticActions' => [],
                'allowedRoles' => [],
            ];
        }

        $title = trim((string) ($caseType['title'] ?? 'Workflow'));
        if ($title === '') {
            $title = 'Workflow';
        }

        return [
            'title'           => $title.' — basis',
            'description'    => 'Backfilled from implicit statusType ordering.',
            'caseType'        => $caseTypeId,
            'version'         => 1,
            'isActive'        => true,
            'isDraft'         => false,
            'lifecycleStatus' => WorkflowDefinitionService::STATUS_PUBLISHED,
            'steps'           => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transitions'     => json_encode($transitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'nodePositions'   => '',
        ];
    }//end buildTemplateFor()


    /**
     * Pin every open case of a caseType to workflowVersion 1 and bind it
     * to the new workflowTemplate.
     *
     * @param object $objectService The OR ObjectService
     * @param string $register      The register id
     * @param string $caseSchema    The case schema id
     * @param string $caseTypeId    The caseType UUID
     * @param string $templateId    The new template UUID
     *
     * @return void
     */
    private function pinOpenCases(
        object $objectService,
        string $register,
        string $caseSchema,
        string $caseTypeId,
        string $templateId,
    ): void {
        try {
            $cases = $objectService->findObjects(
                $register,
                $caseSchema,
                ['caseType' => $caseTypeId],
                [],
                500,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: workflow backfill failed to list cases',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return;
        }

        if (is_array($cases) === false) {
            return;
        }

        foreach ($cases as $row) {
            $case = $this->normalize($row);
            if ($case === null) {
                continue;
            }

            $caseId = (string) ($case['id'] ?? '');
            if ($caseId === '') {
                continue;
            }

            // Skip already-pinned cases.
            if ((string) ($case['workflowTemplate'] ?? '') !== '') {
                continue;
            }

            try {
                $objectService->saveObject(
                    $register,
                    $caseSchema,
                    [
                        'workflowTemplate' => $templateId,
                        'workflowVersion'  => 1,
                    ],
                    $caseId,
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: workflow backfill failed to pin case',
                    ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
                );
            }
        }
    }//end pinOpenCases()


    /**
     * Coerce an OpenRegister result row to an associative array.
     *
     * @param mixed $row Result row from ObjectService
     *
     * @return array<string, mixed>|null
     */
    private function normalize(mixed $row): ?array
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

        return null;
    }//end normalize()


    /**
     * Generate a UUID v4 for embedded step / transition identifiers.
     *
     * @return string
     */
    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }//end uuid()


}//end class
