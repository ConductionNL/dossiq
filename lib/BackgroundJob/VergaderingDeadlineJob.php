<?php

/**
 * Procest Vergadering Deadline Job
 *
 * Nightly background job that advances the status of vergadering-backed
 * Procest cases whose agenda-publication deadline has been reached.
 * A vergadering with a startDatum of today or in the past and current status
 * "gepland" is transitioned to "lopend" by this job.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\VergaderingCaseService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly job that advances vergadering-backed cases when their deadline passes.
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
 */
class VergaderingDeadlineJob extends TimedJob
{
    /**
     * Constructor for VergaderingDeadlineJob.
     *
     * @param ITimeFactory           $time             The time factory
     * @param VergaderingCaseService $vergaderingCases The vergadering case service
     * @param IAppManager            $appManager       The app manager
     * @param LoggerInterface        $logger           The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private readonly VergaderingCaseService $vergaderingCases,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run nightly.
        $this->setInterval(seconds: 86400);

    }//end __construct()

    /**
     * Run the vergadering deadline check.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
     */
    protected function run($argument): void
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps(), strict: true) === false) {
            return;
        }

        $advanced = $this->vergaderingCases->checkDeadlines();

        if ($advanced > 0) {
            $this->logger->info(
                'Procest: vergadering deadline job advanced '.$advanced.' case(s) to lopend',
                ['app' => Application::APP_ID]
            );
        }

    }//end run()
}//end class
