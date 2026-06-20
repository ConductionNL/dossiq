<?php

/**
 * Procest Archival Submission Retry Job.
 *
 * 5-minute TimedJob that drains the failed-submission queue from
 * `overdrachtTransactie` via {@see ArchivalSubmissionRetryService}.
 * Backoff windows (1m → 5m → 30m → 2h → 8h) are enforced by the
 * service; the fifth consecutive failure is escalated to the DIV
 * audit channel.
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
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\ArchivalSubmissionRetryService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * 5-minute timed job: drain the failed-submission retry queue.
 */
class ArchivalSubmissionRetryJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory                   $time         Time factory.
     * @param ArchivalSubmissionRetryService $retryService Retry orchestrator.
     * @param IAppManager                    $appManager   App manager.
     * @param LoggerInterface                $logger       Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ArchivalSubmissionRetryService $retryService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // 5-minute cadence as per the spec backoff curve (1m → 5m → 30m
        // → 2h → 8h). The lower bound is one cron tick; NC's job runner
        // will pick the next-available window beyond this interval.
        $this->setInterval(seconds: 300);
    }//end __construct()

    /**
     * Run the retry sweep.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return;
        }

        try {
            $counts = $this->retryService->processRetryQueue();
            $this->logger->info('ArchivalSubmissionRetryJob: drain finished', $counts);
        } catch (\Throwable $e) {
            $this->logger->error('ArchivalSubmissionRetryJob: drain failed', ['error' => $e->getMessage()]);
        }
    }//end run()
}//end class
