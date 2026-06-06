<?php

/**
 * DSO Deadline Job
 *
 * Daily background job that monitors DSO omgevingsvergunning zaken for
 * approaching statutory deadlines. Sends warning and critical notifications
 * to the case handler and marks overdue cases. Deadline thresholds are
 * configurable via IAppConfig.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job for DSO omgevingsvergunning deadline monitoring.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
 */
class DsoDeadlineJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory         $timeFactory         The time factory
     * @param IAppConfig           $appConfig           The application config service
     * @param ContainerInterface   $container           The DI container
     * @param INotificationManager $notificationManager The notification manager
     * @param LoggerInterface      $logger              The logger
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $timeFactory);
        $this->setInterval(seconds: 24 * 3600);
    }//end __construct()

    /**
     * Run the deadline monitoring job.
     *
     * Queries all open omgevingsvergunning zaken (status ingediend or
     * in_behandeling) and checks each deadline, sending notifications
     * as the deadline approaches and marking overdue zaken.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    protected function run($argument): void
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register   = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'register',
            default: ''
        );
        $caseSchema = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'case_schema',
            default: ''
        );

        if ($register === '' || $caseSchema === '') {
            $this->logger->warning(
                'Procest DsoDeadlineJob: register or case_schema not configured.',
                ['app' => Application::APP_ID]
            );
            return;
        }

        $warningWeeks  = (int) $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'dso_deadline_warning_weeks_warning',
            default: '14'
        );
        $criticalWeeks = (int) $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'dso_deadline_warning_weeks_critical',
            default: '5'
        );

        if ($warningWeeks <= 0) {
            $warningWeeks = 14;
        }

        if ($criticalWeeks <= 0) {
            $criticalWeeks = 5;
        }

        try {
            $zaken = $objectService->findObjects(
                register: $register,
                schema: $caseSchema,
                params: [
                    'caseType' => 'omgevingsvergunning',
                    'status'   => ['ingediend', 'in_behandeling'],
                    '_limit'   => 500,
                    '_offset'  => 0,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest DsoDeadlineJob: could not fetch open zaken: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return;
        }

        if (is_array($zaken) === true) {
            $zaakList = $zaken;
        } else {
            $zaakList = [];
        }

        foreach ($zaakList as $zaak) {
            if (is_array($zaak) === false) {
                continue;
            }

            try {
                $this->processZaakDeadline(
                    zaak: $zaak,
                    objectService: $objectService,
                    register: $register,
                    caseSchema: $caseSchema,
                    warningWeeks: $warningWeeks,
                    criticalWeeks: $criticalWeeks
                );
            } catch (\Throwable $e) {
                $zaakId = (string) ($zaak['id'] ?? ($zaak['uuid'] ?? 'unknown'));
                $this->logger->error(
                    'Procest DsoDeadlineJob: error processing zaak '.$zaakId.': '.$e->getMessage(),
                    [
                        'app'    => Application::APP_ID,
                        'zaakId' => $zaakId,
                    ]
                );
            }
        }//end foreach
    }//end run()

    /**
     * Get the remaining working days from today until the given deadline date.
     *
     * Returns a negative value when the deadline has already passed.
     *
     * @param string $deadlineDatum The deadline date as ISO 8601 (YYYY-MM-DD)
     *
     * @return int The number of remaining working days (negative = overdue)
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    private function getRemainingWorkingDays(string $deadlineDatum): int
    {
        $today    = new \DateTimeImmutable('today');
        $deadline = new \DateTimeImmutable(substr($deadlineDatum, 0, 10));

        if ($deadline <= $today) {
            // Count working days in the past (return negative).
            $count   = 0;
            $current = $deadline;
            while ($current < $today) {
                if ($this->isWorkingDay(date: $current) === true) {
                    $count++;
                }

                $current = $current->modify('+1 day');
            }

            return -$count;
        }

        $count   = 0;
        $current = $today;
        while ($current < $deadline) {
            $current = $current->modify('+1 day');
            if ($this->isWorkingDay(date: $current) === true) {
                $count++;
            }
        }

        return $count;
    }//end getRemainingWorkingDays()

    /**
     * Determine whether a date is a working day (not weekend, not public holiday).
     *
     * @param \DateTimeImmutable $date The date to check
     *
     * @return bool
     */
    private function isWorkingDay(\DateTimeImmutable $date): bool
    {
        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek >= 6) {
            return false;
        }

        $month = (int) $date->format('n');
        $day   = (int) $date->format('j');

        $holidays = [
            [1, 1],
            [4, 27],
            [5, 5],
            [12, 25],
            [12, 26],
        ];

        foreach ($holidays as $holiday) {
            if ($holiday[0] === $month && $holiday[1] === $day) {
                return false;
            }
        }

        return true;
    }//end isWorkingDay()

    /**
     * Process the deadline for a single zaak and dispatch notifications as needed.
     *
     * @param array<string,mixed> $zaak          The zaak object array
     * @param object              $objectService The ObjectService instance
     * @param string              $register      The register identifier
     * @param string              $caseSchema    The case schema identifier
     * @param int                 $warningWeeks  Warning threshold in working days
     * @param int                 $criticalWeeks Critical threshold in working days
     *
     * @return void
     */
    private function processZaakDeadline(
        array $zaak,
        object $objectService,
        string $register,
        string $caseSchema,
        int $warningWeeks,
        int $criticalWeeks,
    ): void {
        $deadlineDatum = (string) ($zaak['deadlineDatum'] ?? '');
        if ($deadlineDatum === '') {
            return;
        }

        $zaakId    = (string) ($zaak['id'] ?? ($zaak['uuid'] ?? ''));
        $assignee  = (string) ($zaak['assigneeUserId'] ?? ($zaak['behandelaar'] ?? ''));
        $remaining = $this->getRemainingWorkingDays(deadlineDatum: $deadlineDatum);

        if ($remaining <= 0) {
            $this->sendDeadlineNotification(
                zaakId: $zaakId,
                assignee: $assignee,
                subject: 'dso_deadline_overdue'
            );

            // Mark zaak as overdue.
            if (($zaak['deadlineOverdue'] ?? false) === false) {
                $zaak['deadlineOverdue'] = true;
                $activityLog         = $zaak['activityLog'] ?? [];
                $activityLog[]       = [
                    'timestamp' => date('c'),
                    'action'    => 'deadline_overdue',
                    'note'      => 'Wettelijke beslistermijn overschreden.',
                ];
                $zaak['activityLog'] = $activityLog;
                $objectService->saveObject(
                    register: $register,
                    schema: $caseSchema,
                    object: $zaak
                );
            }

            return;
        }//end if

        if ($remaining <= $criticalWeeks) {
            $this->sendDeadlineNotification(
                zaakId: $zaakId,
                assignee: $assignee,
                subject: 'dso_deadline_critical'
            );
            return;
        }

        if ($remaining <= $warningWeeks) {
            $this->sendDeadlineNotification(
                zaakId: $zaakId,
                assignee: $assignee,
                subject: 'dso_deadline_warning'
            );
        }
    }//end processZaakDeadline()

    /**
     * Send a deadline notification to the zaak assignee.
     *
     * @param string $zaakId   The zaak UUID
     * @param string $assignee The Nextcloud user UID to notify (may be empty)
     * @param string $subject  The notification subject key
     *
     * @return void
     */
    private function sendDeadlineNotification(string $zaakId, string $assignee, string $subject): void
    {
        if ($assignee === '') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(app: Application::APP_ID);
            $notification->setUser(user: $assignee);
            $notification->setSubject(subject: $subject, parameters: ['zaakId' => $zaakId]);
            $notification->setObject(type: 'case', id: $zaakId);
            $notification->setDateTime(dateTime: new \DateTime());
            $this->notificationManager->notify(notification: $notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest DsoDeadlineJob: could not send notification: '.$e->getMessage(),
                [
                    'app'     => Application::APP_ID,
                    'zaakId'  => $zaakId,
                    'subject' => $subject,
                ]
            );
        }
    }//end sendDeadlineNotification()

    /**
     * Get the ObjectService from the DI container; returns null when unavailable.
     *
     * @return object|null
     *
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedInferredReturnType
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest DsoDeadlineJob: ObjectService not available: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }
    }//end getObjectService()
}//end class
