<?php

/**
 * Procest DSO Intake Service
 *
 * Service for receiving and processing vergunningaanvragen from the
 * Digitaal Stelsel Omgevingswet (DSO/Omgevingsloket).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for DSO/Omgevingsloket intake processing.
 *
 * Creates permit cases from DSO vergunningaanvraag messages.
 * Supports multiple activities per application and calculates
 * deadlines based on procedure type (regulier: 8 weeks, uitgebreid: 26 weeks).
 */
class DsoIntakeService
{

    /**
     * Deadline durations per procedure type (ISO 8601).
     */
    private const DEADLINE_DURATIONS = [
        'regulier'   => 'P56D',
        'uitgebreid' => 'P182D',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process a DSO vergunningaanvraag and create a case.
     *
     * @param array<string, mixed> $dsoMessage The DSO message payload
     *
     * @return array<string, mixed> Created case data with ID
     *
     * @throws \RuntimeException If OpenRegister is unavailable or configuration missing

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function processAanvraag(array $dsoMessage): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        if (empty($register) === true) {
            throw new RuntimeException('Procest register not configured');
        }

        // Extract fields from DSO message.
        $activiteiten  = $dsoMessage['activiteiten'] ?? [];
        $locatie       = $dsoMessage['locatie'] ?? '';
        $aanvrager     = $dsoMessage['aanvrager'] ?? [];
        $bouwkosten    = $dsoMessage['bouwkosten'] ?? 0;
        $procedureType = $dsoMessage['procedureType'] ?? 'regulier';
        $dsoZaaknummer = $dsoMessage['zaaknummer'] ?? '';

        // Build activity description.
        $activityNames = array_map(
            static function ($act) {
                if (is_array($act) === true) {
                    return $act['naam'] ?? '';
                }

                return (string) $act;
            },
            $activiteiten,
        );
        $activityStr   = implode(', ', array_filter($activityNames));

        // Determine processing deadline.
        $deadline = self::DEADLINE_DURATIONS[$procedureType] ?? self::DEADLINE_DURATIONS['regulier'];

        // Create the case.
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        $title = 'Omgevingsvergunning';
        if ($activityStr !== '') {
            $title .= ': '.$activityStr;
        }

        $description = 'Vergunningaanvraag ontvangen via DSO/Omgevingsloket';
        if ($dsoZaaknummer !== '') {
            $description .= ' (DSO: '.$dsoZaaknummer.')';
        }

        $caseData = [
            'title'       => $title,
            'description' => $description,
            'startDate'   => date('Y-m-d'),
            'priority'    => 'normal',
        ];

        $caseObj = $objectService->saveObject(object: $caseData, register: $register, schema: $caseSchema);
        $caseId  = $caseObj->getUuid();

        // Store DSO-specific properties.
        $propertySchema = $this->settingsService->getConfigValue('case_property_schema');
        if (is_array($locatie) === true) {
            $locatieValue = json_encode($locatie);
        } else {
            $locatieValue = $locatie;
        }

        $properties = [
            'dsoZaaknummer' => $dsoZaaknummer,
            'activiteiten'  => $activityStr,
            'locatie'       => $locatieValue,
            'bouwkosten'    => (string) $bouwkosten,
            'procedureType' => $procedureType,
            'aanvragerNaam' => $aanvrager['naam'] ?? '',
        ];

        foreach ($properties as $name => $value) {
            if ($value === '') {
                continue;
            }

            $objectService->saveObject(
                object: [
                    'case'  => $caseId,
                    'name'  => $name,
                    'value' => $value,
                ],
                register: $register,
                schema: $propertySchema
            );
        }

        $this->logger->info(
            'DSO intake processed: case '.$caseId.' (DSO: '.$dsoZaaknummer.')',
            ['app' => Application::APP_ID],
        );

        return [
            'caseId'        => $caseId,
            'dsoZaaknummer' => $dsoZaaknummer,
            'activiteiten'  => $activityNames,
            'procedureType' => $procedureType,
            'deadline'      => $deadline,
        ];
    }//end processAanvraag()

    /**
     * Get the processing deadline duration for a procedure type.
     *
     * @param string $procedureType The procedure type (regulier or uitgebreid)
     *
     * @return string ISO 8601 duration
     */
    public function getDeadlineDuration(string $procedureType): string
    {
        return self::DEADLINE_DURATIONS[$procedureType] ?? self::DEADLINE_DURATIONS['regulier'];
    }//end getDeadlineDuration()

    /**
     * Map a raw DSO payload to a structured case array.
     *
     * @param array<string, mixed> $dsoMessage The DSO vergunningaanvraag payload
     *
     * @return array<string, mixed> Structured case data ready for createCase()
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function map(array $dsoMessage): array
    {
        $activiteiten  = $dsoMessage['activiteiten'] ?? [];
        $locatie       = $dsoMessage['locatie'] ?? '';
        $aanvrager     = $dsoMessage['aanvrager'] ?? [];
        $bouwkosten    = $dsoMessage['bouwkosten'] ?? 0;
        $procedureType = $dsoMessage['procedureType'] ?? 'regulier';
        $dsoZaaknummer = $dsoMessage['zaaknummer'] ?? '';
        $bijlagen      = $dsoMessage['bijlagen'] ?? [];

        $activityNames = array_map(
            static function ($act) {
                if (is_array($act) === true) {
                    return $act['naam'] ?? '';
                }

                return (string) $act;
            },
            $activiteiten,
        );
        $activityStr   = implode(', ', array_filter($activityNames));

        $deadline = self::DEADLINE_DURATIONS[$procedureType] ?? self::DEADLINE_DURATIONS['regulier'];

        $title = 'Omgevingsvergunning';
        if ($activityStr !== '') {
            $title .= ': '.$activityStr;
        }

        $description = 'Vergunningaanvraag ontvangen via DSO/Omgevingsloket';
        if ($dsoZaaknummer !== '') {
            $description .= ' (DSO: '.$dsoZaaknummer.')';
        }

        if (is_array($locatie) === true) {
            $locatieStr = json_encode($locatie);
        } else {
            $locatieStr = (string) $locatie;
        }

        return [
            'title'         => $title,
            'description'   => $description,
            'startDate'     => date('Y-m-d'),
            'priority'      => 'normal',
            'dsoZaaknummer' => $dsoZaaknummer,
            'activiteiten'  => $activityStr,
            'activityNames' => $activityNames,
            'locatie'       => $locatieStr,
            'bouwkosten'    => (string) $bouwkosten,
            'procedureType' => $procedureType,
            'aanvragerNaam' => $aanvrager['naam'] ?? '',
            'deadline'      => $deadline,
            'bijlagen'      => $bijlagen,
        ];
    }//end map()

    /**
     * Create a case from pre-mapped DSO data.
     *
     * @param array<string, mixed> $mappedData Structured case data from map()
     *
     * @return array<string, mixed> Created case data with ID
     *
     * @throws \RuntimeException If OpenRegister is unavailable or configuration missing.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function createCase(array $mappedData): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        if (empty($register) === true) {
            throw new RuntimeException('Procest register not configured');
        }

        $caseSchema = $this->settingsService->getConfigValue('case_schema');
        $caseData   = [
            'title'       => $mappedData['title'] ?? 'Omgevingsvergunning',
            'description' => $mappedData['description'] ?? '',
            'startDate'   => $mappedData['startDate'] ?? date('Y-m-d'),
            'priority'    => $mappedData['priority'] ?? 'normal',
        ];

        $caseObj = $objectService->saveObject($register, $caseSchema, $caseData);
        $caseId  = $caseObj->getUuid();

        $propertySchema = $this->settingsService->getConfigValue('case_property_schema');
        $properties     = [
            'dsoZaaknummer' => $mappedData['dsoZaaknummer'] ?? '',
            'activiteiten'  => $mappedData['activiteiten'] ?? '',
            'locatie'       => $mappedData['locatie'] ?? '',
            'bouwkosten'    => $mappedData['bouwkosten'] ?? '',
            'procedureType' => $mappedData['procedureType'] ?? '',
            'aanvragerNaam' => $mappedData['aanvragerNaam'] ?? '',
        ];

        foreach ($properties as $name => $value) {
            if ($value === '') {
                continue;
            }

            $objectService->saveObject(
                $register,
                $propertySchema,
                [
                    'case'  => $caseId,
                    'name'  => $name,
                    'value' => $value,
                ]
            );
        }

        $this->logger->info(
            'DSO intake: created case '.$caseId,
            ['app' => Application::APP_ID],
        );

        return [
            'caseId'        => $caseId,
            'dsoZaaknummer' => $mappedData['dsoZaaknummer'] ?? '',
            'activiteiten'  => $mappedData['activityNames'] ?? [],
            'procedureType' => $mappedData['procedureType'] ?? '',
            'deadline'      => $mappedData['deadline'] ?? '',
        ];
    }//end createCase()
}//end class
