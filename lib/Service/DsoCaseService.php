<?php

/**
 * Procest DSO Case Service
 *
 * Manages DSO omgevingsvergunning cases: converts inbound vergunningaanvragen
 * into Procest zaken, mirrors status transitions, and computes Omgevingswet
 * deadlines in working days.
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
 * Service for DSO omgevingsvergunning case lifecycle management.
 *
 * Creates zaken from inbound vergunningaanvragen, transitions status on
 * both the Procest zaak and the OpenRegister vergunningaanvraag in a
 * single service call, and calculates Omgevingswet procedure deadlines.
 */
class DsoCaseService
{

    /**
     * Dutch national holidays (fixed dates, extended through 2030).
     * Format: 'M-d' (month-day, no leading zero padding on month).
     *
     * @var string[]
     */
    private const FIXED_HOLIDAYS = [
        '1-1',
        '4-27',
        '5-5',
        '12-25',
        '12-26',
    ];

    /**
     * Procedure length in working days.
     *
     * @var array<string, int>
     */
    private const PROCEDURE_WORKING_DAYS = [
        'reguliere'   => 40,
        'uitgebreide' => 130,
    ];

    /**
     * Constructor.
     *
     * @param SettingsService  $settingsService Settings service
     * @param IEventDispatcher $eventDispatcher Event dispatcher
     * @param LoggerInterface  $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a Procest zaak from an inbound vergunningaanvraag.
     *
     * Determines procedure type from activiteiten, computes deadline in
     * working days, and stores vergunningaanvraagRef on the new zaak.
     *
     * @param string $vergunningaanvraagId UUID of the vergunningaanvraag in OpenRegister
     *
     * @return array<string, mixed> The created Procest zaak
     *
     * @throws \RuntimeException When OpenRegister or required config is unavailable
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
     */
    public function createZaakFromVergunningaanvraag(string $vergunningaanvraagId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available.');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        if (empty($register) === true) {
            throw new \RuntimeException('Procest register not configured.');
        }

        // Fetch vergunningaanvraag from OpenRegister.
        $aanvraag = null;
        try {
            $aanvraag = $objectService->getObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                id: $vergunningaanvraagId
            );
            if (is_object($aanvraag) === true && method_exists($aanvraag, 'jsonSerialize') === true) {
                $aanvraag = $aanvraag->jsonSerialize();
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DsoCaseService: could not fetch vergunningaanvraag '.$vergunningaanvraagId.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }

        if (is_array($aanvraag) === false) {
            $aanvraag = ['id' => $vergunningaanvraagId];
        }

        $activiteiten    = $aanvraag['activiteiten'] ?? [];
        $indieningsdatum = (string) ($aanvraag['indieningsdatum'] ?? date('Y-m-d'));
        $bevoegdGezag    = (string) ($aanvraag['bevoegdGezag'] ?? '');

        $procedureType = $this->determineProcedureType(activiteiten: $activiteiten);
        $deadlineDatum = $this->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: $procedureType
        );

        $caseTypeId = $this->settingsService->getConfigValue(key: 'dso_case_type');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        $title = 'Omgevingsvergunning';
        if (is_array($activiteiten) === true && count($activiteiten) > 0) {
            $names  = array_map(
                static function ($a) {
                    if (is_array($a) === true) {
                        return $a['naam'] ?? (string) reset($a);
                    }

                    return (string) $a;
                },
                $activiteiten
            );
            $title .= ': '.implode(', ', array_filter($names));
        }

        $zaakData = [
            'title'                 => $title,
            'description'           => 'Aanvraag ontvangen via DSO Omgevingsloket.',
            'startDate'             => date('Y-m-d'),
            'deadline'              => $deadlineDatum,
            'vergunningaanvraagRef' => $vergunningaanvraagId,
            'procedureType'         => $procedureType,
            'deadlineDatum'         => $deadlineDatum,
            'bevoegdGezag'          => $bevoegdGezag,
            'samenwerkverzoeken'    => [],
        ];

        if ($caseTypeId !== '') {
            $zaakData['caseType'] = $caseTypeId;
        }

        $zaak = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaakData
        );
        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaakArr = $zaak->jsonSerialize();
        } else {
            $zaakArr = (array) $zaak;
        }

        $this->logger->info(
            'DsoCaseService: zaak created for vergunningaanvraag '.$vergunningaanvraagId,
            ['app' => Application::APP_ID, 'zaakId' => $zaakArr['id'] ?? ''],
        );

        return $zaakArr;
    }//end createZaakFromVergunningaanvraag()

    /**
     * Transition the status of a DSO zaak and mirror it to the vergunningaanvraag.
     *
     * Updates both the Procest zaak and the OpenRegister vergunningaanvraag in
     * a single service call, appends an activity entry, and dispatches
     * VergunningStatusChangedEvent.
     *
     * @param string      $zaakId       UUID of the Procest zaak
     * @param string      $newStatus    Target status from DSO enum
     * @param string|null $besluitdatum Optional decision date (verleend/geweigerd)
     * @param string|null $toelichting  Optional decision motivation
     * @param string      $userId       Nextcloud UID of the acting user
     *
     * @return array<string, mixed> Updated Procest zaak
     *
     * @throws \RuntimeException When OpenRegister is unavailable
     * @throws \InvalidArgumentException When status is not a valid DSO status
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
        $validStatuses = ['ingediend', 'in_behandeling', 'verleend', 'geweigerd', 'ingetrokken'];
        if (in_array(needle: $newStatus, haystack: $validStatuses, strict: true) === false) {
            throw new \InvalidArgumentException(
                'Invalid DSO status: '.$newStatus.'. Valid: '.implode(', ', $validStatuses)
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available.');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        // Load the Procest zaak.
        $zaak = $objectService->getObject(
            register: $register,
            schema: $caseSchema,
            id: $zaakId
        );
        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaak = $zaak->jsonSerialize();
        }

        $oldStatus = (string) ($zaak['status'] ?? '');

        // Update Procest zaak status.
        $zaak['status']    = $newStatus;
        $zaak['updatedBy'] = $userId;
        if ($besluitdatum !== null) {
            $zaak['besluitdatum'] = $besluitdatum;
        }

        if ($toelichting !== null) {
            $zaak['toelichting'] = $toelichting;
        }

        // Append activity entry.
        $rawActivity = $zaak['activity'] ?? null;
        if (is_array($rawActivity) === true) {
            $activity = $rawActivity;
        } else if (is_string($rawActivity) === true) {
            $activity = json_decode(json: $rawActivity, associative: true) ?? [];
        } else {
            $activity = [];
        }

        $activity[]       = [
            'type'      => 'statusTransition',
            'userId'    => $userId,
            'timestamp' => date('c'),
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
        ];
        $zaak['activity'] = $activity;

        $updated = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaak
        );
        if (is_object($updated) === true && method_exists($updated, 'jsonSerialize') === true) {
            $zaakArr = $updated->jsonSerialize();
        } else {
            $zaakArr = (array) $updated;
        }

        // Mirror status to vergunningaanvraag in OpenRegister.
        $vergunningRef = (string) ($zaak['vergunningaanvraagRef'] ?? '');
        if ($vergunningRef !== '') {
            $this->mirrorStatusToAanvraag(
                vergunningRef: $vergunningRef,
                newStatus: $newStatus,
                besluitdatum: $besluitdatum,
                toelichting: $toelichting,
                objectService: $objectService
            );
        }

        // Dispatch event for OpenConnector to push to DSO-LV.
        $event = new VergunningStatusChangedEvent(
            vergunningaanvraagRef: $vergunningRef,
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            besluitdatum: $besluitdatum,
            toelichting: $toelichting,
            userId: $userId
        );
        $this->eventDispatcher->dispatchTyped(event: $event);

        return $zaakArr;
    }//end transitionStatus()

    /**
     * Compute the Omgevingswet deadline excluding weekends and Dutch holidays.
     *
     * @param string $indieningsdatum Start date (Y-m-d)
     * @param string $procedureType   One of 'reguliere' or 'uitgebreide'
     *
     * @return string Deadline date in Y-m-d format
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
     */
    public function computeDeadline(string $indieningsdatum, string $procedureType): string
    {
        $workingDays = self::PROCEDURE_WORKING_DAYS[$procedureType] ?? self::PROCEDURE_WORKING_DAYS['reguliere'];

        try {
            $date = new \DateTimeImmutable(datetime: $indieningsdatum);
        } catch (\Throwable) {
            $date = new \DateTimeImmutable();
        }

        $added = 0;
        while ($added < $workingDays) {
            $date = $date->modify(modifier: '+1 day');
            if ($this->isWorkingDay(date: $date) === true) {
                $added++;
            }
        }

        return $date->format(format: 'Y-m-d');
    }//end computeDeadline()

    /**
     * Determine procedure type from activiteiten array.
     *
     * Returns 'uitgebreide' when any activiteit is flagged, 'reguliere' otherwise.
     *
     * @param array<mixed> $activiteiten Array of activiteit identifiers or objects
     *
     * @return string 'reguliere' or 'uitgebreide'
     */
    private function determineProcedureType(array $activiteiten): string
    {
        foreach ($activiteiten as $act) {
            if (is_array($act) === true && isset($act['procedureType']) === true) {
                if ($act['procedureType'] === 'uitgebreide') {
                    return 'uitgebreide';
                }
            }
        }

        return 'reguliere';
    }//end determineProcedureType()

    /**
     * Check whether a given date is a working day (not a weekend or Dutch holiday).
     *
     * @param \DateTimeImmutable $date The date to check
     *
     * @return bool True when the date is a working day
     */
    private function isWorkingDay(\DateTimeImmutable $date): bool
    {
        $dow = (int) $date->format(format: 'N');
        if ($dow >= 6) {
            return false;
        }

        $key = ltrim(string: $date->format(format: 'n'), characters: '0').'-'
            .ltrim(string: $date->format(format: 'j'), characters: '0');

        if (in_array(needle: $key, haystack: self::FIXED_HOLIDAYS, strict: true) === true) {
            return false;
        }

        // Easter-relative holidays (Goede Vrijdag, Paasmaandag, Hemelvaartsdag, Pinksteren).
        $year        = (int) $date->format(format: 'Y');
        $easterStamp = easter_date(year: $year);
        $easter      = (new \DateTimeImmutable())->setTimestamp(timestamp: $easterStamp)->setTime(hour: 0, minute: 0);

        $easterRelative = [
            $easter->modify(modifier: '-2 days')->format(format: 'Y-m-d'),
            $easter->modify(modifier: '+1 day')->format(format: 'Y-m-d'),
            $easter->modify(modifier: '+39 days')->format(format: 'Y-m-d'),
            $easter->modify(modifier: '+49 days')->format(format: 'Y-m-d'),
            $easter->modify(modifier: '+50 days')->format(format: 'Y-m-d'),
        ];

        return in_array(needle: $date->format(format: 'Y-m-d'), haystack: $easterRelative, strict: true) === false;
    }//end isWorkingDay()

    /**
     * Mirror status change to the OpenRegister vergunningaanvraag.
     *
     * @param string      $vergunningRef Reference to vergunningaanvraag
     * @param string      $newStatus     New DSO status value
     * @param string|null $besluitdatum  Decision date (optional)
     * @param string|null $toelichting   Motivation (optional)
     * @param object      $objectService OpenRegister ObjectService
     *
     * @return void
     */
    private function mirrorStatusToAanvraag(
        string $vergunningRef,
        string $newStatus,
        ?string $besluitdatum,
        ?string $toelichting,
        object $objectService,
    ): void {
        try {
            $aanvraag = $objectService->getObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                id: $vergunningRef
            );
            if (is_object($aanvraag) === true && method_exists($aanvraag, 'jsonSerialize') === true) {
                $aanvraag = $aanvraag->jsonSerialize();
            }

            if (is_array($aanvraag) === false) {
                return;
            }

            $aanvraag['status'] = $newStatus;
            if ($besluitdatum !== null) {
                $aanvraag['besluitdatum'] = $besluitdatum;
            }

            if ($toelichting !== null) {
                $aanvraag['toelichting'] = $toelichting;
            }

            $objectService->saveObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                object: $aanvraag
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DsoCaseService: could not mirror status to vergunningaanvraag '.$vergunningRef.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }//end try
    }//end mirrorStatusToAanvraag()
}//end class
