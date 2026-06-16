<?php

/**
 * Procest Email PDF Retry Job
 *
 * Periodic retry of `pdfStatus: failed` archival rows. Backs off
 * exponentially via the `pdfAttempts` field maintained by the
 * archival service; gives up after 3 attempts.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\EmailArchivalService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Retries failed PDF archival attempts on a 15-minute cadence.
 */
class EmailPdfRetryJob extends TimedJob
{

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time            Time factory.
     * @param IAppManager          $appManager      App manager.
     * @param EmailArchivalService $archivalService Archival service.
     * @param LoggerInterface      $logger          Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppManager $appManager,
        private readonly EmailArchivalService $archivalService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // 15 minutes.
        $this->setInterval(seconds: 900);
    }//end __construct()


    /**
     * Run a retry pass.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T09
     */
    protected function run($argument): void
    {
        try {
            if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
                return;
            }

            $failed = $this->archivalService->listFailedArchivals(limit: 25);
            foreach ($failed as $row) {
                $archivalId = (string) ($row['archivalId'] ?? '');
                if ($archivalId === '') {
                    continue;
                }

                // Real PDF conversion is delegated to Docudesk via an adapter;
                // here we simply re-mark as failed so the attempt count climbs.
                // When Docudesk wiring lands, replace this block with the
                // adapter invocation + markComplete()/markFailed() branching.
                $this->archivalService->markFailed(
                    archivalId: $archivalId,
                    errorMessage: 'retry pending Docudesk adapter wiring'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'EmailPdfRetryJob failed',
                ['error' => $e->getMessage(), 'app' => Application::APP_ID]
            );
        }
    }//end run()
}//end class
