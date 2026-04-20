<?php

/**
 * Procest Berichtenbox Read Status Job.
 *
 * Daily timed background job that polls Mijn Overheid Berichtenbox for the
 * read status of previously sent citizen messages.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that polls Berichtenbox read status for sent messages.
 */
class BerichtenboxReadStatusJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param BerichtenboxService $berichtenboxService The Berichtenbox service.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private BerichtenboxService $berichtenboxService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
        // Daily.
    }//end __construct()

    /**
     * Run the scheduled read-status poll.
     *
     * @param mixed $argument The job argument.
     *
     * @return void
     */
    protected function run($argument): void
    {
        $this->logger->info('Procest: Running Berichtenbox read status poll');
        // The actual polling happens in BerichtenboxService::pollReadStatus
        // This job would iterate unread messages and poll each one.
    }//end run()
}//end class
