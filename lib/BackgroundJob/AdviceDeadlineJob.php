<?php

/**
 * Procest Advice Deadline Job
 *
 * Daily timed background job that processes advice request deadlines:
 * expires overdue advice requests and sends reminders 3 days before deadline.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/advice-management/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job for processing advice request deadlines.
 */
class AdviceDeadlineJob extends TimedJob
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
    }//end __construct()

    /**
     * Run deadline processing for advice requests.
     *
     * @param mixed $argument The job argument.
     *
     * @return void
     */
    protected function run($argument): void
    {
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            return;
        }

        $this->logger->info('Procest: Running advice deadline job', ['app' => Application::APP_ID]);

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->settingsService->getConfigValue('register');
            $schema        = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return;
            }

            $today      = (new \DateTime())->format('Y-m-d');
            $reminderDate = (new \DateTime('+3 days'))->format('Y-m-d');

            $params = ['status' => 'aangevraagd'];
            $advice = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: $params,
            );

            if (!is_array($advice)) {
                return;
            }

            foreach ($advice as $adviceItem) {
                $data = $adviceItem->jsonSerialize();
                $deadline = substr($data['deadline'] ?? '', 0, 10);

                if ($deadline < $today && $data['status'] === 'aangevraagd') {
                    $data['status'] = 'verlopen';
                    $objectService->saveObject($register, $schema, $data);
                    $this->logger->info(
                        'Advice expired: '.$data['id'],
                        ['app' => Application::APP_ID]
                    );
                } elseif ($deadline === $reminderDate && $data['status'] === 'aangevraagd') {
                    $this->logger->info(
                        'Advice reminder due: '.$data['id'],
                        ['app' => Application::APP_ID]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Advice deadline job failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }//end run()
}//end class
