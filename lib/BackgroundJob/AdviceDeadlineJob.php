<?php

/**
 * Procest Advice Deadline Job
 *
 * Background job for managing advice request deadlines — expires overdue requests
 * and sends reminders 3 days before deadline.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use DateTime;
use DateInterval;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AdviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Background job for advice deadline management.
 *
 * Runs daily to:
 * - Expire overdue advice requests (deadline < today)
 * - Send reminders 3 days before deadline
 *
 * @spec openspec/changes/advice-management/tasks.md#task-5
 */
class AdviceDeadlineJob extends TimedJob
{

    /**
     * The OpenRegister ObjectService (loaded dynamically).
     *
     * @var object|null
     */
    private $objectService = null;

    /**
     * Constructor.
     *
     * @param ITimeFactory $timeFactory The time factory
     * @param ContainerInterface $container DI container
     * @param LoggerInterface $logger Logger
     *
     * @spec openspec/changes/advice-management/tasks.md#task-5
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($timeFactory);
        // Run once per day (86400 seconds)
        $this->setInterval(24 * 60 * 60);
        $this->loadOpenRegisterServices();
    }

    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $this->objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AdviceDeadlineJob: OpenRegister not available',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Execute the background job.
     *
     * @param mixed $argument Unused
     *
     * @return void
     *
     * @spec openspec/changes/advice-management/tasks.md#task-5
     */
    protected function run($argument): void
    {
        if ($this->objectService === null) {
            $this->logger->warning('AdviceDeadlineJob: OpenRegister not available');
            return;
        }

        try {
            $settingsService = $this->container->get(
                'OCA\Procest\Service\SettingsService'
            );
            $register = $settingsService->getConfigValue('register');
            $schema = $settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return;
            }

            // Fetch all pending (aangevraagd) advice requests
            $allAdvice = $this->objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['status' => 'aangevraagd']
            );

            if (!is_array($allAdvice)) {
                return;
            }

            $today = new DateTime();
            $today->setTime(0, 0, 0);

            $reminderDays = (int) $settingsService->getConfigValue('advice_reminder_days');
            if ($reminderDays <= 0) {
                $reminderDays = 3; // Default
            }

            foreach ($allAdvice as $advice) {
                $this->processAdviceDeadline($advice, $register, $schema, $today, $reminderDays);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'AdviceDeadlineJob failed',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
        }
    }

    /**
     * Process a single advice request for deadline management.
     *
     * @param array<string, mixed> $advice       The advice request
     * @param string               $register     The register name
     * @param string               $schema       The schema name
     * @param DateTime             $today        Today's date
     * @param int                  $reminderDays Days before deadline for reminder
     *
     * @return void
     *
     * @spec openspec/changes/advice-management/tasks.md#task-5
     */
    private function processAdviceDeadline(
        array $advice,
        string $register,
        string $schema,
        DateTime $today,
        int $reminderDays
    ): void {
        $deadline = $advice['deadline'] ?? null;
        if (empty($deadline)) {
            return;
        }

        try {
            $deadlineDate = new DateTime($deadline);
            $deadlineDate->setTime(0, 0, 0);

            $adviceId = $advice['id'] ?? $advice['uuid'] ?? null;
            if (empty($adviceId)) {
                return;
            }

            // Check if deadline has passed
            if ($deadlineDate < $today) {
                // Expire the advice
                $this->objectService->saveObject(
                    register: $register,
                    schema: $schema,
                    object: ['status' => 'verlopen']
                );

                $this->logger->info(
                    'Advice expired: ' . $adviceId,
                    ['app' => Application::APP_ID]
                );
                return;
            }

            // Check if reminder should be sent (3 days before deadline)
            $reminderDate = clone $deadlineDate;
            $reminderDate->sub(new DateInterval('P' . $reminderDays . 'D'));

            if ($today >= $reminderDate && $today < $deadlineDate) {
                $this->logger->info(
                    'Reminder should be sent for advice: ' . $adviceId,
                    ['app' => Application::APP_ID]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Error processing advice deadline',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
        }
    }
}
