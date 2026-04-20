<?php

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class BerichtenboxReadStatusJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private BerichtenboxService $berichtenboxService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
        // Daily.
    }//end __construct()

    protected function run($argument): void
    {
        $this->logger->info('Procest: Running Berichtenbox read status poll');
        // The actual polling happens in BerichtenboxService::pollReadStatus
        // This job would iterate unread messages and poll each one.
    }//end run()
}//end class
