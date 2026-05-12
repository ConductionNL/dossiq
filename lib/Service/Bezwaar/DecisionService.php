<?php

/**
 * Procest Bezwaar Decision Service.
 *
 * Domain service for the bezwaar-decision capability — the formal
 * beslissing op bezwaar under Awb art. 7:11/7:12. Procest owns the
 * decision record, the structured rechtsmiddelenclausule, the
 * proceskostenvergoeding bookkeeping, and the publication + notification
 * flow. This service composes those operations and delegates every
 * persistence call to OpenRegister via SettingsService::getObjectService();
 * it never owns bespoke CRUD or a custom controller.
 *
 *  - draft()             — create a bezwaarDecision in status "draft" with
 *                          the canonical Awb 7:11 disposition enum and
 *                          link it to its bezwaar; required fields are
 *                          surface-validated.
 *  - publish()           — promote a draft to published after the
 *                          per-dispositionType mandatory-field guard
 *                          passes (replacementDecision for
 *                          gegrond_wijzigen; appealNotice completeness;
 *                          deviationRationale when advisoryOpinion is set
 *                          and the decision deviates; proceskosten rules
 *                          when herroepen/wijzigen). Sets publishedAt,
 *                          stamps notifiedRecipients, calls
 *                          applyToBezwaar() so the case transitions to
 *                          "Beslissing op bezwaar" via the
 *                          status-transition-engine.
 *  - applyToBezwaar()    — write the decision back onto the linked
 *                          bezwaar by invoking the
 *                          StatusTransitionService — no bespoke
 *                          transition logic lives here.
 *
 * Identity for the publication actor is ALWAYS derived from
 * IUserSession. Static error messages only — exception details never
 * bubble to controllers.
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
 * Bezwaar decision service: draft, publish, and apply to the linked
 * bezwaar via the status-transition-engine.
 *
 * @spec openspec/changes/bezwaar-decision/specs/bezwaar-decision/spec.md
 */
class DecisionService
{
    /**
     * Canonical Awb art. 7:11 disposition values (REQ-BD-2).
     */
    public const VALID_DISPOSITIONS = [
        'niet_ontvankelijk',
        'ongegrond',
        'gegrond_handhaven',
        'gegrond_herroepen',
        'gegrond_wijzigen',
    ];

    /**
     * Dispositions for which a replacementDecision is allowed/required.
     */
    private const REPLACEMENT_ALLOWED = [
        'gegrond_herroepen',
        'gegrond_wijzigen',
    ];

    /**
     * Dispositions for which proceskostenvergoeding may be awarded
     * (Awb art. 7:15 lid 2).
     */
    private const PROCESKOSTEN_ELIGIBLE = [
        'gegrond_herroepen',
        'gegrond_wijzigen',
    ];

    /**
     * Bezwaar status target on publication (handed off to the
     * status-transition-engine).
     */
    private const TARGET_BEZWAAR_STATUS = 'Beslissing op bezwaar';

    /**
     * Transition id wired on the bezwaar workflowTemplate to move into
     * "Beslissing op bezwaar".
     */
    private const TRANSITION_ID = 'beslissing-op-bezwaar';

    /**
     * Required appealNotice sub-fields (REQ-BD-6) regardless of
     * filingMethod. filingUrl/filingAddress requirements are
     * conditional on filingMethod and handled separately.
     *
     * @var array<int, string>
     */
    private const APPEAL_NOTICE_BASE_REQUIRED = [
        'competentCourt',
        'beroepTerm',
        'effectiveDate',
        'filingMethod',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService         $settingsService Schema/register bridge.
     * @param IUserSession            $userSession     Acting identity source.
     * @param StatusTransitionService $transitions     Engine used by
     *                                                 applyToBezwaar() to
     *                                                 transition the
     *                                                 linked bezwaar
     *                                                 without bespoke
     *                                                 transition logic.
     * @param LoggerInterface         $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly StatusTransitionService $transitions,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a bezwaarDecision in status "draft".
     *
     * Surface-validates the disposition enum and the per-disposition
     * mandatory-field rules that apply at draft time (canonical enum,
     * replacementDecision compatibility, reasoning + legalBasis
     * presence). Hard guards that only apply at publication time
     * (appealNotice completeness, proceskosten resolution) are
     * deferred to publish().
     *
     * @param string               $bezwaarId UUID of the bezwaar.
     * @param array<string, mixed> $payload   Decision properties.
     *
     * @return array<string, mixed> The created decision record.
     *
     * @throws RuntimeException When OpenRegister is unavailable, schemas
     *                          are unconfigured, or the payload is
     *                          invalid at draft time.
     */
    public function draft(string $bezwaarId, array $payload): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $decisionSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_decision_schema'
        );
        if ($register === '' || $decisionSchema === '') {
            throw new RuntimeException(
                'BezwaarDecision schema is not configured'
            );
        }

        $disposition = (string) ($payload['dispositionType'] ?? '');
        if (in_array($disposition, self::VALID_DISPOSITIONS, true) === false) {
            throw new RuntimeException(
                'Invalid disposition — must be one of the five canonical Awb '
                .'7:11 values'
            );
        }

        $reasoning  = (string) ($payload['reasoning'] ?? '');
        $legalBasis = (string) ($payload['legalBasis'] ?? '');
        if ($reasoning === '' || $legalBasis === '') {
            throw new RuntimeException(
                'reasoning and legalBasis are required (Awb art. 7:12)'
            );
        }

        $replacement = (string) ($payload['replacementDecision'] ?? '');
        if ($replacement !== ''
            && in_array($disposition, self::REPLACEMENT_ALLOWED, true) === false
        ) {
            throw new RuntimeException(
                'replacementDecision MUST NOT be set when disposition is not '
                .'gegrond_herroepen or gegrond_wijzigen'
            );
        }

        $record = array_merge(
            $payload,
            [
                'bezwaar' => $bezwaarId,
                'status'  => 'draft',
            ]
        );
        // publishedAt and notifiedRecipients are owned by publish().
        unset($record['publishedAt'], $record['notifiedRecipients']);

        try {
            return $objectService->saveObject(
                $register,
                $decisionSchema,
                $record
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest bezwaar-decision: failed to draft: '.$e->getMessage()
            );
            throw new RuntimeException('Could not draft bezwaarDecision');
        }
    }//end draft()

    /**
     * Publish a draft bezwaarDecision.
     *
     * Runs the full validity matrix (REQ-BD-3, REQ-BD-5, REQ-BD-6,
     * REQ-BD-7), sets publishedAt + notifiedRecipients, computes the
     * proceskosten total when applicable, and hands the case off to
     * the status-transition-engine via applyToBezwaar().
     *
     * @param string $decisionId UUID of the bezwaarDecision.
     *
     * @return array<string, mixed> The published decision record.
     *
     * @throws RuntimeException When validation fails or persistence
     *                          errors occur.
     */
    public function publish(string $decisionId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $decisionSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_decision_schema'
        );
        if ($register === '' || $decisionSchema === '') {
            throw new RuntimeException(
                'BezwaarDecision schema is not configured'
            );
        }

        $current = $objectService->findObject(
            $register,
            $decisionSchema,
            $decisionId
        );
        if (is_array($current) === false) {
            throw new RuntimeException('BezwaarDecision not found');
        }

        $this->assertPublishable(decision: $current);

        $patch = [
            'status'             => 'published',
            'publishedAt'        => (new DateTimeImmutable())->format(
                DateTimeImmutable::ATOM
            ),
            'notifiedRecipients' => $this->collectRecipients(
                decision: $current
            ),
        ];

        $totalAmount = $this->computeProceskostenTotal(decision: $current);
        if ($totalAmount !== null) {
            $proceskosten = (array) ($current['proceskostenvergoeding'] ?? []);
            $proceskosten['totalAmount']     = $totalAmount;
            $patch['proceskostenvergoeding'] = $proceskosten;
        }

        try {
            $saved = $objectService->saveObject(
                $register,
                $decisionSchema,
                $patch,
                $decisionId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest bezwaar-decision: failed to publish: '
                .$e->getMessage()
            );
            throw new RuntimeException('Could not publish bezwaarDecision');
        }

        $bezwaarId = (string) ($current['bezwaar'] ?? '');
        if ($bezwaarId !== '') {
            $this->applyToBezwaar(
                bezwaarId: $bezwaarId,
                decisionId: $decisionId
            );
        }

        return $saved;
    }//end publish()

    /**
     * Apply a published decision back to its bezwaar by triggering the
     * configured status transition. Never carries out a bespoke
     * transition itself — the engine owns guards + side effects.
     *
     * @param string $bezwaarId  UUID of the source bezwaar.
     * @param string $decisionId UUID of the bezwaarDecision triggering
     *                           the transition.
     *
     * @return void
     */
    public function applyToBezwaar(string $bezwaarId, string $decisionId): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register      = $this->settingsService->getConfigValue(
            key: 'register'
        );
        $bezwaarSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_schema'
        );
        if ($register === '' || $bezwaarSchema === '') {
            return;
        }

        $bezwaar = $objectService->findObject(
            $register,
            $bezwaarSchema,
            $bezwaarId
        );
        if (is_array($bezwaar) === false) {
            return;
        }

        $caseId = (string) ($bezwaar['case'] ?? '');
        if ($caseId === '') {
            return;
        }

        try {
            $this->transitions->execute(
                caseId: $caseId,
                transitionId: self::TRANSITION_ID,
                comment: 'Applied beslissing op bezwaar '.$decisionId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest bezwaar-decision: transition to '
                .self::TARGET_BEZWAAR_STATUS.' failed: '.$e->getMessage()
            );
        }
    }//end applyToBezwaar()

    /**
     * Run every publication-time guard against a draft decision.
     *
     * @param array<string, mixed> $decision The decision payload.
     *
     * @return void
     *
     * @throws RuntimeException When any guard rejects.
     */
    private function assertPublishable(array $decision): void
    {
        $disposition = (string) ($decision['dispositionType'] ?? '');
        if (in_array($disposition, self::VALID_DISPOSITIONS, true) === false) {
            throw new RuntimeException(
                'dispositionType is invalid — refusing to publish'
            );
        }

        // REQ-BD-3: gegrond_wijzigen requires replacementDecision.
        $replacement = (string) ($decision['replacementDecision'] ?? '');
        if ($disposition === 'gegrond_wijzigen' && $replacement === '') {
            throw new RuntimeException(
                'replacementDecision is required when disposition is '
                .'gegrond_wijzigen'
            );
        }

        // REQ-BD-3: ongegrond and gegrond_handhaven MUST NOT carry one.
        if ($replacement !== ''
            && in_array($disposition, self::REPLACEMENT_ALLOWED, true) === false
        ) {
            throw new RuntimeException(
                'replacementDecision MUST NOT be set when disposition is '
                .$disposition
            );
        }

        // REQ-BD-5: deviationRationale required when advisoryOpinion is
        // set and the decision deviates.
        $advisory = (string) ($decision['advisoryOpinion'] ?? '');
        if ($advisory !== '') {
            $follows = (bool) ($decision['followsAdvice'] ?? true);
            $reason  = (string) ($decision['deviationRationale'] ?? '');
            if ($follows === false && $reason === '') {
                throw new RuntimeException(
                    'deviationRationale is required when followsAdvice is '
                    .'false (Awb art. 7:13 lid 7)'
                );
            }
        }

        // REQ-BD-6: appealNotice completeness.
        $this->assertAppealNoticeComplete(decision: $decision);

        // REQ-BD-7: proceskostenvergoeding rules.
        $this->assertProceskostenRules(decision: $decision);
    }//end assertPublishable()

    /**
     * Validate the rechtsmiddelenclausule (REQ-BD-6).
     *
     * @param array<string, mixed> $decision Decision payload.
     *
     * @return void
     *
     * @throws RuntimeException When the appealNotice is incomplete.
     */
    private function assertAppealNoticeComplete(array $decision): void
    {
        $appealNotice = (array) ($decision['appealNotice'] ?? []);
        foreach (self::APPEAL_NOTICE_BASE_REQUIRED as $field) {
            $value = (string) ($appealNotice[$field] ?? '');
            if ($value === '') {
                throw new RuntimeException(
                    'Rechtsmiddelenclausule onvolledig: '.$field.' ontbreekt'
                );
            }
        }

        $method = (string) $appealNotice['filingMethod'];
        if (in_array($method, ['digitaal', 'beide'], true) === true) {
            $url = (string) ($appealNotice['filingUrl'] ?? '');
            if ($url === '') {
                throw new RuntimeException(
                    'filingUrl is required when filingMethod is '.$method
                );
            }
        }

        if (in_array($method, ['schriftelijk', 'beide'], true) === true) {
            $address = (string) ($appealNotice['filingAddress'] ?? '');
            if ($address === '') {
                throw new RuntimeException(
                    'filingAddress is required when filingMethod is '.$method
                );
            }
        }
    }//end assertAppealNoticeComplete()

    /**
     * Validate the proceskostenvergoeding decision (REQ-BD-7).
     *
     * @param array<string, mixed> $decision Decision payload.
     *
     * @return void
     *
     * @throws RuntimeException When proceskosten rules are violated.
     */
    private function assertProceskostenRules(array $decision): void
    {
        $disposition  = (string) ($decision['dispositionType'] ?? '');
        $proceskosten = (array) ($decision['proceskostenvergoeding'] ?? []);
        $requested    = (bool) ($proceskosten['requested'] ?? false);
        $awardedSet   = array_key_exists('awarded', $proceskosten);
        $awarded      = (bool) ($proceskosten['awarded'] ?? false);

        $eligible = in_array(
            $disposition,
            self::PROCESKOSTEN_ELIGIBLE,
            true
        );

        if ($awarded === true && $eligible === false) {
            throw new RuntimeException(
                'Proceskostenvergoeding niet mogelijk: primair besluit niet '
                .'herroepen (Awb art. 7:15 lid 2)'
            );
        }

        if ($requested === true && $eligible === true && $awardedSet === false) {
            throw new RuntimeException(
                'proceskosten.awarded MUST be explicitly set (true or false '
                .'with reasoning) when the bezwaarmaker requested '
                .'proceskostenvergoeding'
            );
        }
    }//end assertProceskostenRules()

    /**
     * Compute proceskosten.totalAmount = awardedPoints * pointValue.
     *
     * @param array<string, mixed> $decision Decision payload.
     *
     * @return float|null Null when no recalculation is needed.
     */
    private function computeProceskostenTotal(array $decision): ?float
    {
        $proceskosten = (array) ($decision['proceskostenvergoeding'] ?? []);
        if (($proceskosten['awarded'] ?? false) !== true) {
            return null;
        }

        $points = (float) ($proceskosten['awardedPoints'] ?? 0);
        $value  = (float) ($proceskosten['pointValue'] ?? 0);
        if ($points <= 0.0 || $value <= 0.0) {
            return null;
        }

        return ($points * $value);
    }//end computeProceskostenTotal()

    /**
     * Build the recipient audit list for the publication notification
     * flow (REQ-BD-10). Bezwaarmaker, gemachtigde, primair beslisser,
     * and the BAC secretaris (when advisoryOpinion is set) are
     * surfaced from the decision payload where available. The actual
     * notification fan-out is owned by NotificatieService /
     * BerichtenboxAdapter; this method records who SHOULD be reached.
     *
     * @param array<string, mixed> $decision Decision payload.
     *
     * @return array<int, string>
     */
    private function collectRecipients(array $decision): array
    {
        $recipients = [];

        $acting = $this->userSession->getUser();
        if ($acting !== null) {
            $recipients[] = 'actor:'.$acting->getUID();
        }

        $bezwaarmaker = (string) ($decision['bezwaarmaker'] ?? '');
        if ($bezwaarmaker !== '') {
            $recipients[] = 'bezwaarmaker:'.$bezwaarmaker;
        }

        $gemachtigde = (string) ($decision['gemachtigde'] ?? '');
        if ($gemachtigde !== '') {
            $recipients[] = 'gemachtigde:'.$gemachtigde;
        }

        $primairBeslisser = (string) ($decision['primairBeslisser'] ?? '');
        if ($primairBeslisser !== '') {
            $recipients[] = 'primair-beslisser:'.$primairBeslisser;
        }

        $advisory = (string) ($decision['advisoryOpinion'] ?? '');
        if ($advisory !== '') {
            $recipients[] = 'bac-secretaris:'.$advisory;
        }

        return array_values(array_unique($recipients));
    }//end collectRecipients()
}//end class
