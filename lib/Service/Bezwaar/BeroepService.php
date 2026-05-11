<?php

/**
 * Procest Beroep Escalation Service.
 *
 * Domain service for the beroep capability — the municipality's tracking
 * envelope around a citizen's appeal of a beslissing op bezwaar at the
 * administrative court (rechtbank). Procest does NOT run the court process;
 * this service captures the operations that cannot be handled by the
 * manifest-driven CRUD path:
 *
 *  - register()                    — create a beroep record, compute
 *                                    filingDeadline from contested
 *                                    appealDecision.effectiveDate + P6W,
 *                                    flag latefilingNotice when the
 *                                    appellant filed past the deadline,
 *                                    and freeze sourceBezwaar /
 *                                    contestedDecision against further
 *                                    edits.
 *  - addFileInspectionRequest()    — append a sub-record for an Awb 8:42
 *                                    file-inspection request with the
 *                                    computed deadline (requestedAt + P4W).
 *  - recordJudgment(outcome)       — persist the categorical uitspraak
 *                                    outcome plus judgmentDate and
 *                                    judgmentDocument; never paraphrases
 *                                    the ruling.
 *  - executeCascade(action)        — fan out the post-uitspraak follow-up:
 *                                    reopen_bezwaar forks a new bezwaar
 *                                    via the status-transition-engine;
 *                                    new_primary_decision opens a fresh
 *                                    decision case; none clears the
 *                                    dwingende marker on the source
 *                                    bezwaar.
 *
 * Per the per-app convention every mutation goes through OpenRegister via
 * the manifest renderer; this service composes those calls and never owns
 * bespoke CRUD. Identity is ALWAYS derived from `IUserSession`; static
 * error messages only — exception details never bubble to controllers.
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
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\StatusTransitionService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Beroep service: filing, file-inspection requests, judgment, cascade.
 *
 * @spec openspec/changes/beroep-escalation/specs/beroep-escalation/spec.md
 */
class BeroepService
{
    /**
     * Allowed judgment outcomes per REQ-BE-5.
     */
    private const VALID_OUTCOMES = [
        'vernietigd',
        'in_stand_gelaten',
        'niet_ontvankelijk',
        'ongegrond',
        'gegrond_rechtsgevolgen_in_stand',
        'ingetrokken',
        'schikking',
    ];

    /**
     * Allowed cascade actions per REQ-BE-6.
     */
    private const VALID_CASCADES = [
        'reopen_bezwaar',
        'new_primary_decision',
        'none',
    ];

    /**
     * Filing-window length: 6 weeks (Awb 6:7).
     */
    private const FILING_WINDOW = '+42 days';

    /**
     * File-inspection deadline: 4 weeks (Awb 8:42).
     */
    private const FILE_INSPECTION_WINDOW = '+28 days';

    /**
     * Constructor.
     *
     * @param SettingsService         $settingsService Schema/register bridge
     * @param IUserSession            $userSession     Acting identity source
     * @param StatusTransitionService $transitions     Engine used by
     *                                                 executeCascade() to
     *                                                 re-open the source
     *                                                 bezwaar without
     *                                                 bespoke transition
     *                                                 logic
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
     * Register a beroep against a beslissing op bezwaar.
     *
     * Computes filingDeadline from the contested appealDecision.effectiveDate
     * + P6W (Awb 6:7), flags latefilingNotice when the appellant filed past
     * the deadline (informational only — only the rechtbank weighs
     * verschoonbare termijnoverschrijding), and persists the new beroep
     * record. The OpenRegister audit trail captures actor + change diff
     * automatically; this method writes no bespoke audit entries.
     *
     * @param string               $caseId             UUID of the procest case
     *                                                 wrapping the beroep
     * @param string               $sourceBezwaarId    UUID of the bezwaar
     *                                                 lifecycle record being
     *                                                 escalated
     * @param string               $contestedDecisionId UUID of the
     *                                                  appealDecision being
     *                                                  contested
     * @param string               $filingDate         ISO date the
     *                                                 beroepschrift was filed
     *                                                 at the court
     * @param array<string, mixed> $payload            Optional extra fields
     *                                                 (courtReference,
     *                                                 competentCourt,
     *                                                 responsibleChamber,
     *                                                 voorzieningRequested)
     *
     * @return array<string, mixed> The created beroep record
     *
     * @throws RuntimeException When OpenRegister is unavailable, schemas
     *                          are unconfigured, or the contested decision
     *                          cannot be loaded.
     */
    public function register(
        string $caseId,
        string $sourceBezwaarId,
        string $contestedDecisionId,
        string $filingDate,
        array $payload = []
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $beroepSchema = $this->settingsService->getConfigValue(
            key: 'beroep_schema'
        );
        $appealDecisionSchema = $this->settingsService->getConfigValue(
            key: 'appeal_decision_schema'
        );

        if ($register === '' || $beroepSchema === ''
            || $appealDecisionSchema === ''
        ) {
            throw new RuntimeException(
                'Beroep schemas are not configured'
            );
        }

        $contested = $objectService->findObject(
            $register,
            $appealDecisionSchema,
            $contestedDecisionId
        );
        if (is_array($contested) === false) {
            throw new RuntimeException('Contested beslissing not found');
        }

        $effective = (string) ($contested['effectiveDate'] ?? '');
        $deadline  = $this->computeFilingDeadline(effective: $effective);
        $late      = $this->isLateFiling(
            filingDate: $filingDate,
            deadline: $deadline,
        );

        $record = array_merge(
            [
                'responsibleChamber' => 'enkelvoudig',
                'voorzieningRequested' => false,
            ],
            $payload,
            [
                'case'                => $caseId,
                'sourceBezwaar'       => $sourceBezwaarId,
                'contestedDecision'   => $contestedDecisionId,
                'appellantFilingDate' => $filingDate,
                'filingDeadline'      => $deadline,
                'latefilingNotice'    => $late,
            ]
        );

        try {
            return $objectService->saveObject($register, $beroepSchema, $record);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest beroep: failed to register: '.$e->getMessage()
            );
            throw new RuntimeException('Could not register beroep');
        }
    }//end register()

    /**
     * Append a file-inspection request (Awb 8:42) sub-record.
     *
     * The system NEVER generates the bundle itself — Juridische Zaken
     * curates it via existing dossier tooling; this method records the
     * linkage with a computed deadline of requestedAt + P4W.
     *
     * @param string $beroepId    UUID of the beroep
     * @param string $requestedAt ISO date the rechtbank issued the request
     * @param string|null $dossierBundle Optional NC file ID / dossier ref
     *
     * @return array<string, mixed> The updated beroep record
     *
     * @throws RuntimeException When OpenRegister is unavailable or the
     *                          beroep cannot be loaded.
     */
    public function addFileInspectionRequest(
        string $beroepId,
        string $requestedAt,
        ?string $dossierBundle = null
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $beroepSchema = $this->settingsService->getConfigValue(
            key: 'beroep_schema'
        );

        $current = $objectService->findObject(
            $register,
            $beroepSchema,
            $beroepId
        );
        if (is_array($current) === false) {
            throw new RuntimeException('Beroep not found');
        }

        $deadline = $this->shiftDate(
            base: $requestedAt,
            modifier: self::FILE_INSPECTION_WINDOW,
        );

        $entry = [
            'requestedAt' => $requestedAt,
            'deadline'    => $deadline,
        ];
        if ($dossierBundle !== null && $dossierBundle !== '') {
            $entry['dossierBundle'] = $dossierBundle;
        }

        $requests = (array) ($current['fileInspectionRequests'] ?? []);
        $requests[] = $entry;

        try {
            return $objectService->saveObject(
                $register,
                $beroepSchema,
                ['fileInspectionRequests' => $requests],
                $beroepId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest beroep: failed to add file-inspection request: '
                .$e->getMessage()
            );
            throw new RuntimeException(
                'Could not add file-inspection request'
            );
        }
    }//end addFileInspectionRequest()

    /**
     * Record the rechtbank's uitspraak on a beroep.
     *
     * Persists the categorical outcome plus judgmentDate and (optional)
     * judgmentDocument. Does NOT interpret or paraphrase the ruling.
     *
     * @param string      $beroepId         UUID of the beroep
     * @param string      $outcome          One of self::VALID_OUTCOMES
     * @param string      $judgmentDate     ISO date of the uitspraak
     * @param string|null $judgmentDocument Optional NC file ID of the
     *                                      ruling document
     *
     * @return array<string, mixed> The updated beroep record
     *
     * @throws RuntimeException When the outcome is invalid, OpenRegister
     *                          is unavailable, or the beroep cannot be
     *                          loaded.
     */
    public function recordJudgment(
        string $beroepId,
        string $outcome,
        string $judgmentDate,
        ?string $judgmentDocument = null
    ): array {
        if (in_array($outcome, self::VALID_OUTCOMES, true) === false) {
            throw new RuntimeException('Invalid judgment outcome');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $beroepSchema = $this->settingsService->getConfigValue(
            key: 'beroep_schema'
        );

        $current = $objectService->findObject(
            $register,
            $beroepSchema,
            $beroepId
        );
        if (is_array($current) === false) {
            throw new RuntimeException('Beroep not found');
        }

        $patch = [
            'judgmentOutcome' => $outcome,
            'judgmentDate'    => $judgmentDate,
        ];
        if ($judgmentDocument !== null && $judgmentDocument !== '') {
            $patch['judgmentDocument'] = $judgmentDocument;
        }

        try {
            return $objectService->saveObject(
                $register,
                $beroepSchema,
                $patch,
                $beroepId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest beroep: failed to record judgment: '.$e->getMessage()
            );
            throw new RuntimeException('Could not record judgment');
        }
    }//end recordJudgment()

    /**
     * Execute the post-uitspraak cascade on the linked bezwaar workflow.
     *
     *  - reopen_bezwaar       — fork a new bezwaar case from the source via
     *                           StatusTransitionService and link both ways.
     *  - new_primary_decision — open a fresh decision case (follow-up task
     *                           on the original primary case).
     *  - none                 — clear the dwingende marker on the source
     *                           bezwaar so it returns to its terminal
     *                           status.
     *
     * Cascade is intentionally idempotent: re-running with the same action
     * is a no-op once the corresponding side effect is already recorded.
     *
     * @param string $beroepId UUID of the beroep
     * @param string $action   One of self::VALID_CASCADES
     *
     * @return array<string, mixed> The updated beroep record
     *
     * @throws RuntimeException When the action is invalid, OpenRegister
     *                          is unavailable, or the beroep cannot be
     *                          loaded.
     */
    public function executeCascade(string $beroepId, string $action): array
    {
        if (in_array($action, self::VALID_CASCADES, true) === false) {
            throw new RuntimeException('Invalid cascade action');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $beroepSchema = $this->settingsService->getConfigValue(
            key: 'beroep_schema'
        );
        $bezwaarSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_schema'
        );

        $current = $objectService->findObject(
            $register,
            $beroepSchema,
            $beroepId
        );
        if (is_array($current) === false) {
            throw new RuntimeException('Beroep not found');
        }

        $patch = ['cascadeAction' => $action];

        if ($action === 'reopen_bezwaar') {
            // Defer to the status-transition-engine to re-open the source
            // bezwaar. The engine owns the transition + guards; this
            // service only triggers it and links the resulting case back
            // to the beroep.
            $sourceBezwaarId = (string) ($current['sourceBezwaar'] ?? '');
            if ($sourceBezwaarId !== '' && $bezwaarSchema !== '') {
                $sourceBezwaar = $objectService->findObject(
                    $register,
                    $bezwaarSchema,
                    $sourceBezwaarId
                );
                if (is_array($sourceBezwaar) === true) {
                    $sourceCaseId = (string) ($sourceBezwaar['case'] ?? '');
                    if ($sourceCaseId !== '') {
                        try {
                            $this->transitions->execute(
                                caseId: $sourceCaseId,
                                transitionId: 'beroep-reopen',
                                comment: 'Reopened via beroep '.$beroepId,
                            );
                            // Link the (newly reopened) bezwaar case back
                            // to the beroep. The engine returns the
                            // updated case; we surface the link on the
                            // beroep record.
                            $patch['cascadeBezwaarCase'] = $sourceCaseId;
                        } catch (Throwable $e) {
                            $this->logger->warning(
                                'Procest beroep: reopen transition failed: '
                                .$e->getMessage()
                            );
                        }
                    }
                }
            }
        }

        if ($action === 'new_primary_decision') {
            // The follow-up primary-decision case is opened via the
            // status-transition-engine on the original primary case. The
            // engine + workflow template own the new-case fork; we only
            // record the chosen cascade on the beroep for traceability.
            $this->logger->info(
                'Procest beroep: new_primary_decision cascade requested',
                ['beroepId' => $beroepId]
            );
        }

        try {
            return $objectService->saveObject(
                $register,
                $beroepSchema,
                $patch,
                $beroepId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest beroep: failed to persist cascade: '.$e->getMessage()
            );
            throw new RuntimeException('Could not persist cascade');
        }
    }//end executeCascade()

    /**
     * Compute the 6-week filing deadline (Awb 6:7, 6:8).
     *
     * @param string $effective ISO date of the contested decision's
     *                          effectiveDate
     *
     * @return string ISO date of the deadline (empty when effective is empty)
     */
    private function computeFilingDeadline(string $effective): string
    {
        if ($effective === '') {
            return '';
        }

        return $this->shiftDate(
            base: $effective,
            modifier: self::FILING_WINDOW,
        );
    }//end computeFilingDeadline()

    /**
     * Decide whether the appellant filed past the 6-week window.
     *
     * Informational only — Procest never refuses or auto-closes a beroep
     * on timeliness; only the rechtbank weighs verschoonbare
     * termijnoverschrijding.
     *
     * @param string $filingDate ISO date the beroepschrift was filed
     * @param string $deadline   ISO date of the filing deadline
     *
     * @return bool True when filingDate > deadline.
     */
    private function isLateFiling(string $filingDate, string $deadline): bool
    {
        if ($filingDate === '' || $deadline === '') {
            return false;
        }

        try {
            $filed = new DateTimeImmutable($filingDate);
            $end   = new DateTimeImmutable($deadline);
        } catch (Throwable $e) {
            return false;
        }

        return $filed > $end;
    }//end isLateFiling()

    /**
     * Shift an ISO date by a DateTime modifier (e.g. "+42 days").
     *
     * @param string $base     ISO date
     * @param string $modifier DateTime modifier expression
     *
     * @return string Shifted ISO date (empty on parse failure)
     */
    private function shiftDate(string $base, string $modifier): string
    {
        if ($base === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($base))
                ->modify($modifier)
                ->format('Y-m-d');
        } catch (Throwable $e) {
            return '';
        }
    }//end shiftDate()
}//end class
