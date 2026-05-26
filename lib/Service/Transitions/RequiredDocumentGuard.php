<?php

/**
 * Procest Required Document Guard evaluator.
 *
 * Guard config shape: `{type: 'requiredDocument', documentType: 'Besluit'}`.
 * Passes when at least one document linked to the case has the matching
 * documentType. Documents are read from `case.documents`, `case.files`, or
 * `case.relations` (whichever the case schema exposes for attachments).
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
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

namespace OCA\Procest\Service\Transitions;

/**
 * Guard: verifies the case has at least one document of the required type.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class RequiredDocumentGuard implements GuardEvaluatorInterface
{
    /**
     * Evaluate the required-document guard.
     *
     * @param array<string, mixed> $guardConfig Guard configuration
     * @param array<string, mixed> $case        Case object
     * @param string               $userId      Current user UID (unused)
     *
     * @return GuardResult
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    /** @spec openspec/specs/status-transition-engine/spec.md */
    public function evaluate(array $guardConfig, array $case, string $userId): GuardResult
    {
        $required = (string) ($guardConfig['documentType'] ?? '');
        if ($required === '') {
            return GuardResult::fail(message: 'Required-document guard missing documentType');
        }

        $candidates = [];
        foreach (['documents', 'files', 'caseDocuments', 'attachments'] as $field) {
            $value = $case[$field] ?? null;
            if (is_array($value) === true) {
                $candidates = array_merge($candidates, $value);
            }
        }

        foreach ($candidates as $doc) {
            if (is_array($doc) === false) {
                continue;
            }

            $type = (string) ($doc['documentType'] ?? ($doc['type'] ?? ''));
            if ($type === $required) {
                return GuardResult::pass(details: ['documentType' => $required]);
            }
        }

        return GuardResult::fail(
            message: sprintf('Vereist document ontbreekt: %s', $required),
            details: ['documentType' => $required],
        );
    }//end evaluate()
}//end class
