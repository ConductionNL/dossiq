<?php

/**
 * Procest Reset Monthly Quotas Job.
 *
 * Daily background job that scans tenant quotas and resets those whose
 * `resetAt` window has elapsed.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\TenantQuotaService;
use OCA\Procest\Service\TenantSaasService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resets monthly + hourly quotas after their window elapses.
 */
class ResetMonthlyQuotasJob extends TimedJob
{
    /**
     * Interval — once per day.
     */
    private const INTERVAL_SECONDS = 86400;

    public function __construct(
        ITimeFactory $time,
        private readonly TenantQuotaService $quotaService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
    }

    /**
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return;
        }

        try {
            $os = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->info('Procest: ResetMonthlyQuotasJob — OR ObjectService unavailable');
            return;
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'tenantQuota',
                limit: 1000,
                offset: 0,
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: ResetMonthlyQuotasJob fetch failed', ['exception' => $e->getMessage()]);
            return;
        }

        if (is_array($rows) === false) {
            return;
        }

        $resetCount = 0;
        foreach ($rows as $row) {
            $before = (int) ($row['currentUsage'] ?? 0);
            $after  = $this->quotaService->resetIfDue($row);
            if ((int) ($after['currentUsage'] ?? 0) === 0 && $before > 0) {
                $resetCount++;
            }
        }

        if ($resetCount > 0) {
            $this->logger->info('Procest: ResetMonthlyQuotasJob reset '.$resetCount.' quotas');
        }
    }
}
