<?php

/**
 * Procest Consultation Service
 *
 * Service for managing inter-departmental consultations (adviesaanvragen).
 * Consultations are first-class entities linked to parent cases with their
 * own lifecycle, document exchange, and structured responses per Awb 3:5-3:9.
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
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for consultation (adviesaanvraag) management.
 *
 * Handles the full consultation lifecycle: creation with auto-generated numbers,
 * status transitions, advice responses, deadline extensions, and dependency
 * cycle detection per Awb 3:5-3:9.
 */
class ConsultationService
{
    use SearchesObjects;

    /**
     * Valid consultation statuses.
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
     * Valid advice response types.
     */
    private const VALID_RESPONSES = [
        'positief',
        'positief_met_voorwaarden',
        'negatief',
        'niet_van_toepassing',
    ];

    /**
     * Allowed status transitions (from => [allowed-to, ...]).
     */
    private const STATUS_TRANSITIONS = [
        'open'               => ['ontvangen', 'ingetrokken'],
        'ontvangen'          => ['in_behandeling', 'ingetrokken'],
        'in_behandeling'     => ['advies_uitgebracht', 'ingetrokken'],
        'advies_uitgebracht' => ['afgesloten'],
        'afgesloten'         => [],
        'ingetrokken'        => [],
    ];

    /**
     * Constructor.
     *
     * @param SettingsService         $settingsService  Settings service
     * @param LoggerInterface         $logger           Logger
     * @param AdviceDelegationService $adviceDelegation Advice delegation to decidesk (ADR-019)
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly AdviceDelegationService $adviceDelegation,
    ) {
    }//end __construct()

    /**
     * Create a consultation linked to a parent case.
     *
     * Generates a unique consultation number in the format ADV-{year}-{seq}.
     *
     * @param array<string, mixed> $data Consultation data
     *
     * @return array<string, mixed> Created consultation with ID and number
     *
     * @throws \RuntimeException If OpenRegister unavailable, required fields missing, or decidesk fails closed
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     * @spec openspec/specs/remaining-decision-delegation/spec.md
     * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    public function createConsultation(array $data): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new RuntimeException('Consultation schema not configured');
        }

        // Validate required fields.
        if (empty($data['parentZaak']) === true) {
            throw new RuntimeException('parentZaak is required');
        }

        if (empty($data['adviesInstantie']) === true) {
            throw new RuntimeException('adviesInstantie is required');
        }

        if (empty($data['vraagstelling']) === true) {
            throw new RuntimeException('vraagstelling is required');
        }

        if (empty($data['uiterlijkeReactiedatum']) === true) {
            throw new RuntimeException('uiterlijkeReactiedatum is required');
        }

        // Generate unique consultation number.
        $data['consultationNumber'] = $this->generateConsultationNumber(
            objectService: $objectService,
            register: $register,
            schema: $schema,
        );

        // Set defaults.
        $data['status']    = 'open';
        $data['createdAt'] = date('Y-m-d\TH:i:s');

        $consultation = $objectService->saveObject(object: $data, register: $register, schema: $schema);

        if (is_object($consultation) === true) {
            $consultationId = $consultation->getUuid();
        } else {
            $consultationId = ($data['id'] ?? '');
        }

        // REQ-PDRD-001 / REQ-PDRD-002: a consultatie is an advice request that
        // is *decided* in decidesk. Raise a decidesk `advice` Decision and
        // persist its ref. Fail CLOSED — never author the consultation advice
        // outcome locally as a fallback.
        try {
            $decisionRef = $this->adviceDelegation->raiseAdviceDecision(
                subjectSchema: 'consultation',
                subjectId: (string) $consultationId,
                payload: [
                    'subjectRegister'   => $register,
                    'externalReference' => (string) $data['parentZaak'],
                    'subjectLabel'      => (string) $data['consultationNumber'],
                    'question'          => (string) $data['vraagstelling'],
                ],
            );

            if ((string) $consultationId !== '') {
                $objectService->saveObject(
                    object: ['decisionRef' => $decisionRef],
                    register: $register,
                    schema: $schema,
                    uuid: (string) $consultationId,
                );
            }
        } catch (\RuntimeException $e) {
            $this->logger->error(
                'Procest: createConsultation: decidesk advice Decision raise failed — failing closed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            // REQ-PDRD-002: fail closed; surface the error.
            throw new RuntimeException('Decision service unavailable: '.$e->getMessage(), 0, $e);
        }//end try

        $this->logger->info(
            'Consultation created: '.$consultationId
            .' ('.$data['consultationNumber'].') for case '.$data['parentZaak'],
            ['app' => Application::APP_ID],
        );

        return [
            'id'                 => $consultationId,
            'consultationNumber' => $data['consultationNumber'],
            'status'             => 'open',
            'decisionRef'        => $decisionRef,
        ];
    }//end createConsultation()

    /**
     * Get all consultations for a case.
     *
     * @param string $caseId The parent case UUID
     *
     * @return array<int, array<string, mixed>> List of consultations
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
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

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['parentZaak' => $caseId, '_limit' => 100],
        );
    }//end getConsultationsForCase()

    /**
     * Get a single consultation by ID.
     *
     * @param string $consultationId The consultation UUID
     *
     * @return array<string, mixed>|null The consultation data or null if not found
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function getConsultation(string $consultationId): ?array
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

        return $this->findObjectAsArray(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            id: $consultationId,
        );
    }//end getConsultation()

    /**
     * Update consultation status with transition validation.
     *
     * @param string $consultationId The consultation UUID
     * @param string $newStatus      The new status
     *
     * @return array<string, mixed> Updated consultation summary
     *
     * @throws \RuntimeException If invalid status, invalid transition, or OpenRegister unavailable
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function updateStatus(string $consultationId, string $newStatus): array
    {
        if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
            throw new RuntimeException('Invalid status: '.$newStatus);
        }

        // Transition validation against the declared status graph. Only a
        // recognised current status constrains the move; a consultation whose
        // stored status is absent or unknown may be set to any valid status so
        // the graph never wedges an object that predates it.
        $consultation = $this->getConsultation(consultationId: $consultationId);
        $current      = (string) ($consultation['status'] ?? '');
        if (array_key_exists($current, self::STATUS_TRANSITIONS) === true
            && in_array($newStatus, self::STATUS_TRANSITIONS[$current], true) === false
        ) {
            throw new RuntimeException('Invalid status transition: '.$current.' -> '.$newStatus);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        $updateData = ['status' => $newStatus];
        if ($newStatus === 'afgesloten') {
            $updateData['closedAt'] = date('Y-m-d\TH:i:s');
        }

        $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string) $consultationId);

        $this->logger->info(
            'Consultation '.$consultationId.' status updated to '.$newStatus,
            ['app' => Application::APP_ID],
        );

        return [
            'id'     => $consultationId,
            'status' => $newStatus,
        ];
    }//end updateStatus()

    /**
     * Submit advice response to a consultation.
     *
     * @param string               $consultationId The consultation UUID
     * @param array<string, mixed> $response       Response data (advies, toelichting, voorwaarden)
     *
     * @return array<string, mixed> Updated consultation summary
     *
     * @throws \RuntimeException If invalid response type or OpenRegister unavailable
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function submitResponse(string $consultationId, array $response): array
    {
        $advies = $response['advies'] ?? '';
        if (in_array($advies, self::VALID_RESPONSES, true) === false) {
            throw new RuntimeException('Invalid advice type: '.$advies);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        $updateData = [
            'advies'      => $advies,
            'toelichting' => $response['toelichting'] ?? '',
            'adviesDatum' => date('Y-m-d'),
            'status'      => 'advies_uitgebracht',
        ];

        if (isset($response['voorwaarden']) === true) {
            $updateData['voorwaarden'] = $response['voorwaarden'];
        }

        $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string) $consultationId);

        $this->logger->info(
            'Consultation '.$consultationId.' advice submitted: '.$advies,
            ['app' => Application::APP_ID],
        );

        return [
            'id'     => $consultationId,
            'advies' => $advies,
            'status' => 'advies_uitgebracht',
        ];
    }//end submitResponse()

    /**
     * Delete a consultation by ID.
     *
     * @param string $consultationId The consultation UUID
     *
     * @return bool True on success
     *
     * @throws \RuntimeException If OpenRegister is unavailable
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function deleteConsultation(string $consultationId): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new RuntimeException('Consultation schema not configured');
        }

        $objectService->deleteObject(uuid: (string) $consultationId, register: $register, schema: $schema);

        $this->logger->info(
            'Consultation deleted: '.$consultationId,
            ['app' => Application::APP_ID],
        );

        return true;
    }//end deleteConsultation()

    /**
     * Get overdue consultations (past deadline with open/in_behandeling status).
     *
     * @return array<int, array<string, mixed>> List of overdue consultations
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
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

        $openList = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['status' => 'open', '_limit' => 200],
        );

        $inProgressList = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['status' => 'in_behandeling', '_limit' => 200],
        );

        $all     = array_merge($openList, $inProgressList);
        $today   = date('Y-m-d');
        $overdue = [];

        foreach ($all as $consultation) {
            $deadline = $consultation['uiterlijkeReactiedatum'] ?? '';
            if ($deadline !== '' && $deadline < $today) {
                $overdue[] = $consultation;
            }
        }

        return $overdue;
    }//end getOverdueConsultations()

    /**
     * Get mandatory consultations that are blocking case progression.
     *
     * Returns consultations where mandatory=true and status is neither
     * advies_uitgebracht nor afgesloten.
     *
     * @param string $zaakId The parent case UUID
     *
     * @return array<int, array<string, mixed>> Blocking consultations
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function getBlockingConsultations(string $zaakId): array
    {
        $all      = $this->getConsultationsForCase(caseId: $zaakId);
        $blocking = [];

        foreach ($all as $consultation) {
            $isMandatory = ($consultation['mandatory'] ?? false) === true;
            $status      = $consultation['status'] ?? '';

            if ($isMandatory === false) {
                continue;
            }

            if ($status === 'advies_uitgebracht' || $status === 'afgesloten') {
                continue;
            }

            $blocking[] = $consultation;
        }

        return $blocking;
    }//end getBlockingConsultations()

    /**
     * Validate that adding the given dependsOn list would not create a dependency cycle.
     *
     * Uses depth-first traversal to detect cycles. Returns true if a cycle is
     * detected, false if the dependency graph remains acyclic.
     *
     * @param string   $consultationId The consultation being updated
     * @param string[] $dependsOn      The proposed dependency IDs
     *
     * @return bool True if a cycle would be created
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function validateDependencyCycle(string $consultationId, array $dependsOn): bool
    {
        // Quick self-reference check.
        if (in_array($consultationId, $dependsOn, true) === true) {
            return true;
        }

        $visited = [];
        foreach ($dependsOn as $depId) {
            if ($this->hasCycleDfs(
                startId: $consultationId,
                currentId: $depId,
                visited: $visited,
            ) === true
            ) {
                return true;
            }
        }

        return false;
    }//end validateDependencyCycle()

    /**
     * Request a deadline extension for a consultation.
     *
     * Records the extension request timestamp and justification.
     *
     * @param string $consultationId The consultation UUID
     * @param string $justification  The justification for the extension request
     *
     * @return array<string, mixed> Updated consultation summary
     *
     * @throws \RuntimeException If OpenRegister is unavailable
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function requestExtension(string $consultationId, string $justification): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        $updateData = [
            'extensionRequestedAt'   => date('Y-m-d\TH:i:s'),
            'extensionJustification' => $justification,
            'extensionApproved'      => false,
        ];

        $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string) $consultationId);

        $this->logger->info(
            'Extension requested for consultation '.$consultationId,
            ['app' => Application::APP_ID],
        );

        return [
            'id'                     => $consultationId,
            'extensionRequestedAt'   => $updateData['extensionRequestedAt'],
            'extensionJustification' => $justification,
        ];
    }//end requestExtension()

    /**
     * Approve a deadline extension and update the deadline.
     *
     * @param string $consultationId The consultation UUID
     * @param string $newDeadline    The new deadline date (Y-m-d format)
     *
     * @return array<string, mixed> Updated consultation summary
     *
     * @throws \RuntimeException If OpenRegister is unavailable or date format invalid
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function approveExtension(string $consultationId, string $newDeadline): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDeadline) !== 1) {
            throw new RuntimeException('Invalid date format; expected Y-m-d');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        $updateData = [
            'uiterlijkeReactiedatum' => $newDeadline,
            'extensionApproved'      => true,
        ];

        $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string) $consultationId);

        $this->logger->info(
            'Extension approved for consultation '.$consultationId.', new deadline: '.$newDeadline,
            ['app' => Application::APP_ID],
        );

        return [
            'id'                     => $consultationId,
            'uiterlijkeReactiedatum' => $newDeadline,
            'extensionApproved'      => true,
        ];
    }//end approveExtension()

    /**
     * Generate a unique consultation number in ADV-{year}-{seq} format.
     *
     * Queries existing consultations to find the maximum sequence number for
     * the current year, then increments by one.
     *
     * @param object $objectService The OpenRegister object service
     * @param string $register      The register slug
     * @param string $schema        The schema slug
     *
     * @return string Generated consultation number (e.g. ADV-2026-0001)
     */
    private function generateConsultationNumber(
        object $objectService,
        string $register,
        string $schema,
    ): string {
        $year   = (int) date('Y');
        $prefix = 'ADV-'.$year.'-';

        $existing = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: [
                'consultationNumber' => $prefix.'%',
                '_order'             => ['consultationNumber' => 'DESC'],
                '_limit'             => 1,
            ],
        );

        $maxSeq = 0;
        if (empty($existing) === false) {
            $latest = $existing[0];
            $number = $latest['consultationNumber'] ?? '';
            if (str_starts_with($number, $prefix) === true) {
                $seqPart = substr($number, strlen($prefix));
                $seq     = (int) $seqPart;
                if ($seq > $maxSeq) {
                    $maxSeq = $seq;
                }
            }
        }

        return $prefix.str_pad((string) ($maxSeq + 1), 4, '0', STR_PAD_LEFT);
    }//end generateConsultationNumber()

    /**
     * Depth-first search helper for cycle detection in dependency graph.
     *
     * @param string   $startId   The original consultation ID (cycle target)
     * @param string   $currentId The current node being visited
     * @param string[] $visited   Already-visited node IDs (prevents re-traversal)
     *
     * @return bool True if startId is reachable from currentId (cycle detected)
     */
    private function hasCycleDfs(string $startId, string $currentId, array &$visited): bool
    {
        if ($currentId === $startId) {
            return true;
        }

        if (in_array($currentId, $visited, true) === true) {
            return false;
        }

        $visited[] = $currentId;

        $consultation = $this->getConsultation(consultationId: $currentId);
        if ($consultation === null) {
            return false;
        }

        $deps = $consultation['dependsOn'] ?? [];
        if (is_array($deps) === false) {
            return false;
        }

        foreach ($deps as $depId) {
            if ($this->hasCycleDfs(startId: $startId, currentId: $depId, visited: $visited) === true) {
                return true;
            }
        }

        return false;
    }//end hasCycleDfs()

    /**
     * Find a consultation by its secure token (for external body public access).
     *
     * Returns null when the token is invalid, the consultation is not found,
     * or the consultation is in a terminal status (afgesloten / ingetrokken).
     *
     * @param string $token The 64-character hex secure token
     *
     * @return array<string, mixed>|null Consultation data or null
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
     */
    public function findBySecureToken(string $token): ?array
    {
        if (strlen($token) < 32) {
            return null;
        }

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
            $results = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['secureToken' => $token, '_limit' => 1],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to find consultation by token: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return null;
        }

        if (empty($results) === true) {
            return null;
        }

        $consultation = $results[0];
        $status       = $consultation['status'] ?? '';

        if ($status === 'afgesloten' || $status === 'ingetrokken') {
            return null;
        }

        return $consultation;
    }//end findBySecureToken()
}//end class
