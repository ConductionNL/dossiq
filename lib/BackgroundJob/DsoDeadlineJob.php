<?php

/**
 * Procest DSO Deadline Job
 *
 * Daily TimedJob that scans all open omgevingsvergunning zaken and sends
 * warning / critical / overdue notifications based on the configured
 * working-day thresholds.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily job that warns behandelaars about approaching and overdue DSO deadlines.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
 */
class DsoDeadlineJob extends TimedJob
{
    /**
     * Default warning threshold in working days.
     */
    private const DEFAULT_WARNING_DAYS = 14;

    /**
     * Default critical threshold in working days.
     */
    private const DEFAULT_CRITICAL_DAYS = 5;

    /**
     * DSO statuses that indicate an open case.
     *
     * @var array<string>
     */
    private const OPEN_STATUSES = ['ingediend', 'in_behandeling'];

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time                The time factory
     * @param DsoCaseService       $dsoCaseService      The DSO case service
     * @param SettingsService      $settingsService     The settings service
     * @param IAppManager          $appManager          The app manager
     * @param INotificationManager $notificationManager The notification manager
     * @param LoggerInterface      $logger              The logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly DsoCaseService $dsoCaseService,
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the DSO deadline check for all open omgevingsvergunning zaken.
     *
     * @param mixed $argument The job argument
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            return;
        }

        $warningDays  = (int) ($this->settingsService->getConfigValue('dso_deadline_warning_weeks_warning', (string) self::DEFAULT_WARNING_DAYS));
        $criticalDays = (int) ($this->settingsService->getConfigValue('dso_deadline_warning_weeks_critical', (string) self::DEFAULT_CRITICAL_DAYS));
        if ($warningDays <= 0) {
            $warningDays = self::DEFAULT_WARNING_DAYS;
        }

        if ($criticalDays <= 0) {
            $criticalDays = self::DEFAULT_CRITICAL_DAYS;
        }

        $today = new \DateTimeImmutable('today');

        $this->logger->info(
            'DsoDeadlineJob: running (warningDays='.$warningDays.', criticalDays='.$criticalDays.')',
            ['app' => Application::APP_ID],
        );

        // Query open omgevingsvergunning zaken.
        try {
            $openZaken = $objectService->findObjects(
                register: $register,
                schema: $caseSchema,
                params: ['_limit' => 500, '_offset' => 0],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'DsoDeadlineJob: could not query open zaken',
                ['error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
            return;
        }

        $zakenList = [];
        if (is_array($openZaken) === true) {
            $zakenList = $openZaken;
        } else if (is_object($openZaken) === true && method_exists($openZaken, 'getResults') === true) {
            $zakenList = $openZaken->getResults() ?? [];
        }

        foreach ($zakenList as $zaak) {
            try {
                $this->processZaak(
                    zaak: $zaak,
                    today: $today,
                    warningDays: $warningDays,
                    criticalDays: $criticalDays,
                );
            } catch (\Throwable $e) {
                $zaakId = '';
                if (is_array($zaak) === true) {
                    $zaakId = (string) ($zaak['uuid'] ?? ($zaak['id'] ?? ''));
                }

                $this->logger->warning(
                    'DsoDeadlineJob: error processing zaak '.$zaakId,
                    ['error' => $e->getMessage(), 'app' => Application::APP_ID],
                );
            }
        }//end foreach
    }//end run()

    /**
     * Process a single zaak for deadline warnings.
     *
     * @param mixed              $zaak         Zaak data (array or object)
     * @param \DateTimeImmutable $today        Today's date
     * @param int                $warningDays  Working-day threshold for warning
     * @param int                $criticalDays Working-day threshold for critical
     *
     * @return void
     */
    private function processZaak(
        mixed $zaak,
        \DateTimeImmutable $today,
        int $warningDays,
        int $criticalDays,
    ): void {
        $zaakArray = [];
        if (is_array($zaak) === true) {
            $zaakArray = $zaak;
        } else if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $serialized = $zaak->jsonSerialize();
            if (is_array($serialized) === true) {
                $zaakArray = $serialized;
            }
        }

        $dsoStatus = (string) ($zaakArray['dsoStatus'] ?? '');
        if (in_array($dsoStatus, self::OPEN_STATUSES, true) === false) {
            // Not an open DSO case — skip.
            return;
        }

        $deadlineDatum = (string) ($zaakArray['deadlineDatum'] ?? '');
        if ($deadlineDatum === '') {
            return;
        }

        try {
            $deadline = new \DateTimeImmutable($deadlineDatum);
        } catch (\Exception $e) {
            return;
        }

        $zaakId   = (string) ($zaakArray['uuid'] ?? ($zaakArray['id'] ?? ''));
        $assignee = (string) ($zaakArray['assignee'] ?? '');

        $diff          = $today->diff($deadline);
        $daysRemaining = $diff->days;
        if ($diff->invert === 1) {
            $daysRemaining = -$diff->days;
        }

        if ($daysRemaining < 0) {
            $this->sendNotification(
                assignee: $assignee,
                zaakId: $zaakId,
                subject: 'dso_deadline_overdue',
                daysRemaining: $daysRemaining,
            );
            return;
        }

        if ($daysRemaining <= $criticalDays) {
            $this->sendNotification(
                assignee: $assignee,
                zaakId: $zaakId,
                subject: 'dso_deadline_critical',
                daysRemaining: $daysRemaining,
            );
            return;
        }

        if ($daysRemaining <= $warningDays) {
            $this->sendNotification(
                assignee: $assignee,
                zaakId: $zaakId,
                subject: 'dso_deadline_warning',
                daysRemaining: $daysRemaining,
            );
        }
    }//end processZaak()

    /**
     * Send a Nextcloud notification to the assignee about a deadline.
     *
     * @param string $assignee      Nextcloud user ID
     * @param string $zaakId        Zaak UUID
     * @param string $subject       Notification subject key
     * @param int    $daysRemaining Remaining days (negative when overdue)
     *
     * @return void
     */
    private function sendNotification(
        string $assignee,
        string $zaakId,
        string $subject,
        int $daysRemaining,
    ): void {
        if ($assignee === '') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($assignee)
                ->setDateTime(new \DateTime())
                ->setObject('zaak', $zaakId)
                ->setSubject($subject, ['daysRemaining' => $daysRemaining, 'zaakId' => $zaakId]);

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DsoDeadlineJob: notification failed',
                ['assignee' => $assignee, 'zaakId' => $zaakId, 'error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
        }
    }//end sendNotification()
}//end class
