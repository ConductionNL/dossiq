<?php

/**
 * Procest Archival Job.
 *
 * Queued job that archives a single beschikking. It is enqueued by the
 * BezwaarTermijnJob (or manually) with a `beschikkingId` argument, and
 * delegates the TMLO/MDTO metadata generation, OpenRegister ingest, and the
 * `gearchiveerd` state transition to BeschikkingService::archive — which is
 * idempotent because the state-machine rejects a transition out of an already
 * gearchiveerd beschikking.
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\BeschikkingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Archives a single beschikking when enqueued with its id.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T13
 */
class ArchivalJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory       $time               The time factory.
     * @param BeschikkingService $beschikkingService The beschikking service.
     * @param IAppManager        $appManager         The app manager.
     * @param LoggerInterface    $logger             The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly BeschikkingService $beschikkingService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Archive the beschikking carried in the job argument.
     *
     * @param mixed $argument The job argument; expects ['beschikkingId' => string].
     *
     * @return void
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return;
        }

        $beschikkingId = '';
        if (is_array($argument) === true) {
            $beschikkingId = (string) ($argument['beschikkingId'] ?? '');
        }

        if ($beschikkingId === '') {
            $this->logger->warning('ArchivalJob: missing beschikkingId argument');
            return;
        }

        try {
            $this->beschikkingService->archive($beschikkingId);
            $this->logger->info('ArchivalJob: archived beschikking', ['beschikkingId' => $beschikkingId]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ArchivalJob: archival failed',
                ['exception' => $e->getMessage(), 'beschikkingId' => $beschikkingId],
            );
        }
    }//end run()
}//end class
