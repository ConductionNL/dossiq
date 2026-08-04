<?php

/**
 * Procest Bezwaar Advisory Committee Service.
 *
 * Domain service for the bezwaaradviescommissie (BAC) capability under
 * Awb Art. 7:13. Owns the legitimate domain operations that cannot be
 * handled by the manifest-driven CRUD path:
 *
 *  - assignToCommittee()          — referral with independence check
 *                                   (Awb Art. 7:13(3))
 *  - transitionAdviceStatus()     — one-way lifecycle transitions
 *                                   (assigned → in-deliberation → advice-issued)
 *  - autoAssignDefaultCommittee() — listener entry point used when a bezwaar
 *                                   case enters status "Advies aangevraagd"
 *
 * Per the per-app convention every mutation goes through OpenRegister via
 * the manifest renderer; this service composes those calls and writes the
 * append-only `auditTrail` entries that satisfy Archiefwet 1995.
 *
 * Identity is ALWAYS derived from `IUserSession`. Static error messages
 * only — exception details never bubble to controllers.
 *
 * @category Service
 * @package  OCA\Procest\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\Service\AdviceDelegationService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\StatusTransitionService;
use OCA\Procest\Service\Transitions\GuardFailedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BAC service: committee assignment + advice request lifecycle.
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */
class AdvisoryCommitteeService
{

    /**
     * Allowed advice-request lifecycle states.
     */
    private const VALID_STATUSES = [
        'assigned',
        'in-deliberation',
        'advice-issued',
        'niet-ontvankelijk',
    ];

    /**
     * Allowed one-way forward transitions (source => [allowed targets]).
     * niet-ontvankelijk is a terminal advice the committee MAY issue from
     * in-deliberation per Awb Art. 7:13(7).
     */
    private const ALLOWED_TRANSITIONS = [
        'assigned'          => ['in-deliberation'],
        'in-deliberation'   => ['advice-issued', 'niet-ontvankelijk'],
        'advice-issued'     => [],
        'niet-ontvankelijk' => [],
    ];

    /**
     * Required structured-advice fields when transitioning to advice-issued.
     */
    private const REQUIRED_ADVICE_FIELDS = [
        'conclusion',
        'recommendation',
    ];

    /**
     * Default deadline in days for advice delivery (12 weeks per Awb 7:24(1)).
     */
    private const DEFAULT_DEADLINE_DAYS = 84;

    /**
     * Constructor.
     *
     * @param SettingsService          $settingsService  Schema/register bridge
     * @param StatusTransitionService  $transitions      Optional integration with
     *                                                   the case-level status FSM
     *                                                   (used when the lifecycle
     *                                                   advances the parent case)
     * @param LoggerInterface          $logger           Logger
     * @param AdviceDelegationService  $adviceDelegation Advice delegation to decidesk (ADR-019)
     * @param BezwaarAuditTrail        $auditTrail       Shared append-only audit writer
     * @param PanelIndependenceChecker $independence     Awb Art. 7:13 lid 3 panel check
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly StatusTransitionService $transitions,
        private readonly LoggerInterface $logger,
        private readonly AdviceDelegationService $adviceDelegation,
        private readonly BezwaarAuditTrail $auditTrail,
        private readonly PanelIndependenceChecker $independence,
    ) {
    }//end __construct()

    /**
     * Assign a bezwaar case to a committee, creating a bacAdviceRequest in
     * state `assigned`. The independence check (Awb Art. 7:13(3)) is
     * deferred until the advance-to-in-deliberation transition because a
     * committee MAY be valid for one bezwaar and invalid for another.
     *
     * @param string               $bezwaarId   UUID of the bezwaar (lifecycle)
     * @param string               $commissieId UUID of the committee
     * @param array<string, mixed> $payload     Optional extra fields
     *                                          (panel, deadline, etc.)
     *
     * @return array<string, mixed> The created bacAdviceRequest record
     *
     * @throws RuntimeException When OpenRegister unavailable or refs invalid

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function assignToCommittee(
        string $bezwaarId,
        string $commissieId,
        array $payload=[]
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register        = $this->settingsService->getConfigValue(key: 'register');
        $requestSchema   = $this->settingsService->getConfigValue(
            key: 'bac_advice_request_schema'
        );
        $committeeSchema = $this->settingsService->getConfigValue(
            key: 'bezwaaradviescommissie_schema'
        );

        $required = [$register, $requestSchema, $committeeSchema];
        if (in_array('', $required, true) === true) {
            throw new RuntimeException(
                'BAC schemas are not configured'
            );
        }

        // Validate committee exists and is active.
        $committee = $objectService->find($commissieId, register: $register, schema: $committeeSchema);
        if (is_array($committee) === false) {
            throw new RuntimeException('Committee not found');
        }

        $active = $committee['active'] ?? true;
        if ($active === false) {
            throw new RuntimeException(
                'Committee is archived and cannot accept new bezwaaren'
            );
        }

        $now      = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $deadline = (new DateTimeImmutable())
            ->modify('+'.self::DEFAULT_DEADLINE_DAYS.' days')
            ->format('Y-m-d');

        $record = array_merge(
            [
                'panel'    => [],
                'deadline' => $deadline,
            ],
            $payload,
            [
                'bezwaar'    => $bezwaarId,
                'commissie'  => $commissieId,
                'status'     => 'assigned',
                'assignedAt' => $now,
            ]
        );

        // Append audit entry for panel composition.
        $record['auditTrail'] = $this->auditTrail->append(
            existing: [],
            event: 'panel-member-added',
            payload: [
                'panel'       => $record['panel'],
                'commissieId' => $commissieId,
                'bezwaar'     => $bezwaarId,
            ],
        );

        try {
            return $objectService->saveObject(object: $record, register: $register, schema: $requestSchema);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest BAC: failed to create advice request: '.$e->getMessage()
            );
            throw new RuntimeException('Could not create advice request');
        }
    }//end assignToCommittee()

    /**
     * Advance the advice request to a new status. Enforces the one-way
     * lifecycle (REQ-BAC-3), the independence check (REQ-BAC-2) and the
     * advice content contract (REQ-BAC-4).
     *
     * @param string               $requestId Advice request UUID
     * @param string               $newStatus Target status
     * @param array<string, mixed> $payload   Optional patch (advice text,
     *                                        signatureEvidence, conclusion, ...)
     *
     * @return array<string, mixed> The updated advice request record
     *
     * @throws RuntimeException     When the transition is forbidden
     * @throws GuardFailedException When the independence check fails

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     * @spec openspec/specs/remaining-decision-delegation/spec.md
     * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-procest
     */
    public function transitionAdviceStatus(
        string $requestId,
        string $newStatus,
        array $payload=[]
    ): array {
        if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
            throw new RuntimeException('Invalid BAC advice status');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register      = $this->settingsService->getConfigValue(key: 'register');
        $requestSchema = $this->settingsService->getConfigValue(
            key: 'bac_advice_request_schema'
        );

        $current = $objectService->find($requestId, register: $register, schema: $requestSchema);
        if (is_array($current) === false) {
            throw new RuntimeException('Advice request not found');
        }

        $from = (string) ($current['status'] ?? 'assigned');
        $this->assertTransitionAllowed(from: $from, newStatus: $newStatus);

        // Guard: assigned → in-deliberation requires panel and
        // independence (REQ-BAC-2).
        if ($from === 'assigned' && $newStatus === 'in-deliberation') {
            $this->guardDeliberationStart(
                objectService: $objectService,
                current: $current,
                requestId: $requestId,
                register: $register,
                requestSchema: $requestSchema,
            );
        }

        // Guard: in-deliberation → advice-issued requires the structured
        // advice content (REQ-BAC-4).
        $adviceDecisionRef = '';
        if ($from === 'in-deliberation' && $newStatus === 'advice-issued') {
            $adviceDecisionRef = $this->issueAdviceDecision(
                current: $current,
                payload: $payload,
                requestId: $requestId,
                register: $register,
            );
        }

        $userId = $this->auditTrail->resolveActor();

        // Compose the update.
        $update = $this->buildTransitionUpdate(
            payload: $payload,
            current: $current,
            newStatus: $newStatus,
            adviceDecisionRef: $adviceDecisionRef,
            userId: $userId,
        );

        try {
            return $objectService->saveObject(
                object: $update,
                register: $register,
                schema: $requestSchema,
                uuid: (string) $requestId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest BAC: failed to transition advice request '
                .$requestId.': '.$e->getMessage()
            );
            throw new RuntimeException('Could not transition advice request');
        }
    }//end transitionAdviceStatus()

    /**
     * Listener entry-point: when a bezwaar enters status
     * "Hoorzitting gepland", auto-assign the default committee for the
     * bezwaar's jurisdiction.
     *
     * @param string $bezwaarId The bezwaar (lifecycle) UUID
     *
     * @return array<string, mixed>|null The created advice request, or
     *                                    null when no default committee
     *                                    is configured.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function autoAssignDefaultCommittee(string $bezwaarId): ?array
    {
        $defaultId = $this->settingsService->getConfigValue(
            key: 'bac_default_committee'
        );
        if ($defaultId === '') {
            $this->logger->info(
                'Procest BAC: no default committee configured; '
                .'skipping auto-assignment for bezwaar '.$bezwaarId
            );
            return null;
        }

        try {
            return $this->assignToCommittee(
                bezwaarId: $bezwaarId,
                commissieId: $defaultId,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest BAC: auto-assignment failed for bezwaar '
                .$bezwaarId.': '.$e->getMessage()
            );
            return null;
        }
    }//end autoAssignDefaultCommittee()

    /**
     * Record a council-deviation event on the linked advice request after
     * the parent bezwaar-lifecycle finalises a besluit op bezwaar with
     * `motivatieAfwijkingAdvies` set (REQ-BAC-5).
     *
     * @param string $requestId    Advice request UUID
     * @param string $besluitId    Besluit op bezwaar UUID
     * @param string $motivatieRef Reference / hash of the motivation
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function recordCouncilDeviation(
        string $requestId,
        string $besluitId,
        string $motivatieRef
    ): void {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register      = $this->settingsService->getConfigValue(key: 'register');
        $requestSchema = $this->settingsService->getConfigValue(
            key: 'bac_advice_request_schema'
        );

        try {
            $current = $objectService->find($requestId, register: $register, schema: $requestSchema);
            if (is_array($current) === false) {
                return;
            }

            $audit = $this->auditTrail->append(
                existing: (array) ($current['auditTrail'] ?? []),
                event: 'council-deviation-recorded',
                payload: [
                    'besluit'   => $besluitId,
                    'motivatie' => $motivatieRef,
                ],
            );

            $objectService->saveObject(
                object: ['auditTrail' => $audit],
                register: $register,
                schema: $requestSchema,
                uuid: (string) $requestId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest BAC: failed to record council deviation: '
                .$e->getMessage()
            );
        }//end try
    }//end recordCouncilDeviation()

    /**
     * Assert that the requested advice-status transition is permitted by the
     * one-way lifecycle (REQ-BAC-3).
     *
     * @param string $from      Current advice-request status
     * @param string $newStatus Requested target status
     *
     * @return void
     *
     * @throws RuntimeException When the transition is not permitted
     */
    private function assertTransitionAllowed(string $from, string $newStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        if (in_array($newStatus, $allowed, true) === false) {
            throw new RuntimeException(
                'Transition from '.$from.' to '.$newStatus.' is not permitted'
            );
        }
    }//end assertTransitionAllowed()

    /**
     * Guard the assigned → in-deliberation transition (REQ-BAC-2): a panel
     * must be set and every member must be independent. An independence
     * failure is appended to the audit trail before the guard raises.
     *
     * @param object               $objectService OpenRegister object service
     * @param array<string, mixed> $current       Current advice-request record
     * @param string               $requestId     Advice request UUID
     * @param string               $register      Register identifier
     * @param string               $requestSchema Advice-request schema identifier
     *
     * @return void
     *
     * @throws RuntimeException     When no panel has been set
     * @throws GuardFailedException When a panel member is not independent
     */
    private function guardDeliberationStart(
        object $objectService,
        array $current,
        string $requestId,
        string $register,
        string $requestSchema
    ): void {
        $panel = (array) ($current['panel'] ?? []);
        if ($panel === []) {
            throw new RuntimeException(
                'Panel must be set before deliberation can start'
            );
        }

        $independence = $this->independence->check(
            bezwaarId: (string) ($current['bezwaar'] ?? ''),
            panel: $panel,
        );

        if ($independence['ok'] !== false) {
            return;
        }

        // Persist the failure to the audit trail before raising.
        $audit = $this->auditTrail->append(
            existing: (array) ($current['auditTrail'] ?? []),
            event: 'independence-check-failed',
            payload: [
                'conflictingMember' => $independence['member'],
                'reason'            => $independence['reason'],
            ],
        );

        try {
            $objectService->saveObject(
                object: ['auditTrail' => $audit],
                register: $register,
                schema: $requestSchema,
                uuid: (string) $requestId
            );
        } catch (\Throwable $auditError) {
            $this->logger->error(
                'Procest BAC: failed to write audit on '
                .'independence failure: '
                .$auditError->getMessage()
            );
        }

        throw new GuardFailedException(
            failedGuards: [],
            message: 'Panel member conflict (Awb Art. 7:13 lid 3): '
            .$independence['reason']
        );
    }//end guardDeliberationStart()

    /**
     * Validate the structured advice content (REQ-BAC-4) and raise the
     * decidesk `advice` Decision (REQ-PDRD-001 / REQ-PDRD-002).
     *
     * The BAC advice is *made* in decidesk; this fails CLOSED and never
     * authors the advice outcome locally as a fallback.
     *
     * @param array<string, mixed> $current   Current advice-request record
     * @param array<string, mixed> $payload   Caller-supplied patch
     * @param string               $requestId Advice request UUID
     * @param string               $register  Register identifier
     *
     * @return string The decidesk Decision reference
     *
     * @throws RuntimeException When a required advice field is missing or the
     *                          decision service is unavailable
     */
    private function issueAdviceDecision(
        array $current,
        array $payload,
        string $requestId,
        string $register
    ): string {
        $merged = array_merge($current, $payload);
        foreach (self::REQUIRED_ADVICE_FIELDS as $field) {
            $value = $merged[$field] ?? null;
            if (in_array($value, [null, '', []], true) === true) {
                throw new RuntimeException(
                    'Advice cannot be issued: missing required field '
                    .$field
                );
            }
        }

        try {
            return $this->adviceDelegation->raiseAdviceDecision(
                subjectSchema: 'bacAdviceRequest',
                subjectId: (string) $requestId,
                payload: [
                    'subjectRegister'   => $register,
                    'externalReference' => (string) ($current['bezwaar'] ?? $requestId),
                    'subjectLabel'      => (string) ($merged['conclusion'] ?? 'BAC-advies'),
                    'adviceType'        => (string) ($merged['recommendation'] ?? ''),
                    'question'          => (string) ($merged['conclusion'] ?? ''),
                ],
            );
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Procest BAC: decidesk advice Decision raise failed — failing closed: '
                .$e->getMessage()
            );
            throw new RuntimeException('Decision service unavailable: '.$e->getMessage(), 0, $e);
        }//end try
    }//end issueAdviceDecision()

    /**
     * Compose the advice-request patch for a lifecycle transition, stamping
     * the issue timestamp and chair-signature audit entry on terminal states.
     *
     * @param array<string, mixed> $payload           Caller-supplied patch
     * @param array<string, mixed> $current           Current advice-request record
     * @param string               $newStatus         Target status
     * @param string               $adviceDecisionRef decidesk Decision reference, or ''
     * @param string               $userId            Acting user UID
     *
     * @return array<string, mixed> The patch to persist
     */
    private function buildTransitionUpdate(
        array $payload,
        array $current,
        string $newStatus,
        string $adviceDecisionRef,
        string $userId
    ): array {
        $update           = $payload;
        $update['status'] = $newStatus;
        if ($adviceDecisionRef !== '') {
            $update['decisionRef'] = $adviceDecisionRef;
        }

        $terminal = ['advice-issued', 'niet-ontvankelijk'];
        if (in_array($newStatus, $terminal, true) === false) {
            return $update;
        }

        $update['adviceIssuedAt'] = (new DateTimeImmutable())
            ->format(DateTimeInterface::ATOM);
        $update['auditTrail']     = $this->auditTrail->append(
            existing: (array) ($current['auditTrail'] ?? []),
            event: 'advice-signed-by-chair',
            payload: [
                'chair'             => $userId,
                'signatureEvidence' => $update['signatureEvidence'] ?? ($current['signatureEvidence'] ?? null),
                'conclusion'        => $update['conclusion'] ?? ($current['conclusion'] ?? null),
            ],
        );

        return $update;
    }//end buildTransitionUpdate()
}//end class
