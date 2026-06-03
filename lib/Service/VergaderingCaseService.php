<?php

/**
 * Procest Vergadering Case Service
 *
 * Service that wraps ORI vergaderingen as Procest cases, managing the
 * case lifecycle (gepland → lopend → afgerond / geannuleerd), deadline
 * alerts (agenda publication T-7), and an audit trail.
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
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Wraps ORI vergaderingen as Procest cases with lifecycle and deadline tracking.
 *
 * A vergadering is created in the ORI register with status "gepland".  This
 * service creates a linked Procest case so that the full Procest lifecycle
 * engine (status, deadlines, tasks, audit trail) applies to council meetings.
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
 */
class VergaderingCaseService
{

    /**
     * Valid case statuses for vergadering-backed cases.
     *
     * @var string[]
     */
    private const VALID_STATUSES = [
        'gepland',
        'lopend',
        'afgerond',
        'geannuleerd',
    ];

    /**
     * Number of days before the vergadering that the agenda-publication deadline falls.
     *
     * @var int
     */
    private const AGENDA_DEADLINE_DAYS = 7;

    /**
     * Constructor for VergaderingCaseService.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a Procest case for a newly registered vergadering.
     *
     * GIVEN a vergadering created with startDatum
     * THEN a linked Procest case is created with status "gepland"
     * AND deadline = startDatum − 7 days (agenda publication deadline).
     *
     * @param array $vergadering The vergadering object from the ORI register
     *
     * @return array The created case object, or empty array when skipped
     *
     * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
     */
    public function createForVergadering(array $vergadering): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $this->logger->warning(
                'Procest: ObjectService unavailable; skipping vergadering case creation',
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            $this->logger->warning(
                'Procest: case register/schema not configured; skipping vergadering case creation',
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $startDatum = ($vergadering['startDatum'] ?? '');
        $deadline   = '';

        if (empty($startDatum) === false) {
            try {
                $start    = new \DateTimeImmutable(datetime: $startDatum);
                $deadline = $start->modify('-'.self::AGENDA_DEADLINE_DAYS.' days')->format('Y-m-d');
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Procest: could not parse vergadering startDatum for deadline calculation',
                    ['startDatum' => $startDatum, 'error' => $e->getMessage()]
                );
            }
        }

        $caseData = [
            'title'            => ($vergadering['naam'] ?? 'Vergadering'),
            'status'           => 'gepland',
            'deadline'         => $deadline,
            'oriVergaderingId' => ($vergadering['@self']['slug'] ?? ''),
            'oriRegister'      => 'ori',
            'type'             => ($vergadering['type'] ?? ''),
            'organisatie'      => ($vergadering['organisatie'] ?? ''),
        ];

        try {
            $case = $objectService->saveObject(
                register: $register,
                schema: $caseSchema,
                object: $caseData,
            );

            $this->logger->info(
                'Procest: created case for vergadering',
                [
                    'vergaderingSlug' => ($vergadering['@self']['slug'] ?? ''),
                    'caseId'          => ($case['id'] ?? ''),
                    'deadline'        => $deadline,
                ]
            );

            return $case;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to create case for vergadering',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return [];
        }//end try

    }//end createForVergadering()

    /**
     * Advance the status of a vergadering-backed case.
     *
     * @param string $caseId    The UUID of the Procest case to advance
     * @param string $newStatus The target status (gepland|lopend|afgerond|geannuleerd)
     *
     * @return array The updated case object
     *
     * @throws RuntimeException When status is invalid or the case cannot be updated
     *
     * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
     */
    public function advanceStatus(string $caseId, string $newStatus): array
    {
        if (in_array(needle: $newStatus, haystack: self::VALID_STATUSES, strict: true) === false) {
            throw new RuntimeException(
                'Invalid vergadering case status: '.$newStatus.'. '
                .'Valid values: '.implode(separator: ', ', array: self::VALID_STATUSES)
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister ObjectService is not available');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            throw new RuntimeException('Procest case register/schema is not configured');
        }

        try {
            $updated = $objectService->saveObject(
                register: $register,
                schema: $caseSchema,
                object: ['status' => $newStatus],
                id: $caseId,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to advance vergadering case status',
                ['caseId' => $caseId, 'newStatus' => $newStatus, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Could not update vergadering case status: '.$e->getMessage());
        }

        $this->logger->info(
            'Procest: advanced vergadering case status',
            ['caseId' => $caseId, 'newStatus' => $newStatus]
        );

        return ($updated ?? []);

    }//end advanceStatus()

    /**
     * Check all gepland vergadering cases and advance those whose startDatum has passed.
     *
     * GIVEN startDatum reached WHEN nightly job runs
     * THEN status transitions to "lopend".
     *
     * @return int The number of cases advanced to "lopend"
     *
     * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
     */
    public function checkDeadlines(): int
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return 0;
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            return 0;
        }

        $today    = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $advanced = 0;

        try {
            $geplandCases = $objectService->findObjects(
                register: $register,
                schema: $caseSchema,
                params: [
                    'status'   => 'gepland',
                    'deadline' => $today,
                    '_limit'   => 200,
                ]
            );

            foreach ($geplandCases as $case) {
                $caseId = (string) ($case['id'] ?? '');
                if (empty($caseId) === true) {
                    continue;
                }

                try {
                    $this->advanceStatus(caseId: $caseId, newStatus: 'lopend');
                    $advanced++;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Procest: could not advance deadline case',
                        ['caseId' => $caseId, 'exception' => $e->getMessage()]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: deadline check for vergadering cases failed',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
        }//end try

        return $advanced;

    }//end checkDeadlines()
}//end class
