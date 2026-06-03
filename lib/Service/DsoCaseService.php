<?php

/**
 * Procest DSO Case Service
 *
 * Converts DSO vergunningaanvraag objects into Procest zaken,
 * mirrors status transitions between the vergunningaanvraag and the
 * Procest zaak, and calculates statutory deadlines for both reguliere
 * and uitgebreide procedures per the Omgevingswet.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Event\VergunningStatusChangedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates Procest zaak creation from DSO vergunningaanvraag objects,
 * status mirroring, deadline calculation, and event dispatch.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
 */
class DsoCaseService
{
    /**
     * Working days per week (Mon–Fri, Dutch calendar).
     */
    private const WORKING_DAYS_PER_WEEK = 5;

    /**
     * Procedure deadlines in working days.
     */
    private const PROCEDURE_WORKING_DAYS = [
        'reguliere'   => 40,
        'uitgebreide' => 130,
    ];

    /**
     * Dutch national holidays (fixed and recurring).
     * Computed dates added dynamically via getDutchHolidays().
     *
     * @var array<string>
     */
    private static array $fixedHolidays = [
        'Nieuwjaarsdag'      => '01-01',
        'Eerste Kerstdag'    => '12-25',
        'Tweede Kerstdag'    => '12-26',
        'Eerste Paasdag'     => '',
        'Tweede Paasdag'     => '',
        'Koningsdag'         => '04-27',
        'Bevrijdingsdag'     => '05-05',
        'Hemelvaartsdag'     => '',
        'Eerste Pinksterdag' => '',
        'Tweede Pinksterdag' => '',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService  $settingsService The settings service
     * @param IEventDispatcher $dispatcher      The event dispatcher
     * @param LoggerInterface  $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a Procest zaak from a vergunningaanvraag object.
     *
     * Looks up the vergunningaanvraag in OpenRegister, determines the
     * procedureType from activiteiten (uitgebreide when any activiteit is
     * flagged; reguliere by default), computes deadlineDatum, and saves a
     * new Procest zaak with all DSO extension fields populated.
     *
     * @param string $vergunningaanvraagId UUID of the vergunningaanvraag object
     *
     * @return array<string, mixed> The created zaak object
     *
     * @throws \RuntimeException When OpenRegister is unavailable or config missing
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
     */
    public function createZaakFromVergunningaanvraag(string $vergunningaanvraagId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        if (empty($register) === true) {
            throw new \RuntimeException('Procest register not configured');
        }

        $caseSchema = $this->settingsService->getConfigValue('case_schema');
        if (empty($caseSchema) === true) {
            throw new \RuntimeException('Case schema not configured');
        }

        // Resolve the DSO register containing the vergunningaanvraag.
        $dsoRegister = $this->resolveDsoRegister(objectService: $objectService);

        // Fetch the vergunningaanvraag from OpenRegister.
        $aanvraag = null;
        if ($dsoRegister !== null) {
            try {
                $aanvraag = $objectService->findObject(
                    register: $dsoRegister,
                    schema: 'vergunningaanvraag',
                    id: $vergunningaanvraagId,
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'DsoCaseService: could not fetch vergunningaanvraag from DSO register',
                    ['id' => $vergunningaanvraagId, 'error' => $e->getMessage(), 'app' => Application::APP_ID],
                );
            }
        }

        if ($aanvraag === null) {
            // Fallback: treat the ID as the reference string directly.
            $aanvraag = [
                'uuid'          => $vergunningaanvraagId,
                'identificatie' => $vergunningaanvraagId,
                'activiteiten'  => [],
                'status'        => 'ingediend',
            ];
        }

        $procedureType   = $this->determineProcedureType(aanvraag: $aanvraag);
        $indieningsdatum = (string) ($aanvraag['indieningsdatum'] ?? date('Y-m-d'));
        $deadlineDatum   = $this->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: $procedureType,
        );

        // Build the zaak title from activiteiten.
        $activiteiten  = $aanvraag['activiteiten'] ?? [];
        $activiteitStr = $this->buildActiviteitString(activiteiten: $activiteiten);
        $title         = 'Omgevingsvergunning';
        if ($activiteitStr !== '') {
            $title .= ': '.$activiteitStr;
        }

        $zaakData = [
            'title'                 => $title,
            'description'           => 'Omgevingsvergunningszaak aangemaakt vanuit DSO Omgevingsloket.',
            'startDate'             => date('Y-m-d'),
            'priority'              => 'normal',
            'intakeChannel'         => 'overig',
            'vergunningaanvraagRef' => $vergunningaanvraagId,
            'procedureType'         => $procedureType,
            'deadlineDatum'         => $deadlineDatum,
            'bevoegdGezag'          => (string) ($aanvraag['bevoegdGezag'] ?? ''),
            'dsoStatus'             => (string) ($aanvraag['status'] ?? 'ingediend'),
            'samenwerkverzoeken'    => [],
        ];

        $zaak = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaakData,
        );

        $zaakArray = $this->objectToArray(obj: $zaak);

        $this->logger->info(
            'DsoCaseService: zaak created from vergunningaanvraag',
            [
                'app'                   => Application::APP_ID,
                'vergunningaanvraagRef' => $vergunningaanvraagId,
                'procedureType'         => $procedureType,
                'deadlineDatum'         => $deadlineDatum,
            ],
        );

        return $zaakArray;
    }//end createZaakFromVergunningaanvraag()

    /**
     * Transition the status of a Procest zaak and mirror to the vergunningaanvraag.
     *
     * Updates both the Procest zaak and the OpenRegister vergunningaanvraag in a
     * single service call, appends an activity entry, and dispatches
     * VergunningStatusChangedEvent for OpenConnector to push to DSO-LV.
     *
     * @param string      $zaakId       UUID of the Procest zaak
     * @param string      $newStatus    New DSO status (ingediend/in_behandeling/verleend/geweigerd/ingetrokken)
     * @param string|null $besluitdatum Optional decision date (for verleend/geweigerd)
     * @param string|null $toelichting  Optional decision motivation
     * @param string      $userId       Nextcloud user ID of the initiating user
     *
     * @return array<string, mixed> Updated zaak data
     *
     * @throws \RuntimeException When OpenRegister is unavailable or zaak not found
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
     */
    public function transitionStatus(
        string $zaakId,
        string $newStatus,
        ?string $besluitdatum,
        ?string $toelichting,
        string $userId,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            throw new \RuntimeException('Procest register or case schema not configured');
        }

        $zaak = $objectService->findObject(
            register: $register,
            schema: $caseSchema,
            id: $zaakId,
        );

        if ($zaak === null) {
            throw new \RuntimeException('zaak_not_found');
        }

        $zaakArray = $this->objectToArray(obj: $zaak);

        $oldStatus = (string) ($zaakArray['dsoStatus'] ?? '');

        // Update the Procest zaak.
        $zaakArray['dsoStatus'] = $newStatus;
        if ($besluitdatum !== null) {
            $zaakArray['besluitdatum'] = $besluitdatum;
        }

        if ($toelichting !== null) {
            $zaakArray['dsoToelichting'] = $toelichting;
        }

        // Append activity entry.
        $activityRaw = $zaakArray['activity'] ?? '[]';
        $activityStr = '[]';
        if (is_string($activityRaw) === true) {
            $activityStr = $activityRaw;
        }

        $activities = json_decode($activityStr, true) ?? [];

        $activities[] = [
            'type'      => 'status_transition',
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'userId'    => $userId,
            'timestamp' => date('c'),
        ];

        $zaakArray['activity'] = json_encode($activities);

        $updatedZaak = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaakArray,
        );

        // Mirror to vergunningaanvraag in DSO register.
        $vergunningaanvraagRef = (string) ($zaakArray['vergunningaanvraagRef'] ?? '');
        if ($vergunningaanvraagRef !== '') {
            $this->mirrorStatusToVergunningaanvraag(
                objectService: $objectService,
                vergunningaanvraagRef: $vergunningaanvraagRef,
                newStatus: $newStatus,
                besluitdatum: $besluitdatum,
                toelichting: $toelichting,
            );
        }

        // Dispatch typed event for OpenConnector.
        $event = new VergunningStatusChangedEvent(
            vergunningaanvraagRef: $vergunningaanvraagRef,
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            besluitdatum: $besluitdatum,
            toelichting: $toelichting,
            userId: $userId,
        );
        $this->dispatcher->dispatchTyped(event: $event);

        $this->logger->info(
            'DsoCaseService: status transitioned',
            [
                'app'       => Application::APP_ID,
                'zaakId'    => $zaakId,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'userId'    => $userId,
            ],
        );

        return $this->objectToArray(obj: $updatedZaak, fallback: $zaakArray);
    }//end transitionStatus()

    /**
     * Compute the statutory deadline date for a vergunningaanvraag.
     *
     * Adds the required working days (excluding weekends and Dutch national
     * holidays) to the indieningsdatum. Per Omgevingswet:
     *   - reguliere procedure: 40 working days (8 weeks)
     *   - uitgebreide procedure: 130 working days (26 weeks)
     *
     * @param string $indieningsdatum ISO 8601 date of submission
     * @param string $procedureType   'reguliere' or 'uitgebreide'
     *
     * @return string ISO 8601 date of the deadline
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
     */
    public function computeDeadline(string $indieningsdatum, string $procedureType): string
    {
        $workingDays = self::PROCEDURE_WORKING_DAYS[$procedureType] ?? self::PROCEDURE_WORKING_DAYS['reguliere'];

        try {
            $current = new \DateTimeImmutable($indieningsdatum);
        } catch (\Exception $e) {
            $current = new \DateTimeImmutable('today');
        }

        $year     = (int) $current->format('Y');
        $holidays = $this->getDutchHolidays(year: $year);
        // Also include next year in case deadline crosses year boundary.
        $holidays = array_merge($holidays, $this->getDutchHolidays(year: $year + 1));

        $remaining = $workingDays;
        while ($remaining > 0) {
            $current = $current->modify('+1 day');
            if ($this->isWorkingDay(date: $current, holidays: $holidays) === true) {
                $remaining--;
            }
        }

        return $current->format('Y-m-d');
    }//end computeDeadline()

    /**
     * Determine the procedure type from the activiteiten on a vergunningaanvraag.
     *
     * Defaults to 'reguliere'; switches to 'uitgebreide' when any activiteit
     * has a `procedureType` field set to 'uitgebreid'.
     *
     * @param array<string, mixed> $aanvraag The vergunningaanvraag object array
     *
     * @return string 'reguliere' or 'uitgebreide'
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
     */
    public function determineProcedureType(array $aanvraag): string
    {
        // Honour an explicit field on the aanvraag first.
        if (isset($aanvraag['procedureType']) === true) {
            $pt = strtolower((string) $aanvraag['procedureType']);
            if ($pt === 'uitgebreid' || $pt === 'uitgebreide') {
                return 'uitgebreide';
            }

            if ($pt === 'regulier' || $pt === 'reguliere') {
                return 'reguliere';
            }
        }

        // Inspect activiteiten for an uitgebreide flag.
        $activiteiten = $aanvraag['activiteiten'] ?? [];
        if (is_array($activiteiten) === false) {
            return 'reguliere';
        }

        foreach ($activiteiten as $activiteit) {
            if (is_array($activiteit) === false) {
                continue;
            }

            $pt = strtolower((string) ($activiteit['procedureType'] ?? ''));
            if ($pt === 'uitgebreid' || $pt === 'uitgebreide') {
                return 'uitgebreide';
            }
        }

        return 'reguliere';
    }//end determineProcedureType()

    /**
     * Check whether a given date is a working day (not a weekend or holiday).
     *
     * @param \DateTimeImmutable $date     The date to check
     * @param array<string>      $holidays List of holiday dates as 'Y-m-d' strings
     *
     * @return bool True when the date is a working day
     */
    private function isWorkingDay(\DateTimeImmutable $date, array $holidays): bool
    {
        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek >= 6) {
            return false;
        }

        return in_array($date->format('Y-m-d'), $holidays, true) === false;
    }//end isWorkingDay()

    /**
     * Return Dutch national holidays for a given year.
     *
     * Computes Easter-dependent holidays (Pasen, Hemelvaartsdag, Pinksteren)
     * for the given year and merges with fixed-date holidays.
     *
     * @param int $year The year
     *
     * @return array<string> Dates in 'Y-m-d' format
     */
    private function getDutchHolidays(int $year): array
    {
        $easter = new \DateTimeImmutable(date('Y-m-d', easter_date($year)));

        $holidays = [
            // Fixed dates.
            $year.'-01-01',
            $year.'-04-27',
            $year.'-05-05',
            $year.'-12-25',
            $year.'-12-26',
            // Easter.
            $easter->format('Y-m-d'),
            $easter->modify('+1 day')->format('Y-m-d'),
            // Ascension (39 days after Easter).
            $easter->modify('+39 days')->format('Y-m-d'),
            // Pentecost (49 days after Easter).
            $easter->modify('+49 days')->format('Y-m-d'),
            $easter->modify('+50 days')->format('Y-m-d'),
        ];

        return $holidays;
    }//end getDutchHolidays()

    /**
     * Mirror a status change to the vergunningaanvraag in the DSO register.
     *
     * @param object      $objectService         OpenRegister ObjectService
     * @param string      $vergunningaanvraagRef The vergunningaanvraag ID/ref
     * @param string      $newStatus             New DSO status
     * @param string|null $besluitdatum          Optional decision date
     * @param string|null $toelichting           Optional toelichting
     *
     * @return void
     */
    private function mirrorStatusToVergunningaanvraag(
        object $objectService,
        string $vergunningaanvraagRef,
        string $newStatus,
        ?string $besluitdatum,
        ?string $toelichting,
    ): void {
        $dsoRegister = $this->resolveDsoRegister(objectService: $objectService);
        if ($dsoRegister === null) {
            return;
        }

        try {
            $aanvraag = $objectService->findObject(
                register: $dsoRegister,
                schema: 'vergunningaanvraag',
                id: $vergunningaanvraagRef,
            );

            if ($aanvraag === null) {
                return;
            }

            $aanvraagArray           = $this->objectToArray(obj: $aanvraag);
            $aanvraagArray['status'] = $newStatus;
            if ($besluitdatum !== null) {
                $aanvraagArray['besluitdatum'] = $besluitdatum;
            }

            if ($toelichting !== null) {
                $aanvraagArray['toelichting'] = $toelichting;
            }

            $objectService->saveObject(
                register: $dsoRegister,
                schema: 'vergunningaanvraag',
                object: $aanvraagArray,
            );
        } catch (\Throwable $e) {
            // Non-fatal — log and continue; the Procest zaak is the source of truth.
            $this->logger->warning(
                'DsoCaseService: could not mirror status to vergunningaanvraag',
                ['ref' => $vergunningaanvraagRef, 'error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
        }//end try
    }//end mirrorStatusToVergunningaanvraag()

    /**
     * Resolve the DSO register slug or ID from app config or fallback.
     *
     * @param object $objectService OpenRegister ObjectService
     *
     * @return string|null The register slug/ID, or null when not resolvable
     */
    private function resolveDsoRegister(object $objectService): ?string
    {
        // Check if a DSO register is explicitly configured.
        $dsoRegister = $this->settingsService->getConfigValue('dso_register', '');
        if ($dsoRegister !== '') {
            return $dsoRegister;
        }

        // Fallback: attempt 'dso' slug by convention.
        return 'dso';
    }//end resolveDsoRegister()

    /**
     * Build a human-readable string from an activiteiten array.
     *
     * @param array<mixed> $activiteiten List of activiteit values or objects
     *
     * @return string Comma-separated names
     */
    private function buildActiviteitString(array $activiteiten): string
    {
        $names = array_map(
            static function ($a): string {
                if (is_array($a) === true) {
                    return (string) ($a['naam'] ?? $a['identificatie'] ?? '');
                }

                return (string) $a;
            },
            $activiteiten,
        );

        return implode(', ', array_filter($names));
    }//end buildActiviteitString()

    /**
     * Convert an object or array returned by ObjectService to a plain array.
     *
     * Falls back to $fallback when the input cannot be converted.
     *
     * @param mixed               $obj      Object or array to convert
     * @param array<string,mixed> $fallback Fallback when conversion fails
     *
     * @return array<string, mixed>
     */
    private function objectToArray(mixed $obj, array $fallback=[]): array
    {
        if (is_array($obj) === true) {
            return $obj;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $serialized = $obj->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return $fallback;
    }//end objectToArray()
}//end class
