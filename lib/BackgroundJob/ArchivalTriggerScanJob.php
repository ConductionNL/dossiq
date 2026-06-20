<?php

/**
 * Procest Archival Trigger Scan Job.
 *
 * Nightly TimedJob that drives the archief-edepot detection sweep.
 * For every closed case in OpenRegister it asks ArchivalTriggerService
 * to assert/refresh the matching OverdrachtTrigger row (ready /
 * blocked / suspended). The sweep is idempotent — the underlying
 * service upserts triggers in place — so a missed run is recovered
 * the next night without back-fill.
 *
 * The detection-window cadence is daily (86400s); the underlying
 * ArchivalTriggerService::detectReadyCases call walks OpenRegister
 * pagination so per-batch cost stays bounded.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
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
 * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly timed job: archief-edepot retention-trigger detection.
 */
class ArchivalTriggerScanJob extends TimedJob
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param ITimeFactory           $time            Time factory.
     * @param ArchivalTriggerService $archivalService Detection service.
     * @param SettingsService        $settingsService Settings + ObjectService resolver.
     * @param IAppManager            $appManager      App manager.
     * @param LoggerInterface        $logger          Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ArchivalTriggerService $archivalService,
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Nightly: 24h cadence (NC picks the actual window).
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the nightly retention-trigger sweep.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $caseSchema    = (string) $this->settingsService->getConfigValue('case_schema');
        if ($objectService === null || $register === '' || $caseSchema === '') {
            $this->logger->info('ArchivalTriggerScanJob: case schema offline');
            return;
        }

        try {
            $closed = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                filters: ['status' => 'closed'],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ArchivalTriggerScanJob: case query failed', ['error' => $e->getMessage()]);
            return;
        }

        try {
            $counts = $this->archivalService->detectReadyCases((array) $closed);
            $this->logger->info('ArchivalTriggerScanJob: sweep finished', $counts);
        } catch (\Throwable $e) {
            $this->logger->error('ArchivalTriggerScanJob: sweep failed', ['error' => $e->getMessage()]);
        }
    }//end run()
}//end class
