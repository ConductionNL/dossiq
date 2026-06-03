<?php

/**
 * Procest DSO Deadline Job
 *
 * Daily TimedJob that scans all open omgevingsvergunning zaken for approaching
 * deadlines and dispatches Nextcloud notifications at the configured warning
 * and critical thresholds. Overdue zaken are flagged in the activity log.
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
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily deadline scanner for DSO omgevingsvergunning zaken.
 *
 * Warning threshold (default 14 working days) and critical threshold
 * (default 5 working days) are configurable via admin settings.
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
     * Constructor.
     *
     * @param ITimeFactory         $time                The time factory
     * @param DsoCaseService       $dsoCaseService      DSO case service
     * @param SettingsService      $settingsService     Settings service
     * @param INotificationManager $notificationManager Nextcloud notification manager
     * @param IAppManager          $appManager          App manager
     * @param LoggerInterface      $logger              Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly DsoCaseService $dsoCaseService,
        private readonly SettingsService $settingsService,
        private readonly INotificationManager $notificationManager,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Daily interval.
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Scan open DSO zaken and send deadline notifications.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
     */
    protected function run($argument): void
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps(), strict: true) === false) {
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $warningDays  = (int) $this->settingsService->getConfigValue(
            key: 'dso_deadline_warning_weeks_warning',
            default: (string) self::DEFAULT_WARNING_DAYS
        );
        $criticalDays = (int) $this->settingsService->getConfigValue(
            key: 'dso_deadline_warning_weeks_critical',
            default: (string) self::DEFAULT_CRITICAL_DAYS
        );

        if ($warningDays <= 0) {
            $warningDays = self::DEFAULT_WARNING_DAYS;
        }

        if ($criticalDays <= 0) {
            $criticalDays = self::DEFAULT_CRITICAL_DAYS;
        }

        $today      = new DateTimeImmutable('today');
        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        $this->logger->info(
            'DsoDeadlineJob: scanning open DSO zaken (warning='.$warningDays.'d, critical='.$criticalDays.'d)',
            ['app' => Application::APP_ID],
        );

        try {
            // Fetch open omgevingsvergunning zaken with a deadlineDatum set.
            $openZaken = $objectService->findObjects(
                register: $register,
                schema: $caseSchema,
                params: [
                    'procedureType' => ['reguliere', 'uitgebreide'],
                    '_limit'        => 500,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'DsoDeadlineJob: could not fetch open zaken: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return;
        }

        if (is_array($openZaken) === false) {
            return;
        }

        foreach ($openZaken as $zaak) {
            try {
                if (is_object($zaak) === true) {
                    $zaakData = $zaak->jsonSerialize();
                } else {
                    $zaakData = (array) $zaak;
                }

                $this->processZaak(
                    zaak: $zaakData,
                    today: $today,
                    warningDays: $warningDays,
                    criticalDays: $criticalDays,
                    objectService: $objectService,
                    register: $register,
                    caseSchema: $caseSchema
                );
            } catch (\Throwable $e) {
                if (is_array($zaak) === true) {
                    $zaakId = (string) ($zaak['id'] ?? '?');
                } else {
                    $zaakId = '?';
                }

                $this->logger->warning(
                    'DsoDeadlineJob: error processing zaak '.$zaakId.': '.$e->getMessage(),
                    ['app' => Application::APP_ID],
                );
            }//end try
        }//end foreach
    }//end run()

    /**
     * Process a single zaak for deadline warnings.
     *
     * @param array<string, mixed> $zaak          Procest zaak data
     * @param DateTimeImmutable    $today         Today's date
     * @param int                  $warningDays   Warning threshold in days
     * @param int                  $criticalDays  Critical threshold in days
     * @param object               $objectService OpenRegister ObjectService
     * @param string               $register      Register slug
     * @param string               $caseSchema    Case schema slug/id
     *
     * @return void
     */
    private function processZaak(
        array $zaak,
        DateTimeImmutable $today,
        int $warningDays,
        int $criticalDays,
        object $objectService,
        string $register,
        string $caseSchema,
    ): void {
        $deadlineDatum = (string) ($zaak['deadlineDatum'] ?? '');
        if ($deadlineDatum === '') {
            return;
        }

        $status = (string) ($zaak['status'] ?? '');
        if (in_array(needle: $status, haystack: ['verleend', 'geweigerd', 'ingetrokken'], strict: true) === true) {
            return;
        }

        try {
            $deadline = new DateTimeImmutable(datetime: $deadlineDatum);
        } catch (\Throwable) {
            return;
        }

        $diff     = (int) $today->diff(targetObject: $deadline)->days;
        $isPast   = $deadline < $today;
        $assignee = (string) ($zaak['assignee'] ?? '');
        $zaakId   = (string) ($zaak['id'] ?? ($zaak['uuid'] ?? ''));

        if ($isPast === true) {
            $this->sendNotification(
                userId: $assignee,
                zaakId: $zaakId,
                level: 'overdue',
                remainingDays: 0
            );
            $this->flagOverdue(
                zaak: $zaak,
                objectService: $objectService,
                register: $register,
                caseSchema: $caseSchema
            );
        } else if ($diff <= $criticalDays) {
            $this->sendNotification(
                userId: $assignee,
                zaakId: $zaakId,
                level: 'critical',
                remainingDays: $diff
            );
        } else if ($diff <= $warningDays) {
            $this->sendNotification(
                userId: $assignee,
                zaakId: $zaakId,
                level: 'warning',
                remainingDays: $diff
            );
        }//end if
    }//end processZaak()

    /**
     * Send a Nextcloud notification about a deadline.
     *
     * @param string $userId        Nextcloud UID of the recipient
     * @param string $zaakId        UUID of the zaak
     * @param string $level         'warning', 'critical', or 'overdue'
     * @param int    $remainingDays Number of days remaining (0 for overdue)
     *
     * @return void
     */
    private function sendNotification(
        string $userId,
        string $zaakId,
        string $level,
        int $remainingDays,
    ): void {
        if ($userId === '') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(app: Application::APP_ID)
                ->setUser(user: $userId)
                ->setObject(type: 'dso-zaak', id: $zaakId)
                ->setSubject(
                    subject: 'dso_deadline_'.$level,
                    parameters: [
                        'zaakId'        => $zaakId,
                        'remainingDays' => $remainingDays,
                    ]
                )
                ->setDateTime(dateTime: new \DateTime());
            $this->notificationManager->notify(notification: $notification);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'DsoDeadlineJob: notification failed for '.$zaakId.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }
    }//end sendNotification()

    /**
     * Append an overdue flag to the zaak's activity log.
     *
     * @param array<string, mixed> $zaak          Procest zaak data
     * @param object               $objectService OpenRegister ObjectService
     * @param string               $register      Register slug
     * @param string               $caseSchema    Case schema slug/id
     *
     * @return void
     */
    private function flagOverdue(
        array $zaak,
        object $objectService,
        string $register,
        string $caseSchema,
    ): void {
        $rawActivity = $zaak['activity'] ?? null;
        if (is_array($rawActivity) === true) {
            $activity = $rawActivity;
        } else {
            $activity = [];
        }

        // Avoid duplicate overdue flags for the same day.
        $today = date('Y-m-d');
        foreach ($activity as $entry) {
            if (is_array($entry) === true
                && ($entry['type'] ?? '') === 'deadlineOverdue'
                && str_starts_with(haystack: (string) ($entry['timestamp'] ?? ''), needle: $today) === true
            ) {
                return;
            }
        }

        $activity[]       = [
            'type'      => 'deadlineOverdue',
            'timestamp' => date('c'),
        ];
        $zaak['activity'] = $activity;

        try {
            $objectService->saveObject(
                register: $register,
                schema: $caseSchema,
                object: $zaak
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'DsoDeadlineJob: could not flag overdue: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }
    }//end flagOverdue()
}//end class
