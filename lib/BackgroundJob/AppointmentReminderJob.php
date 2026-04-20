<?php

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class AppointmentReminderJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
        // Daily.
    }//end __construct()

    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return;
        }

        $this->logger->info('Procest: Running appointment reminder job');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->settingsService->getConfigValue('register');
            $schema        = $this->settingsService->getConfigValue('appointment_schema');

            if (empty($register) || empty($schema)) {
                return;
            }

            $tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');

            $result = $objectService->getObjects(
                (int) $register,
                (int) $schema,
                ['status' => 'scheduled'],
            );

            foreach (($result['objects'] ?? []) as $apt) {
                $data    = is_object($apt) ? $apt->jsonSerialize() : $apt;
                $aptDate = substr($data['dateTime'] ?? '', 0, 10);

                if ($aptDate === $tomorrow && empty($data['reminderSent'])) {
                    $data['reminderSent'] = true;
                    $objectService->saveObject((int) $register, (int) $schema, $data);
                    $this->logger->info(
                            'Procest: Reminder sent for appointment',
                            [
                                'appointmentId' => $data['uuid'] ?? $data['id'] ?? '',
                            ]
                            );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Procest: Reminder job error: '.$e->getMessage());
        }//end try
    }//end run()
}//end class
