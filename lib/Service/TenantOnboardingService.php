<?php

/**
 * Procest Tenant Onboarding Service
 *
 * Owns the per-tenant onboarding checklist — fork the 7-step template,
 * track progress, mark steps complete, validate go-live readiness.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Onboarding workflow service.
 */
class TenantOnboardingService
{
    /**
     * The seven canonical onboarding steps.
     */
    public const STEPS = [
        'contract',
        'mandate_import',
        'sso_setup',
        'branding',
        'zaaktype_selection',
        'first_user',
        'go_live',
    ];

    /**
     * Constructor.
     *
     * @param TenantSaasService  $tenantSaasService Tenant SaaS service.
     * @param IAppManager        $appManager        App manager.
     * @param ContainerInterface $container         Service container.
     * @param LoggerInterface    $logger            Logger.
     */
    public function __construct(
        private readonly TenantSaasService $tenantSaasService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fork the default 7-step template into the tenant's onboarding list.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array<int, array<string, mixed>> Created task rows.
     */
    public function createOnboarding(string $tenantId): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            $this->logger->info('Procest: createOnboarding skipped — OR unavailable');
            return [];
        }

        $created = [];
        foreach (self::STEPS as $step) {
            try {
                $row = $os->saveObject(
                    object: ['tenantRef' => $tenantId, 'step' => $step, 'status' => 'pending'],
                    register: TenantSaasService::REGISTER,
                    schema: 'tenantOnboardingTask',
                    uuid: null
                );
                if (is_array($row) === true) {
                    $created[] = $row;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'Procest: createOnboarding step write failed',
                    ['tenantId' => $tenantId, 'step' => $step, 'exception' => $e->getMessage()]
                );
            }
        }

        return $created;
    }//end createOnboarding()

    /**
     * Get the per-step progress and overall completion fraction.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array{steps: array<int, array<string, mixed>>, completed: int, total: int, fraction: float}
     */
    public function getProgress(string $tenantId): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return ['steps' => [], 'completed' => 0, 'total' => count(self::STEPS), 'fraction' => 0.0];
        }

        try {
            // ObjectService::findAll() takes a single $config array — the previous
            // named-argument form threw "Unknown named parameter $register" and
            // was swallowed by the catch below. Register/schema are read from
            // inside `filters`.
            $rows = $os->findAll(
                [
                    'filters' => [
                        'register'  => TenantSaasService::REGISTER,
                        'schema'    => 'tenantOnboardingTask',
                        'tenantRef' => $tenantId,
                    ],
                    'limit'   => 100,
                    'offset'  => 0,
                ]
            );
        } catch (Throwable $e) {
            $rows = [];
        }

        if (is_array($rows) === false) {
            $rows = [];
        }

        $completed = 0;
        foreach ($rows as $r) {
            if ((string) ($r['status'] ?? '') === 'completed') {
                $completed++;
            }
        }

        $total = max(count(self::STEPS), count($rows));
        if ($total === 0) {
            $fraction = 0.0;
        } else {
            $fraction = ($completed / $total);
        }

        return [
            'steps'     => array_values($rows),
            'completed' => $completed,
            'total'     => $total,
            'fraction'  => round($fraction, 2),
        ];
    }//end getProgress()

    /**
     * Mark a step as completed.
     *
     * @param string $tenantId    Tenant UUID.
     * @param string $step        Step name.
     * @param string $completedBy NC user ID who completed it.
     *
     * @return array<string,mixed>|null Updated task row.
     *
     * @throws InvalidArgumentException On invalid step.
     */
    public function markStepComplete(string $tenantId, string $step, string $completedBy): ?array
    {
        if (in_array($step, self::STEPS, true) === false) {
            throw new InvalidArgumentException('Unknown onboarding step: '.$step);
        }

        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            // ObjectService::findAll() takes a single $config array — see the
            // note in getProgress(); register/schema live inside `filters`.
            $rows = $os->findAll(
                [
                    'filters' => [
                        'register'  => TenantSaasService::REGISTER,
                        'schema'    => 'tenantOnboardingTask',
                        'tenantRef' => $tenantId,
                        'step'      => $step,
                    ],
                    'limit'   => 1,
                    'offset'  => 0,
                ]
            );
            if (is_array($rows) === false || count($rows) === 0) {
                return null;
            }

            $task           = $rows[0];
            $task['status'] = 'completed';
            $task['completedBy'] = $completedBy;
            $task['completedAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);

            $uuid = (string) ($task['uuid'] ?? $task['id'] ?? '');
            if ($uuid !== '') {
                $uuidArg = $uuid;
            } else {
                $uuidArg = null;
            }

            $row = $os->saveObject(
                object: $task,
                register: TenantSaasService::REGISTER,
                schema: 'tenantOnboardingTask',
                uuid: $uuidArg
            );
            if (is_array($row) === true) {
                return $row;
            }

            return $task;
        } catch (Throwable $e) {
            $this->logger->error('Procest: markStepComplete failed', ['exception' => $e->getMessage()]);
            return null;
        }//end try
    }//end markStepComplete()

    /**
     * Validate that the tenant is ready to go live.
     *
     * Acceptance criteria: ≥1 zaaktype, ≥1 mandate, ≥1 tenant_admin user.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array{ready: bool, missing: array<int, string>}
     */
    public function validateGoLive(string $tenantId): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return ['ready' => false, 'missing' => ['openregister_unavailable']];
        }

        $missing = [];
        if ($this->countSchemaRows(os: $os, schema: 'caseType', filters: ['tenantRef' => $tenantId]) === 0) {
            $missing[] = 'zaaktype';
        }

        if ($this->countSchemaRows(os: $os, schema: 'tenantMandate', filters: ['tenantRef' => $tenantId]) === 0) {
            $missing[] = 'mandate';
        }

        if ($this->countSchemaRows(
            os: $os,
            schema: 'tenantUser',
            filters: ['tenantRef' => $tenantId, 'role' => 'tenant_admin']
        ) === 0
        ) {
            $missing[] = 'tenant_admin';
        }

        return ['ready' => count($missing) === 0, 'missing' => $missing];
    }//end validateGoLive()

    /**
     * Trigger the activation flow when go-live validates.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array{activated: bool, missing?: array<int, string>}
     */
    public function activate(string $tenantId): array
    {
        $check = $this->validateGoLive(tenantId: $tenantId);
        if ($check['ready'] === false) {
            return ['activated' => false, 'missing' => $check['missing']];
        }

        try {
            $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'active');
        } catch (Throwable $e) {
            $this->logger->error('Procest: activation transition failed', ['exception' => $e->getMessage()]);
            return ['activated' => false, 'missing' => ['transition_failed']];
        }

        return ['activated' => true];
    }//end activate()

    /**
     * Count rows in a schema with a filter.
     *
     * @param mixed               $os      Object service.
     * @param string              $schema  Schema slug.
     * @param array<string,mixed> $filters Filters.
     *
     * @return int
     */
    private function countSchemaRows($os, string $schema, array $filters): int
    {
        try {
            // ObjectService::findAll() takes a single $config array — see the
            // note in getProgress(); register/schema live inside `filters`.
            $rows = $os->findAll(
                [
                    'filters' => array_merge(
                        [
                            'register' => TenantSaasService::REGISTER,
                            'schema'   => $schema,
                        ],
                        $filters
                    ),
                    'limit'   => 1,
                    'offset'  => 0,
                ]
            );
            if (is_array($rows) === true) {
                return count($rows);
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }//end try
    }//end countSchemaRows()

    /**
     * Resolve the OpenRegister object service, or null when unavailable.
     *
     * @return mixed|null
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }//end getObjectService()
}//end class
