<?php

/**
 * Procest WOO Decision Service
 *
 * Service for assembling the formal WOO besluit. Aggregates all document
 * assessments and writes a decision object linked to the case. Guards besluit
 * creation until every document has a classification with (where needed)
 * weigeringsgrond per WOO Art. 5.1/5.2.
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
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for assembling the formal WOO besluit.
 *
 * @psalm-suppress UnusedClass
 */
class WOODecisionService
{

    use SearchesObjects;

    /**
     * Decision type name for WOO besluiten.
     */
    private const DECISION_TYPE_TITLE = 'WOO-besluit';

    /**
     * Constructor.
     *
     * @param SettingsService              $settingsService           Settings service
     * @param WOODocumentAssessmentService $documentAssessmentService Document assessment service
     * @param IUserSession                 $userSession               Current user session
     * @param LoggerInterface              $logger                    Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly WOODocumentAssessmentService $documentAssessmentService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assemble the formal WOO besluit for a case.
     *
     * Validates that all documents are assessed, then writes a decision object
     * linked to the case referencing all assessments and weigeringsgronden.
     *
     * @param string               $caseId       The case UUID
     * @param array<string, mixed> $decisionData Optional override data (besluitdatum, samenvatting)
     *
     * @return array<string, mixed> Created decision with ID and assessment summary
     *
     * @throws \RuntimeException        If OpenRegister unavailable or case not found
     * @throws \InvalidArgumentException If any document has not been assessed
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-7
     */
    public function assembleDecision(string $caseId, array $decisionData=[]): array
    {
        // Guard: all documents must be assessed before a besluit can be created.
        $outstanding = $this->documentAssessmentService->getOutstanding(caseId: $caseId);
        if ($outstanding['count'] > 0) {
            throw new \InvalidArgumentException(
                'Cannot create besluit: '.$outstanding['count'].' document(s) still need assessment. '
                .'Document IDs: '.implode(', ', $outstanding['documents'])
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register         = $this->settingsService->getConfigValue('register');
        $decisionSchema   = $this->settingsService->getConfigValue('decision_schema');
        $assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

        if (empty($register) === true || empty($decisionSchema) === true) {
            throw new \RuntimeException('Decision schema not configured');
        }

        // Collect all assessments for the case.
        $assessments = [];
        if (empty($assessmentSchema) === false) {
            $result = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $assessmentSchema,
                filters: ['caseRef' => $caseId, '_limit' => 500],
            );

            if (is_array($result) === true) {
                $assessments = $result;
            }
        }

        // Summarise assessments by classification.
        $summary = [
            'openbaar'       => 0,
            'deels_openbaar' => 0,
            'niet_openbaar'  => 0,
        ];

        $weigeringsgronden = [];
        foreach ($assessments as $assessment) {
            $classification = $assessment['classification'] ?? null;
            if ($classification !== null && isset($summary[$classification]) === true) {
                $summary[$classification]++;
            }

            foreach (($assessment['weigeringsgronden'] ?? []) as $code) {
                if (in_array($code, $weigeringsgronden, true) === false) {
                    $weigeringsgronden[] = $code;
                }
            }
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $userId = $user->getUID();
        } else {
            $userId = 'system';
        }

        $besluitData = array_merge(
            [
                'case'              => $caseId,
                'decisionType'      => self::DECISION_TYPE_TITLE,
                'decisionDate'      => date('Y-m-d'),
                'description'       => 'WOO besluit voor zaak '.$caseId,
                'wooSummary'        => $summary,
                'weigeringsgronden' => $weigeringsgronden,
                'assessmentCount'   => count($assessments),
                'decidedBy'         => $userId,
            ],
            $decisionData,
        );

        $decision = $objectService->saveObject(object: $besluitData, register: $register, schema: $decisionSchema);

        $this->logger->info(
            'WOO besluit assembled for case '.$caseId.': decision '.$decision->getUuid(),
            ['app' => Application::APP_ID],
        );

        return [
            'decisionId'        => $decision->getUuid(),
            'caseId'            => $caseId,
            'summary'           => $summary,
            'weigeringsgronden' => $weigeringsgronden,
            'assessmentCount'   => count($assessments),
        ];
    }//end assembleDecision()
}//end class
