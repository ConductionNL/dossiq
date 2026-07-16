<?php

/**
 * Procest Seed Bezwaar Workflow Definition Repair Step
 *
 * Idempotently seeds a published workflowTemplate for the pre-seeded
 * Bezwaar caseType, expressing the AWB-grounded state machine
 * (Ontvangen → Ontvankelijkheidstoets → ...) plus legal-posture
 * guards (verdaging/opschorting/niet-ontvankelijk/intrekking require
 * a non-empty awbReference). All status transitions flow through the
 * status-transition-engine; there is NO bespoke BezwaarController or
 * BezwaarLifecycleService.
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
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
 * Seed the canonical bezwaar workflow definition (published, version 1).
 */
class SeedBezwaarWorkflowDefinition implements IRepairStep
{

    use SearchesObjects;


    /**
     * Required guards for transitions that change legal posture
     * — keyed by toStatus name, value is the human reason key.
     *
     * @var array<string, string>
     */
    private const LEGAL_POSTURE_TARGETS = [
        'Niet-ontvankelijk' => 'Niet-ontvankelijk vergt AWB-motivering (6:6)',
        'Ingetrokken'       => 'Intrekking vergt AWB-motivering (6:21)',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService           $settingsService The settings service
     * @param WorkflowDefinitionService $workflowService The workflow definition service
     * @param LoggerInterface           $logger          Logger
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
        return 'Seed canonical bezwaar workflow definition (AWB-compliant state machine)';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output Repair output channel
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function run(IOutput $output): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available — skipping bezwaar workflow seed.');
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->warning('OpenRegister ObjectService not resolvable — skipping bezwaar workflow seed.');
            return;
        }

        $register       = $this->settingsService->getConfigValue('register');
        $caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
        $statusSchema   = $this->settingsService->getConfigValue('status_type_schema');
        $templateSchema = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === ''
            || $caseTypeSchema === ''
            || $statusSchema === ''
            || $templateSchema === ''
        ) {
            $output->warning('Bezwaar workflow seed: required schema config missing — skipping.');
            return;
        }

        // Locate the bezwaar caseType.
        try {
            $caseTypes = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $caseTypeSchema,
                filters: ['identifier' => 'bezwaar', '_limit' => 5],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: bezwaar workflow seed — failed to list caseTypes',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            $output->warning('Could not list caseTypes — skipping bezwaar workflow seed.');
            return;
        }

        if ($caseTypes === []) {
            $output->info('Bezwaar caseType not present yet — skipping workflow seed.');
            return;
        }

        $caseType = $this->normalize(object: $caseTypes[0]);
        if ($caseType === null) {
            return;
        }

        $caseTypeId = (string) ($caseType['id'] ?? '');
        if ($caseTypeId === '') {
            return;
        }

        // Idempotent guard.
        $existingVersions = $this->workflowService->listVersions($caseTypeId);
        if ($existingVersions !== []) {
            $output->info('Bezwaar workflow definition already present — skipping seed.');
            return;
        }

        // Pull statusType rows for the bezwaar caseType.
        try {
            $statusRows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $statusSchema,
                filters: ['caseType' => $caseTypeId, '_limit' => 50],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: bezwaar workflow seed — failed to list statusTypes',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return;
        }

        if ($statusRows === []) {
            $output->info('Bezwaar statusTypes missing — skipping workflow seed.');
            return;
        }

        $statusByName = [];
        foreach ($statusRows as $raw) {
            $row = $this->normalize(object: $raw);
            if ($row === null) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $id   = (string) ($row['id'] ?? '');
            if ($name !== '' && $id !== '') {
                $statusByName[$name] = $row;
            }
        }

        $required = [
            'Ontvangen',
            'Ontvankelijkheidstoets',
            'In behandeling',
            'Hoorzitting gepland',
            'Hoorzitting afgerond',
            'Advies uitgebracht',
            'Beslissing op bezwaar',
            'Afgehandeld',
            'Niet-ontvankelijk',
            'Ingetrokken',
        ];

        foreach ($required as $name) {
            if (isset($statusByName[$name]) === false) {
                $output->warning('Bezwaar workflow seed: missing statusType "'.$name.'" — skipping seed.');
                return;
            }
        }

        $steps       = $this->buildSteps(statusByName: $statusByName, ordered: $required);
        $transitions = $this->buildTransitions(statusByName: $statusByName);

        $description = 'Canonical bezwaar lifecycle state machine: Ontvangen → Afgehandeld with terminal '
            .'Niet-ontvankelijk/Ingetrokken. Transitions wired through the status-transition-engine; '
            .'deadlines computed declaratively on the bezwaar schema (x-openregister-calculations, ADR-022).';

        $template = [
            'title'           => 'Bezwaar — AWB-compliant workflow',
            'description'     => $description,
            'caseType'        => $caseTypeId,
            'version'         => 1,
            'isActive'        => true,
            'isDraft'         => false,
            'lifecycleStatus' => WorkflowDefinitionService::STATUS_PUBLISHED,
            'steps'           => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transitions'     => json_encode($transitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'nodePositions'   => '',
        ];

        try {
            $created = $objectService->saveObject(
                object: $template,
                register: $register,
                schema: $templateSchema,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: bezwaar workflow seed — failed to save workflowTemplate',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            $output->warning('Bezwaar workflow seed: save failed — see log.');
            return;
        }

        $createdNormalized = $this->normalize(object: $created);
        $newId = (string) ($createdNormalized['id'] ?? '');

        if ($newId !== '') {
            try {
                $objectService->saveObject(
                    object: ['workflowDefinition' => $newId],
                    register: $register,
                    schema: $caseTypeSchema,
                    uuid: (string) $caseTypeId,
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: bezwaar workflow seed — failed to pin caseType',
                    ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
                );
            }
        }

        $output->info('Seeded canonical bezwaar workflow definition.');
    }//end run()

    /**
     * Build step records from statusType rows.
     *
     * @param array<string, array<string, mixed>> $statusByName Status rows indexed by name
     * @param array<int, string>                  $ordered      Ordered status names
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSteps(array $statusByName, array $ordered): array
    {
        $steps = [];
        $order = 1;
        foreach ($ordered as $name) {
            $row     = $statusByName[$name];
            $steps[] = [
                'id'               => $this->uuid(),
                'title'            => $name,
                'description'      => (string) ($row['description'] ?? ''),
                'status'           => (string) ($row['id'] ?? ''),
                'order'            => $order,
                'assigneeRole'     => '',
                'isRequired'       => false,
                'checklist'        => [],
                'automaticActions' => [],
            ];
            $order++;
        }

        return $steps;
    }//end buildSteps()

    /**
     * Build the bezwaar state-machine transition matrix.
     *
     * @param array<string, array<string, mixed>> $statusByName Status rows indexed by name
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTransitions(array $statusByName): array
    {
        $matrix = [
            ['Ontvangen',              'Ontvankelijkheidstoets', 'Intake compleet'],
            ['Ontvankelijkheidstoets', 'In behandeling',         'Ontvankelijk'],
            ['Ontvankelijkheidstoets', 'Niet-ontvankelijk',      'Niet-ontvankelijk (motivering vereist)'],
            ['In behandeling',         'Hoorzitting gepland',    'Hoorzitting ingepland'],
            ['In behandeling',         'Advies uitgebracht',     'Hoorrecht afgezien (rechtstreeks advies)'],
            ['In behandeling',         'Beslissing op bezwaar',  'Hoorrecht afgezien (rechtstreeks beslissing)'],
            ['Hoorzitting gepland',    'Hoorzitting afgerond',   'Hoorzitting uitgevoerd'],
            ['Hoorzitting afgerond',   'Advies uitgebracht',     'Advies uitgebracht'],
            ['Hoorzitting afgerond',   'Beslissing op bezwaar',  'Geen commissie — direct beslissing'],
            ['Advies uitgebracht',     'Beslissing op bezwaar',  'Beslissing genomen'],
            ['Beslissing op bezwaar',  'Afgehandeld',            'Beslissing verzonden'],
            ['*',                      'Ingetrokken',            'Bezwaar ingetrokken (AWB 6:21)'],
        ];

        $transitions = [];
        foreach ($matrix as $row) {
            [$fromName, $toName, $label] = $row;
            $fromId = '*';
            if ($fromName !== '*') {
                $fromId = (string) ($statusByName[$fromName]['id'] ?? '');
            }

            $toId = (string) ($statusByName[$toName]['id'] ?? '');

            $guards = [];
            if (isset(self::LEGAL_POSTURE_TARGETS[$toName]) === true) {
                $guards[] = [
                    'type'   => 'requiredField',
                    'config' => [
                        'field'   => 'awbReference',
                        'message' => self::LEGAL_POSTURE_TARGETS[$toName],
                    ],
                ];
            }

            $transitions[] = [
                'id'               => $this->uuid(),
                'fromStatus'       => $fromId,
                'toStatus'         => $toId,
                'label'            => $label,
                'guards'           => $guards,
                'automaticActions' => [],
                'allowedRoles'     => [],
            ];
        }//end foreach

        return $transitions;
    }//end buildTransitions()

    /**
     * Normalize an OR object into a flat array.
     *
     * @param mixed $object Object or array
     *
     * @return array<string, mixed>|null
     */
    private function normalize(mixed $object): ?array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return null;
        }

        return null;
    }//end normalize()

    /**
     * Generate a v4 UUID.
     *
     * @return string
     */
    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end uuid()
}//end class
