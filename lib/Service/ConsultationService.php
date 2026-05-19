<?php

/**
 * Procest Consultation Service
 *
 * Service for managing inter-departmental consultations (adviesaanvragen)
 * with full status lifecycle, dependency validation, and milestone gate support.
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
 * @spec openspec/changes/consultation-management/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Service for consultation (adviesaanvraag) management.
 *
 * Owns the domain operations for inter-departmental consultations:
 *   - createConsultation()          — validate + auto-number + persist
 *   - getConsultation()             — fetch single record
 *   - getConsultationsForCase()     — fetch all for a parent zaak
 *   - transitionStatus()            — enforce status machine with notifications
 *   - claimConsultation()           — assign individual handler
 *   - requestExtension()            — register a deadline-extension request
 *   - approveExtension()            — approve and update deadline
 *   - getBlockingConsultations()    — mandatory pending consultations for a zaak
 *   - validateDependencyCycle()     — BFS cycle check on dependsOn graph
 *   - getOverdueConsultations()     — open/in_behandeling past deadline
 *   - getConsultationsByDepartment() — fetch by advisory body
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-2
 */
class ConsultationService
{

    /**
     * All valid consultation statuses.
     */
    private const VALID_STATUSES = [
        'open',
        'ontvangen',
        'in_behandeling',
        'advies_uitgebracht',
        'afgesloten',
        'ingetrokken',
    ];

    /**
     * Forward-only status transitions (non-coordinator path).
     *
     * Key = current status, value = allowed next statuses.
     *
     * @var array<string, string[]>
     */
    private const FORWARD_TRANSITIONS = [
        'open'               => ['ontvangen', 'ingetrokken'],
        'ontvangen'          => ['in_behandeling', 'ingetrokken'],
        'in_behandeling'     => ['advies_uitgebracht', 'ingetrokken'],
        'advies_uitgebracht' => ['afgesloten', 'ingetrokken'],
        'afgesloten'         => [],
        'ingetrokken'        => [],
    ];

    /**
     * Statuses that are considered terminal (no further non-coordinator moves).
     *
     * @var string[]
     */
    private const TERMINAL_STATUSES = ['afgesloten', 'ingetrokken'];

    /**
     * Prefix used for auto-generated consultation numbers.
     */
    private const NUMBER_PREFIX = 'ADV';

    /**
     * Constructor.
     *
     * @param SettingsService      $settingsService     The settings service
     * @param IUserSession         $userSession         The current user session
     * @param INotificationManager $notificationManager The notification manager
     * @param LoggerInterface      $logger              The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new consultation linked to a parent zaak.
     *
     * Validates required fields, auto-generates a unique number in the format
     * ADV-{year}-{seq} (seq = count of existing consultations this year + 1),
     * and persists the record with status=open.
     *
     * @param array<string, mixed> $data        Consultation data
     * @param string               $requesterId The UID of the creating user
     *
     * @return array<string, mixed> Created consultation record
     *
     * @throws \RuntimeException When required fields are missing, schema not configured,
     *                           or OpenRegister unavailable.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function createConsultation(array $data, string $requesterId): array
    {
        if (empty($data['parentZaak']) === true) {
            throw new \RuntimeException('parentZaak is required');
        }

        if (empty($data['adviesInstantie']) === true) {
            throw new \RuntimeException('adviesInstantie is required');
        }

        if (empty($data['onderwerp']) === true) {
            throw new \RuntimeException('onderwerp is required');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema is not configured');
        }

        $year   = (int) date('Y');
        $number = $this->generateConsultationNumber(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            year: $year
        );

        $data['nummer']      = $number;
        $data['status']      = 'open';
        $data['aanvrager']   = $requesterId;
        $data['aanvraagDat'] = date('c');

        try {
            $result = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $data
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to create consultation: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not create consultation');
        }

        $consultation = $this->normalizeResult(result: $result);

        $this->logger->info(
            'Procest: consultation created '.$number.' for zaak '.$data['parentZaak'],
            ['app' => Application::APP_ID]
        );

        return $consultation;
    }//end createConsultation()

    /**
     * Fetch a single consultation by ID.
     *
     * @param string $id The consultation UUID
     *
     * @return array<string, mixed>|null Consultation data, or null when not found
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function getConsultation(string $id): ?array
    {
        return $this->loadConsultation(consultationId: $id);
    }//end getConsultation()

    /**
     * Get all consultations linked to a parent zaak.
     *
     * @param string $caseId The parent zaak UUID
     *
     * @return array<int, array<string, mixed>> Consultations for the case
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function getConsultationsForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        try {
            $results = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['parentZaak' => $caseId, '_limit' => 200]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to fetch consultations for case: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end getConsultationsForCase()

    /**
     * Transition a consultation to a new status, enforcing the state machine.
     *
     * Forward transitions are allowed for all users. Backward transitions
     * (moving to a status that is not a valid forward move) are only allowed
     * when $isCoordinator is true.
     *
     * Notifications are fired on acknowledge (ontvangen) and on advice submission
     * (advies_uitgebracht).
     *
     * @param string $consultationId The consultation UUID
     * @param string $newStatus      Target status
     * @param bool   $isCoordinator  Whether the caller has coordinator privileges
     *
     * @return array<string, mixed> Updated consultation record
     *
     * @throws \RuntimeException When transition is invalid, consultation not found,
     *                           or OpenRegister unavailable.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function transitionStatus(
        string $consultationId,
        string $newStatus,
        bool $isCoordinator=false
    ): array {
        if (in_array(needle: $newStatus, haystack: self::VALID_STATUSES, strict: true) === false) {
            throw new \RuntimeException('Invalid consultation status: '.$newStatus);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema is not configured');
        }

        $current = $this->loadConsultation(consultationId: $consultationId);
        if ($current === null) {
            throw new \RuntimeException('Consultation not found: '.$consultationId);
        }

        $currentStatus = (string) ($current['status'] ?? 'open');

        $this->assertTransitionAllowed(
            currentStatus: $currentStatus,
            newStatus: $newStatus,
            isCoordinator: $isCoordinator
        );

        $update = ['status' => $newStatus];

        if ($newStatus === 'ontvangen') {
            $update['ontvangenOp'] = date('c');
        }

        if ($newStatus === 'in_behandeling') {
            $update['inBehandelingOp'] = date('c');
        }

        if ($newStatus === 'advies_uitgebracht') {
            $update['adviesUitgebrachtOp'] = date('c');
        }

        if ($newStatus === 'afgesloten') {
            $update['afgeslotenOp'] = date('c');
        }

        if ($newStatus === 'ingetrokken') {
            $update['ingetrokkenOp'] = date('c');
        }

        try {
            $result = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $update,
                id: $consultationId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to transition consultation status: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not update consultation status');
        }

        $consultation = $this->normalizeResult(result: $result);

        $this->fireTransitionNotification(
            newStatus: $newStatus,
            current: $current,
            consultationId: $consultationId
        );

        return $consultation;
    }//end transitionStatus()

    /**
     * Claim a consultation by assigning an individual handler.
     *
     * @param string $consultationId The consultation UUID
     * @param string $userId         The UID of the handler to assign
     *
     * @return array<string, mixed> Updated consultation record
     *
     * @throws \RuntimeException When consultation not found or OpenRegister unavailable.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function claimConsultation(string $consultationId, string $userId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema is not configured');
        }

        $current = $this->loadConsultation(consultationId: $consultationId);
        if ($current === null) {
            throw new \RuntimeException('Consultation not found: '.$consultationId);
        }

        $update = [
            'behandelaar'  => $userId,
            'geclaimeOpAt' => date('c'),
        ];

        try {
            $result = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $update,
                id: $consultationId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to claim consultation: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not claim consultation');
        }

        return $this->normalizeResult(result: $result);
    }//end claimConsultation()

    /**
     * Register a deadline-extension request on a consultation.
     *
     * Sets extensionRequestedAt and extensionJustification on the record.
     *
     * @param string $consultationId The consultation UUID
     * @param string $justification  The reason for the extension request
     *
     * @return array<string, mixed> Updated consultation record
     *
     * @throws \RuntimeException When consultation not found or OpenRegister unavailable.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function requestExtension(string $consultationId, string $justification): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema is not configured');
        }

        $current = $this->loadConsultation(consultationId: $consultationId);
        if ($current === null) {
            throw new \RuntimeException('Consultation not found: '.$consultationId);
        }

        $update = [
            'extensionRequestedAt'   => date('c'),
            'extensionJustification' => $justification,
            'extensionApproved'      => false,
        ];

        try {
            $result = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $update,
                id: $consultationId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to register extension request: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not register extension request');
        }

        return $this->normalizeResult(result: $result);
    }//end requestExtension()

    /**
     * Approve a deadline-extension request and update the deadline.
     *
     * Sets extensionApproved=true and updates uiterlijkeReactiedatum to the
     * provided new deadline.
     *
     * @param string $consultationId The consultation UUID
     * @param string $newDeadline    New deadline in ISO 8601 / Y-m-d format
     *
     * @return array<string, mixed> Updated consultation record
     *
     * @throws \RuntimeException When consultation not found or OpenRegister unavailable.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function approveExtension(string $consultationId, string $newDeadline): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema is not configured');
        }

        $current = $this->loadConsultation(consultationId: $consultationId);
        if ($current === null) {
            throw new \RuntimeException('Consultation not found: '.$consultationId);
        }

        $update = [
            'uiterlijkeReactiedatum' => $newDeadline,
            'extensionApproved'      => true,
            'extensionApprovedAt'    => date('c'),
        ];

        try {
            $result = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $update,
                id: $consultationId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to approve extension: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not approve extension');
        }

        return $this->normalizeResult(result: $result);
    }//end approveExtension()

    /**
     * Return all mandatory consultations for a zaak that are still pending.
     *
     * Pending means the consultation has not yet reached advies_uitgebracht or
     * afgesloten. These block milestone gates on the parent zaak.
     *
     * @param string $zaakId The parent zaak UUID
     *
     * @return array<int, array<string, mixed>> Blocking consultation records
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function getBlockingConsultations(string $zaakId): array
    {
        $all      = $this->getConsultationsForCase(caseId: $zaakId);
        $blocking = [];

        $doneStatuses = ['advies_uitgebracht', 'afgesloten', 'ingetrokken'];

        foreach ($all as $consultation) {
            $status    = (string) ($consultation['status'] ?? '');
            $mandatory = (bool) ($consultation['verplicht'] ?? false);

            if ($mandatory === true && in_array(needle: $status, haystack: $doneStatuses, strict: true) === false) {
                $blocking[] = $consultation;
            }
        }

        return $blocking;
    }//end getBlockingConsultations()

    /**
     * Validate that adding the given dependsOn list to a consultation does not
     * create a dependency cycle.
     *
     * Uses breadth-first search over the dependsOn graph. Returns true when no
     * cycle is detected (the new dependency list is safe to persist).
     *
     * @param string[] $newDependsOn   List of consultation UUIDs the new consultation depends on
     * @param string   $consultationId The consultation being edited (excluded from cycle check)
     *
     * @return bool True when the dependency graph remains acyclic
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function validateDependencyCycle(array $newDependsOn, string $consultationId): bool
    {
        // BFS: starting from each dependency, check if we ever reach $consultationId.
        $visited = [];
        $queue   = $newDependsOn;

        while (empty($queue) === false) {
            $current = array_shift($queue);

            if ($current === $consultationId) {
                // Cycle detected.
                return false;
            }

            if (isset($visited[$current]) === true) {
                continue;
            }

            $visited[$current] = true;

            $node = $this->loadConsultation(consultationId: $current);
            if ($node === null) {
                continue;
            }

            $nodeDeps = (array) ($node['dependsOn'] ?? []);
            foreach ($nodeDeps as $dep) {
                if (isset($visited[$dep]) === false) {
                    $queue[] = (string) $dep;
                }
            }
        }//end while

        return true;
    }//end validateDependencyCycle()

    /**
     * Get all open or in-progress consultations that are past their deadline.
     *
     * Fetches consultations with status open or in_behandeling and filters to
     * those whose uiterlijkeReactiedatum is in the past.
     *
     * @return array<int, array<string, mixed>> Overdue consultation records
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function getOverdueConsultations(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $openList       = [];
        $inProgressList = [];

        try {
            $openResults = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['status' => 'open', '_limit' => 500]
            );
            if (is_array($openResults) === true) {
                $openList = $openResults;
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load open consultations: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        try {
            $inProgressResults = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['status' => 'in_behandeling', '_limit' => 500]
            );
            if (is_array($inProgressResults) === true) {
                $inProgressList = $inProgressResults;
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load in-progress consultations: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        $all     = array_merge($openList, $inProgressList);
        $today   = date('Y-m-d');
        $overdue = [];

        foreach ($all as $consultation) {
            $deadline = (string) ($consultation['uiterlijkeReactiedatum'] ?? '');
            if ($deadline !== '' && substr(string: $deadline, offset: 0, length: 10) < $today) {
                $overdue[] = $consultation;
            }
        }

        return $overdue;
    }//end getOverdueConsultations()

    /**
     * Get all consultations assigned to an advisory body (department).
     *
     * @param string $advisoryBodyId The advisory body UUID
     *
     * @return array<int, array<string, mixed>> Consultation records for the department
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function getConsultationsByDepartment(string $advisoryBodyId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        try {
            $results = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['advisoryBodyId' => $advisoryBodyId, '_limit' => 200]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to fetch consultations by department: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end getConsultationsByDepartment()

    /**
     * Load a single consultation by ID.
     *
     * @param string $consultationId The consultation UUID
     *
     * @return array<string, mixed>|null Consultation data, or null when not found or on error
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function loadConsultation(string $consultationId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $result = $objectService->findObject(
                register: $register,
                schema: $schema,
                id: $consultationId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load consultation: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }

        return $this->normalizeResult(result: $result);
    }//end loadConsultation()

    /**
     * Assert that a status transition is allowed.
     *
     * Forward transitions are allowed for all callers. Backward transitions
     * (not in the forward-transition map for the current status) are only
     * permitted when the caller is a coordinator.
     *
     * @param string $currentStatus The current status of the consultation
     * @param string $newStatus     Target status
     * @param bool   $isCoordinator Whether the caller has coordinator privileges
     *
     * @return void
     *
     * @throws \RuntimeException When the transition is not allowed.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function assertTransitionAllowed(
        string $currentStatus,
        string $newStatus,
        bool $isCoordinator
    ): void {
        if ($currentStatus === $newStatus) {
            // No-op transition is always allowed.
            return;
        }

        if (in_array(needle: $currentStatus, haystack: self::TERMINAL_STATUSES, strict: true) === true) {
            if ($isCoordinator === false) {
                throw new \RuntimeException(
                    'Cannot transition from terminal status '.$currentStatus.' without coordinator role'
                );
            }

            // Coordinators may reopen from terminal statuses.
            return;
        }

        $allowed = self::FORWARD_TRANSITIONS[$currentStatus] ?? [];

        if (in_array(needle: $newStatus, haystack: $allowed, strict: true) === true) {
            // Valid forward transition.
            return;
        }

        // Backward or lateral transition — only coordinators may do this.
        if ($isCoordinator === false) {
            throw new \RuntimeException(
                'Transition from '.$currentStatus.' to '.$newStatus.' requires coordinator role'
            );
        }
    }//end assertTransitionAllowed()

    /**
     * Fire the notification that matches a status transition.
     *
     * Notifies the requester (aanvrager) when the consultation is acknowledged
     * (ontvangen) and when advice has been submitted (advies_uitgebracht).
     *
     * @param string               $newStatus      Target status
     * @param array<string, mixed> $current        Consultation record before update
     * @param string               $consultationId The consultation UUID
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function fireTransitionNotification(
        string $newStatus,
        array $current,
        string $consultationId
    ): void {
        $aanvrager = (string) ($current['aanvrager'] ?? '');

        if ($newStatus === 'ontvangen' && $aanvrager !== '') {
            $this->sendUserNotification(
                userId: $aanvrager,
                subject: 'consultation_ontvangen',
                objectId: $consultationId
            );
            return;
        }

        if ($newStatus === 'advies_uitgebracht' && $aanvrager !== '') {
            $adviesType = (string) ($current['advies'] ?? '');
            $this->sendUserNotification(
                userId: $aanvrager,
                subject: 'consultation_advies_uitgebracht',
                objectId: $consultationId,
                message: $adviesType
            );
        }
    }//end fireTransitionNotification()

    /**
     * Generate an auto-incremented consultation number for the given year.
     *
     * Format: ADV-{year}-{seq} where seq is (count of existing consultations
     * in that year) + 1, zero-padded to 4 digits.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $register      Register configuration value
     * @param string $schema        Schema configuration value
     * @param int    $year          The calendar year
     *
     * @return string The generated consultation number
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function generateConsultationNumber(
        object $objectService,
        string $register,
        string $schema,
        int $year
    ): string {
        $yearStr = (string) $year;
        $prefix  = self::NUMBER_PREFIX.'-'.$yearStr.'-';
        $seq     = 1;

        try {
            $existing = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['nummer' => $prefix, '_limit' => 1000]
            );

            if (is_array($existing) === true) {
                $seq = count($existing) + 1;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: could not count existing consultations for numbering, defaulting to 1: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        return $prefix.str_pad(string: (string) $seq, length: 4, pad_string: '0', pad_type: STR_PAD_LEFT);
    }//end generateConsultationNumber()

    /**
     * Convert an object/array result from OpenRegister to an associative array.
     *
     * @param mixed $result The OpenRegister return value
     *
     * @return array<string, mixed> Normalized record, or empty array when conversion fails
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function normalizeResult($result): array
    {
        if (is_array($result) === true) {
            return $result;
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $data = $result->jsonSerialize();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return [];
    }//end normalizeResult()

    /**
     * Resolve the current user UID from session (never trust client-supplied user).
     *
     * @return string The current user UID or empty string when not authenticated
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function getUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();
    }//end getUserId()

    /**
     * Send a Nextcloud notification to a user.
     *
     * @param string $userId   Recipient user UID
     * @param string $subject  Notification subject key
     * @param string $objectId The consultation UUID
     * @param string $message  Additional message context
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    private function sendUserNotification(
        string $userId,
        string $subject,
        string $objectId,
        string $message='',
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('consultation', $objectId)
                ->setSubject($subject, ['object' => $objectId]);

            if ($message !== '') {
                $notification->setMessage('plain', ['message' => $message]);
            }

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to send consultation notification: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }//end sendUserNotification()

    /**
     * Submit an advice response to a consultation (authenticated path).
     *
     * Saves the adviceResponse object and transitions the consultation status
     * to advies_uitgebracht.
     *
     * @param string               $consultationId The consultation UUID
     * @param array<string, mixed> $response       Response data (advies, toelichting, voorwaarden, datum)
     *
     * @return array<string, mixed> Updated consultation
     *
     * @throws \RuntimeException When advies is invalid, consultation not found, or OR unavailable.
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-2
     */
    public function submitResponse(string $consultationId, array $response): array
    {
        $valid  = ['positief', 'positief_met_voorwaarden', 'negatief', 'niet_van_toepassing'];
        $advies = (string) ($response['advies'] ?? '');
        if (in_array(needle: $advies, haystack: $valid, strict: true) === false) {
            throw new \RuntimeException('Invalid advies type: '.$advies);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $responseSchema = $this->settingsService->getConfigValue('advice_response_schema');

        if (empty($register) === false && empty($responseSchema) === false) {
            try {
                $responseData = [
                    'consultation' => $consultationId,
                    'advies'       => $advies,
                    'toelichting'  => $response['toelichting'] ?? '',
                    'voorwaarden'  => $response['voorwaarden'] ?? [],
                    'datum'        => $response['datum'] ?? date(format: 'Y-m-d'),
                    'createdAt'    => date(format: 'c'),
                ];
                $objectService->saveObject(
                    register: $register,
                    schema: $responseSchema,
                    object: $responseData,
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: failed to save advice response: '.$e->getMessage(),
                    ['app' => Application::APP_ID],
                );
            }
        }//end if

        return $this->transitionStatus(
            consultationId: $consultationId,
            newStatus: 'advies_uitgebracht',
        );
    }//end submitResponse()

    /**
     * Process an advice response from an external advisory body via secure token.
     *
     * Looks up the consultation by secureToken, validates it is still open,
     * and delegates to submitResponse(). Returns null when the token is invalid
     * or the consultation is in a terminal state.
     *
     * @param string               $token    The 256-bit hex secure token
     * @param array<string, mixed> $response Response data from the external body
     *
     * @return array<string, mixed>|null Updated consultation or null on failure
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-4
     */
    public function processExternalResponse(string $token, array $response): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $results = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['secureToken' => $token, '_limit' => 1],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to look up consultation by token: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return null;
        }

        if (is_array($results) === true) {
            $list = $results;
        } else {
            $list = [];
        }

        if (count($list) === 0) {
            return null;
        }

        $consultation = $this->normalizeResult(result: $list[0]);
        $status       = (string) ($consultation['status'] ?? '');

        if (in_array(needle: $status, haystack: self::TERMINAL_STATUSES, strict: true) === true) {
            return null;
        }

        $id = (string) ($consultation['id'] ?? ($consultation['uuid'] ?? ''));
        if ($id === '') {
            return null;
        }

        try {
            return $this->submitResponse(consultationId: $id, response: $response);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: external response processing failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return null;
        }
    }//end processExternalResponse()
}//end class
