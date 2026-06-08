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
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\StatusTransitionService;
use OCA\Procest\Service\Transitions\GuardFailedException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BAC service: committee assignment + advice request lifecycle.
 *
 * @spec openspec/changes/bezwaar-advisory-committee/specs/bezwaar-advisory-committee/spec.md
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
     * @param SettingsService         $settingsService Schema/register bridge
     * @param IUserSession            $userSession     Acting identity source
     * @param StatusTransitionService $transitions     Optional integration with
     *                                                 the case-level status FSM
     *                                                 (used when the lifecycle
     *                                                 advances the parent case)
     * @param LoggerInterface         $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly StatusTransitionService $transitions,
        private readonly LoggerInterface $logger,
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

        if ($register === '' || $requestSchema === '' || $committeeSchema === '') {
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
        $record['auditTrail'] = $this->appendAudit(
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

        $from    = (string) ($current['status'] ?? 'assigned');
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        if (in_array($newStatus, $allowed, true) === false) {
            throw new RuntimeException(
                'Transition from '.$from.' to '.$newStatus.' is not permitted'
            );
        }

        // Guard: assigned → in-deliberation requires panel and
        // independence (REQ-BAC-2).
        if ($from === 'assigned' && $newStatus === 'in-deliberation') {
            $panel = (array) ($current['panel'] ?? []);
            if ($panel === []) {
                throw new RuntimeException(
                    'Panel must be set before deliberation can start'
                );
            }

            $independence = $this->checkPanelIndependence(
                bezwaarId: (string) ($current['bezwaar'] ?? ''),
                panel: $panel,
            );

            if ($independence['ok'] === false) {
                // Persist the failure to the audit trail before raising.
                $audit = $this->appendAudit(
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
            }//end if
        }//end if

        // Guard: in-deliberation → advice-issued requires the structured
        // advice content (REQ-BAC-4).
        if ($from === 'in-deliberation' && $newStatus === 'advice-issued') {
            $merged = array_merge($current, $payload);
            foreach (self::REQUIRED_ADVICE_FIELDS as $field) {
                $value = $merged[$field] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    throw new RuntimeException(
                        'Advice cannot be issued: missing required field '
                        .$field
                    );
                }
            }
        }

        $userId = $this->resolveUserId();

        // Compose the update.
        $update           = $payload;
        $update['status'] = $newStatus;

        if ($newStatus === 'advice-issued' || $newStatus === 'niet-ontvankelijk') {
            $update['adviceIssuedAt'] = (new DateTimeImmutable())
                ->format(DateTimeInterface::ATOM);
            $auditEvent           = 'advice-signed-by-chair';
            $auditPayload         = [
                'chair'             => $userId,
                'signatureEvidence' => $update['signatureEvidence'] ?? ($current['signatureEvidence'] ?? null),
                'conclusion'        => $update['conclusion'] ?? ($current['conclusion'] ?? null),
            ];
            $update['auditTrail'] = $this->appendAudit(
                existing: (array) ($current['auditTrail'] ?? []),
                event: $auditEvent,
                payload: $auditPayload,
            );
        }

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

            $audit = $this->appendAudit(
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
     * Member-independence check per Awb Art. 7:13(3).
     *
     * Compares each panel member UID against the `createdBy` (steller) of
     * the contested primair besluit. Resolution chain:
     *   bacAdviceRequest.bezwaar → bezwaar (lifecycle record) → bezwaar.case
     *   (procest case) → objection (filed on that case) →
     *   objection.contestedDecision → decision.createdBy (steller).
     *
     * @param string        $bezwaarId The bezwaar (lifecycle) UUID
     * @param array<string> $panel     Panel member UIDs
     *
     * @return array{ok: bool, member: ?string, reason: ?string}
     */
    private function checkPanelIndependence(
        string $bezwaarId,
        array $panel
    ): array {
        if ($bezwaarId === '' || $panel === []) {
            return ['ok' => true, 'member' => null, 'reason' => null];
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['ok' => true, 'member' => null, 'reason' => null];
        }

        $register        = $this->settingsService->getConfigValue(key: 'register');
        $bezwaarSchema   = $this->settingsService->getConfigValue(
            key: 'bezwaar_schema'
        );
        $objectionSchema = $this->settingsService->getConfigValue(
            key: 'objection_schema'
        );
        $decisionSchema  = $this->settingsService->getConfigValue(
            key: 'decision_schema'
        );

        if ($objectionSchema === '' || $decisionSchema === '') {
            // Unable to resolve; do not block the transition, but log.
            $this->logger->info(
                'Procest BAC: objection/decision schemas not configured; '
                .'skipping independence check'
            );
            return ['ok' => true, 'member' => null, 'reason' => null];
        }

        try {
            // Resolve the underlying procest case via the bezwaar entity
            // when the bezwaar_schema is registered. When unavailable
            // (e.g. legacy callers passing a case UUID directly), fall back
            // to treating the input as the case id.
            $caseId = $bezwaarId;
            if ($bezwaarSchema !== '') {
                $bezwaar = $objectService->find($bezwaarId, register: $register, schema: $bezwaarSchema);
                if (is_array($bezwaar) === true) {
                    $caseId = (string) ($bezwaar['case'] ?? $bezwaarId);
                }
            }

            $objections = $objectService->findObjects(
                $register,
                $objectionSchema,
                ['case' => $caseId]
            );
            $objection  = null;
            if (is_array($objections) === true && $objections !== []) {
                $objection = $objections[0];
            }

            if (is_array($objection) === false) {
                return ['ok' => true, 'member' => null, 'reason' => null];
            }

            $contestedId = (string) ($objection['contestedDecision'] ?? '');
            if ($contestedId === '') {
                return ['ok' => true, 'member' => null, 'reason' => null];
            }

            $decision = $objectService->find($contestedId, register: $register, schema: $decisionSchema);
            if (is_array($decision) === false) {
                return ['ok' => true, 'member' => null, 'reason' => null];
            }

            $steller = (string) (
                $decision['@self']['owner'] ?? ($decision['createdBy'] ?? ($decision['steller'] ?? ''))
            );
            if ($steller === '') {
                return ['ok' => true, 'member' => null, 'reason' => null];
            }

            foreach ($panel as $memberUid) {
                if ((string) $memberUid === $steller) {
                    return [
                        'ok'     => false,
                        'member' => (string) $memberUid,
                        'reason' => 'Lid was betrokken bij het bestreden '
                                    .'besluit (Awb Art. 7:13 lid 3)',
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest BAC: independence check error: '.$e->getMessage()
            );
            // Fail-open here is intentional: do not block on infra issues.
        }//end try

        return ['ok' => true, 'member' => null, 'reason' => null];
    }//end checkPanelIndependence()

    /**
     * Append an entry to the bac_audit_trail array.
     *
     * @param array<int, array<string, mixed>> $existing Current audit entries
     * @param string                           $event    Event slug
     * @param array<string, mixed>             $payload  Structured payload
     *
     * @return array<int, array<string, mixed>>
     */
    private function appendAudit(
        array $existing,
        string $event,
        array $payload
    ): array {
        $entry = [
            'event'   => $event,
            'actor'   => $this->resolveUserId(),
            'at'      => (new DateTimeImmutable())
                ->format(DateTimeInterface::ATOM),
            'payload' => $payload,
        ];

        $existing[] = $entry;
        return $existing;
    }//end appendAudit()

    /**
     * Resolve the acting user UID from IUserSession.
     *
     * @return string
     */
    private function resolveUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'system';
        }

        return $user->getUID();
    }//end resolveUserId()
}//end class
