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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

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
     */
    public function processAanvraag(array $dsoMessage): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        if (empty($register) === true) {
            throw new \RuntimeException('Procest register not configured');
        }

        // Extract fields from DSO message.
        $activiteiten  = $dsoMessage['activiteiten'] ?? [];
        $locatie       = $dsoMessage['locatie'] ?? '';
        $aanvrager     = $dsoMessage['aanvrager'] ?? [];
        $bouwkosten    = $dsoMessage['bouwkosten'] ?? 0;
        $procedureType = $dsoMessage['procedureType'] ?? 'regulier';
        $dsoZaaknummer = $dsoMessage['zaaknummer'] ?? '';
        $bijlagen      = $dsoMessage['bijlagen'] ?? [];

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

        $caseObj = $objectService->saveObject($register, $caseSchema, $caseData);
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
}//end class
