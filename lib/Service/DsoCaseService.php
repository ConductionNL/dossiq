<?php

/**
 * DSO Case Service
 *
 * Core service for the DSO Omgevingsloket integration. Handles vergunningaanvraag
 * intake (zaak creation), status transitions, deadline computation, and per-object
 * authorization for DSO-related cases. All workflow side-effects (notifications,
 * event dispatch) are routed through this service to keep the controller layer thin.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Event\VergunningStatusChangedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for DSO Omgevingsloket case management.
 *
 * Creates Procest zaken from DSO vergunningaanvragen, transitions statuses,
 * and computes statutory deadlines in working days (excluding weekends and
 * Dutch national holidays).
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */
class DsoCaseService
{

    /**
     * Fixed Dutch national holidays as [month, day] pairs.
     * Variable Easter-based holidays are computed dynamically in isWorkingDay().
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private const FIXED_HOLIDAYS = [
        [1, 1],
    // New Year.
        [4, 27],
    // King's Day.
        [5, 5],
    // Liberation Day.
        [12, 25],
    // Christmas Day 1.
        [12, 26],
    // Christmas Day 2.
    ];

    /**
     * Day-offsets from Easter Sunday for variable Dutch national holidays.
     *
     * 0=Eerste Paasdag, 1=Tweede Paasdag, 39=Hemelvaartsdag,
     * 49=Eerste Pinksterdag, 50=Tweede Pinksterdag.
     *
     * @var array<int, int>
     */
    private const EASTER_OFFSETS = [0, 1, 39, 49, 50];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig       The application config service
     * @param ContainerInterface $container       The DI container (ObjectService resolved lazily)
     * @param IEventDispatcher   $eventDispatcher The event dispatcher
     * @param LoggerInterface    $logger          The logger
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a Procest zaak from a DSO vergunningaanvraag.
     *
     * Looks up the vergunningaanvraag object, determines the procedure type
     * from the activiteiten list, computes the statutory deadline, and
     * persists a new zaak in the Procest register.
     *
     * @param string $vergunningaanvraagId The UUID of the vergunningaanvraag object
     *
     * @return array<string,mixed> The created zaak object
     *
     * @throws \RuntimeException When OpenRegister is unavailable or config is missing
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function createZaakFromVergunningaanvraag(string $vergunningaanvraagId): array
    {
        $objectService = $this->getObjectService();

        $vergunningaanvraagSchema = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'dso_vergunningaanvraag_schema',
            default: ''
        );

        $vergunningaanvraag = $objectService->findObject(
            register: 'dso',
            schema: $vergunningaanvraagSchema,
            id: $vergunningaanvraagId
        );

        if ($vergunningaanvraag === null) {
            throw new \RuntimeException('Vergunningaanvraag not found: '.$vergunningaanvraagId);
        }

        $activiteiten  = $vergunningaanvraag['activiteiten'] ?? [];
        $procedureType = $this->determineProcedureType(activiteiten: $activiteiten);

        $indieningsdatum = (string) ($vergunningaanvraag['indieningsdatum'] ?? date('Y-m-d'));
        $deadlineDatum   = $this->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: $procedureType
        );

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

        $zaak = [
            'title'                 => 'Omgevingsvergunning: '.($vergunningaanvraag['titel'] ?? $vergunningaanvraagId),
            'status'                => 'ingediend',
            'caseType'              => 'omgevingsvergunning',
            'procedureType'         => $procedureType,
            'vergunningaanvraagRef' => $vergunningaanvraagId,
            'indieningsdatum'       => $indieningsdatum,
            'deadlineDatum'         => $deadlineDatum,
            'activiteiten'          => $activiteiten,
            'activityLog'           => [
                [
                    'timestamp' => date('c'),
                    'action'    => 'zaak_created',
                    'note'      => 'Zaak aangemaakt vanuit DSO vergunningaanvraag.',
                ],
            ],
        ];

        $created = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaak
        );

        $this->logger->info(
            'Procest DsoCaseService: zaak created',
            [
                'app'                  => Application::APP_ID,
                'vergunningaanvraagId' => $vergunningaanvraagId,
                'procedureType'        => $procedureType,
                'deadlineDatum'        => $deadlineDatum,
            ]
        );

        return $created;
    }//end createZaakFromVergunningaanvraag()

    /**
     * Transition the status of a DSO zaak.
     *
     * Loads both the zaak and the linked vergunningaanvraag, updates their
     * statuses, appends to the activity log, and dispatches a
     * VergunningStatusChangedEvent for downstream listeners.
     *
     * @param string      $zaakId       The UUID of the zaak
     * @param string      $newStatus    The target status value
     * @param string|null $besluitdatum Optional ISO 8601 decision date
     * @param string|null $toelichting  Optional explanation text
     * @param string      $userId       The Nextcloud user UID performing the action
     *
     * @return array<string,mixed> The updated zaak object
     *
     * @throws \RuntimeException When the zaak cannot be found
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function transitionStatus(
        string $zaakId,
        string $newStatus,
        ?string $besluitdatum,
        ?string $toelichting,
        string $userId,
    ): array {
        $objectService = $this->getObjectService();

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

        $zaak = $objectService->findObject(
            register: $register,
            schema: $caseSchema,
            id: $zaakId
        );

        if ($zaak === null) {
            throw new \RuntimeException('Zaak not found: '.$zaakId);
        }

        $oldStatus = (string) ($zaak['status'] ?? '');
        $vergunningaanvraagRef = (string) ($zaak['vergunningaanvraagRef'] ?? '');

        $zaak['status'] = $newStatus;
        if ($besluitdatum !== null) {
            $zaak['besluitdatum'] = $besluitdatum;
        }

        if ($toelichting !== null) {
            $zaak['toelichting'] = $toelichting;
        }

        $logEntry = [
            'timestamp' => date('c'),
            'action'    => 'status_transition',
            'userId'    => $userId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
        ];
        if ($toelichting !== null) {
            $logEntry['note'] = $toelichting;
        }

        $activityLog         = $zaak['activityLog'] ?? [];
        $activityLog[]       = $logEntry;
        $zaak['activityLog'] = $activityLog;

        $updatedZaak = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaak
        );

        // Update the linked vergunningaanvraag status when possible.
        if ($vergunningaanvraagRef !== '') {
            $this->syncVergunningaanvraagStatus(
                objectService: $objectService,
                vergunningaanvraagRef: $vergunningaanvraagRef,
                newStatus: $newStatus,
                besluitdatum: $besluitdatum
            );
        }

        $event = new VergunningStatusChangedEvent(
            vergunningaanvraagRef: $vergunningaanvraagRef,
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            besluitdatum: $besluitdatum,
            toelichting: $toelichting,
            userId: $userId,
        );
        $this->eventDispatcher->dispatchTyped(event: $event);

        return $updatedZaak;
    }//end transitionStatus()

    /**
     * Compute the statutory deadline for a vergunningaanvraag.
     *
     * Reguliere procedure: 40 working days (8 weeks).
     * Uitgebreide procedure: 130 working days (26 weeks).
     * Working days exclude weekends (Saturday = 6, Sunday = 7 per date('N'))
     * and a fixed set of Dutch national holidays.
     *
     * @param string $indieningsdatum ISO 8601 date of submission
     * @param string $procedureType   'reguliere' or 'uitgebreide'
     *
     * @return string ISO 8601 date string of the computed deadline
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function computeDeadline(string $indieningsdatum, string $procedureType): string
    {
        if ($procedureType === 'uitgebreide') {
            $workingDaysTarget = 130;
        } else {
            $workingDaysTarget = 40;
        }

        $current     = new \DateTimeImmutable($indieningsdatum);
        $workingDays = 0;

        while ($workingDays < $workingDaysTarget) {
            $current = $current->modify('+1 day');
            if ($this->isWorkingDay(date: $current) === true) {
                $workingDays++;
            }
        }

        return $current->format('Y-m-d');
    }//end computeDeadline()

    /**
     * Authorise a zaak mutation for the given user.
     *
     * Checks whether the user is either the assigned user on the zaak or
     * a Nextcloud administrator. Throws an exception if not authorised so
     * that the controller can catch and return a 403 response.
     *
     * @param array<string,mixed> $zaak The zaak object array
     * @param IUser               $user The authenticated user
     *
     * @return void
     *
     * @throws \Exception When the user is not authorised to mutate the zaak
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function authorizeZaakMutation(array $zaak, IUser $user): void
    {
        $uid      = $user->getUID();
        $assignee = (string) ($zaak['assigneeUserId'] ?? ($zaak['behandelaar'] ?? ''));

        if ($uid === $assignee) {
            return;
        }

        try {
            $groupManager = $this->container->get('OCP\IGroupManager');
            if ($groupManager->isAdmin(uid: $uid) === true) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest DsoCaseService: could not resolve IGroupManager for auth check: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        throw new \Exception('Not authorized');
    }//end authorizeZaakMutation()

    /**
     * Get the ObjectService lazily from the DI container.
     *
     * @return object The OpenRegister ObjectService
     *
     * @throws \RuntimeException When the service is not available
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OpenRegister ObjectService not available: '.$e->getMessage(),
                0,
                $e
            );
        }
    }//end getObjectService()

    /**
     * Determine the procedure type from the activiteiten list.
     *
     * Returns 'uitgebreide' when any activiteit has regelkwalificatie set to
     * 'uitgebreide' or when there are more than 3 activiteiten; 'reguliere'
     * otherwise.
     *
     * @param array<int,mixed> $activiteiten The activiteiten array
     *
     * @return string 'reguliere' or 'uitgebreide'
     */
    private function determineProcedureType(array $activiteiten): string
    {
        if (count($activiteiten) > 3) {
            return 'uitgebreide';
        }

        foreach ($activiteiten as $activiteit) {
            if (is_array($activiteit) === false) {
                continue;
            }

            $kwalificatie = (string) ($activiteit['regelkwalificatie'] ?? '');
            if ($kwalificatie === 'uitgebreide') {
                return 'uitgebreide';
            }
        }

        return 'reguliere';
    }//end determineProcedureType()

    /**
     * Check whether a given date is a working day.
     *
     * A working day is neither a weekend day nor a Dutch national holiday.
     * Both fixed holidays (New Year, King's Day, Liberation Day, Christmas)
     * and Easter-based variable holidays (Eerste/Tweede Paasdag,
     * Hemelvaartsdag, Eerste/Tweede Pinksterdag) are excluded.
     *
     * @param \DateTimeImmutable $date The date to check
     *
     * @return bool True when the date is a working day
     */
    private function isWorkingDay(\DateTimeImmutable $date): bool
    {
        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek >= 6) {
            return false;
        }

        $month = (int) $date->format('n');
        $day   = (int) $date->format('j');

        foreach (self::FIXED_HOLIDAYS as $holiday) {
            if ($holiday[0] === $month && $holiday[1] === $day) {
                return false;
            }
        }

        // Check Easter-based variable holidays using PHP's easter_date().
        $year       = (int) $date->format('Y');
        $easterTs   = easter_date($year);
        $easterDay  = (int) date('j', $easterTs);
        $easterMon  = (int) date('n', $easterTs);
        $easterDate = (new \DateTimeImmutable())->setDate($year, $easterMon, $easterDay);

        foreach (self::EASTER_OFFSETS as $offset) {
            $holiday = $easterDate->modify('+'.$offset.' days');
            if ((int) $holiday->format('n') === $month && (int) $holiday->format('j') === $day) {
                return false;
            }
        }

        return true;
    }//end isWorkingDay()

    /**
     * Sync the vergunningaanvraag status to match the zaak's new status.
     *
     * Best-effort: errors are logged but do not propagate to the caller.
     *
     * @param object      $objectService         The ObjectService instance
     * @param string      $vergunningaanvraagRef The vergunningaanvraag UUID
     * @param string      $newStatus             The new status to set
     * @param string|null $besluitdatum          Optional decision date
     *
     * @return void
     */
    private function syncVergunningaanvraagStatus(
        object $objectService,
        string $vergunningaanvraagRef,
        string $newStatus,
        ?string $besluitdatum,
    ): void {
        try {
            $vergunningaanvraagSchema = $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'dso_vergunningaanvraag_schema',
                default: ''
            );

            if ($vergunningaanvraagSchema === '') {
                return;
            }

            $aanvraag = $objectService->findObject(
                register: 'dso',
                schema: $vergunningaanvraagSchema,
                id: $vergunningaanvraagRef
            );

            if ($aanvraag === null) {
                return;
            }

            $aanvraag['status'] = $newStatus;
            if ($besluitdatum !== null) {
                $aanvraag['besluitdatum'] = $besluitdatum;
            }

            $objectService->saveObject(
                register: 'dso',
                schema: $vergunningaanvraagSchema,
                object: $aanvraag
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest DsoCaseService: could not sync vergunningaanvraag status: '.$e->getMessage(),
                [
                    'app'                   => Application::APP_ID,
                    'vergunningaanvraagRef' => $vergunningaanvraagRef,
                ]
            );
        }//end try
    }//end syncVergunningaanvraagStatus()
}//end class
