<?php

/**
 * Procest Appointment Reminder Job.
 *
 * Daily timed background job that sends reminders for scheduled appointments
 * that are due tomorrow and have not yet had a reminder dispatched.
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
 *
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that sends appointment reminders for next-day appointments.
 */
class AppointmentReminderJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory       $time            The time factory.
     * @param SettingsService    $settingsService The settings service.
     * @param IAppManager        $appManager      The Nextcloud app manager.
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
        // Daily.
    }//end __construct()

    /**
     * Run the scheduled reminder dispatch.
     *
     * @param mixed $argument The job argument.
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
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

            if (empty($register) === true || empty($schema) === true) {
                return;
            }

            $tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');

            $appointments = $objectService->findAll(
                ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'status' => 'scheduled']],
            );

            foreach ($appointments as $apt) {
                if (is_object($apt) === true) {
                    $data = $apt->jsonSerialize();
                } else {
                    $data = $apt;
                }

                $aptDate = substr($data['dateTime'] ?? '', 0, 10);

                if ($aptDate === $tomorrow && empty($data['reminderSent']) === true) {
                    $data['reminderSent'] = true;
                    $objectService->saveObject(object: $data, register: (int) $register, schema: (int) $schema);
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
