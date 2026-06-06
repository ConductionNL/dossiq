<?php

/**
 * Procest WOO Document Assessment Service
 *
 * Service for per-document WOO disclosure assessments. Manages wooAssessment
 * records (openbaar / deels openbaar / niet openbaar) with mandatory
 * weigeringsgrond validation per WOO Art. 5.1/5.2. Guards stage advancement
 * until every collected document has been assessed.
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
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for WOO per-document disclosure assessments.
 *
 * @psalm-suppress UnusedClass
 */
class WOODocumentAssessmentService
{

    /**
     * Valid classification values.
     */
    private const VALID_CLASSIFICATIONS = [
        'openbaar',
        'deels_openbaar',
        'niet_openbaar',
    ];

    /**
     * Classifications that require at least one weigeringsgrond.
     */
    private const REQUIRES_WEIGERINGSGROND = [
        'niet_openbaar',
        'deels_openbaar',
    ];

    /**
     * Valid WOO Art. 5.1/5.2 weigeringsgrond codes.
     */
    private const VALID_WEIGERINGSGRONDEN = [
        '5.1.1',
        '5.1.2',
        '5.1.3',
        '5.1.4',
        '5.1.5',
        '5.2.1',
        '5.2.2',
        '5.2.3',
        '5.2.4',
        '5.2.5',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param IUserSession    $userSession     Current user session
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Bulk-upsert assessments for a case's documents.
     *
     * Creates or updates wooAssessment records. Returns the list of saved
     * assessments and flags any documents that still lack an assessment.
     *
     * @param string                           $caseId      The case UUID
     * @param array<int, array<string, mixed>> $assessments Array of assessment payloads
     *
     * @return array<string, mixed> Result with saved assessments and outstanding documents
     *
     * @throws \RuntimeException If OpenRegister unavailable
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-5
     */
    public function bulkUpsert(string $caseId, array $assessments): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register         = $this->settingsService->getConfigValue('register');
        $assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

        if (empty($register) === true || empty($assessmentSchema) === true) {
            throw new \RuntimeException('WOO assessment schema not configured');
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $userId = $user->getUID();
        } else {
            $userId = 'system';
        }

        $saved  = [];
        $errors = [];

        foreach ($assessments as $assessment) {
            $validationErrors = $this->validate(assessment: $assessment);
            if (empty($validationErrors) === false) {
                $errors[] = [
                    'documentRef' => $assessment['documentRef'] ?? 'unknown',
                    'errors'      => $validationErrors,
                ];
                continue;
            }

            $assessment['caseRef']    = $caseId;
            $assessment['assessedBy'] = $userId;
            $assessment['assessedAt'] = date('Y-m-d\TH:i:s');

            // Find existing assessment for this document in this case.
            $existing = $objectService->findObjects(
                $register,
                $assessmentSchema,
                [
                    'caseRef'     => $caseId,
                    'documentRef' => $assessment['documentRef'],
                    '_limit'      => 1,
                ],
            );

            if (is_array($existing) === true && count($existing) > 0) {
                $existingId  = $existing[0]['id'] ?? $existing[0]['uuid'] ?? null;
                $savedObject = $objectService->saveObject(
                    $register,
                    $assessmentSchema,
                    $assessment,
                    $existingId,
                );
            } else {
                $savedObject = $objectService->saveObject(
                    $register,
                    $assessmentSchema,
                    $assessment,
                );
            }

            $saved[] = $savedObject;
        }//end foreach

        $outstanding = $this->getOutstanding(caseId: $caseId);

        $this->logger->info(
            'WOO bulk-upsert: '.count($saved).' saved, '.$outstanding['count'].' outstanding for case '.$caseId,
            ['app' => Application::APP_ID],
        );

        return [
            'saved'       => $saved,
            'errors'      => $errors,
            'outstanding' => $outstanding,
        ];
    }//end bulkUpsert()

    /**
     * Validate a single assessment payload.
     *
     * @param array<string, mixed> $assessment The assessment to validate
     *
     * @return array<string, string> Validation errors keyed by field name; empty if valid
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-5
     */
    public function validate(array $assessment): array
    {
        $errors = [];

        if (empty($assessment['documentRef']) === true) {
            $errors['documentRef'] = 'documentRef is required';
        }

        $classification = $assessment['classification'] ?? null;
        if (empty($classification) === true) {
            $errors['classification'] = 'classification is required';
        } else if (in_array($classification, self::VALID_CLASSIFICATIONS, true) === false) {
            $errors['classification'] = 'Invalid classification. Must be one of: '
                .implode(', ', self::VALID_CLASSIFICATIONS);
        } else if (in_array($classification, self::REQUIRES_WEIGERINGSGROND, true) === true) {
            $grounds = $assessment['weigeringsgronden'] ?? [];
            if (empty($grounds) === true) {
                $errors['weigeringsgronden'] = 'At least one weigeringsgrond is required for '
                    .$classification.' (WOO Art. 5.1/5.2)';
            } else {
                foreach ($grounds as $code) {
                    if (in_array($code, self::VALID_WEIGERINGSGRONDEN, true) === false) {
                        $errors['weigeringsgronden'] = 'Invalid weigeringsgrond code: '.$code;
                        break;
                    }
                }
            }
        }

        return $errors;
    }//end validate()

    /**
     * Get documents without a completed assessment for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return array<string, mixed> Array with 'count' and 'documents' list of unassessed doc IDs
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-5
     */
    public function getOutstanding(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['count' => 0, 'documents' => []];
        }

        $register         = $this->settingsService->getConfigValue('register');
        $docSchema        = $this->settingsService->getConfigValue('document_schema');
        $assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

        if (empty($register) === true) {
            return ['count' => 0, 'documents' => []];
        }

        // Collect all documents for this case.
        $allDocs = [];
        if (empty($docSchema) === false) {
            $docs = $objectService->findObjects(
                $register,
                $docSchema,
                ['case' => $caseId, '_limit' => 500],
            );

            if (is_array($docs) === true) {
                foreach ($docs as $doc) {
                    $docId = $doc['id'] ?? $doc['uuid'] ?? null;
                    if ($docId !== null) {
                        $allDocs[$docId] = true;
                    }
                }
            }
        }

        if (empty($allDocs) === true) {
            return ['count' => 0, 'documents' => []];
        }

        // Collect all assessed document IDs.
        $assessedDocIds = [];
        if (empty($assessmentSchema) === false) {
            $assessed = $objectService->findObjects(
                $register,
                $assessmentSchema,
                ['caseRef' => $caseId, '_limit' => 500],
            );

            if (is_array($assessed) === true) {
                foreach ($assessed as $item) {
                    $docRef = $item['documentRef'] ?? null;
                    if ($docRef !== null) {
                        $assessedDocIds[$docRef] = true;
                    }
                }
            }
        }

        $outstanding = array_keys(array_diff_key($allDocs, $assessedDocIds));

        return [
            'count'     => count($outstanding),
            'documents' => $outstanding,
        ];
    }//end getOutstanding()

    /**
     * Check whether all documents in a case have been assessed.
     *
     * Used as a stage-advancement guard before "Lakken / Anonimiseren".
     *
     * @param string $caseId The case UUID
     *
     * @return bool True if all documents are assessed
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-5
     */
    public function allDocumentsAssessed(string $caseId): bool
    {
        $outstanding = $this->getOutstanding(caseId: $caseId);
        return ($outstanding['count'] === 0);
    }//end allDocumentsAssessed()
}//end class
