<?php

/**
 * Procest Required Field Guard evaluator.
 *
 * Guard config shape: `{type: 'requiredField', field: 'resultaat'}`.
 * Passes when `case[field]` is non-null, non-empty-string, non-empty-array.
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
 * Guard: verifies a named case field is non-empty.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class RequiredFieldGuard implements GuardEvaluatorInterface
{
    /**
     * Evaluate the required-field guard.
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
        $field = (string) ($guardConfig['field'] ?? '');
        if ($field === '') {
            return GuardResult::fail(message: 'Required-field guard missing field');
        }

        $value = $case[$field] ?? null;
        if ($value === null || $value === '' || (is_array($value) === true && count($value) === 0)) {
            return GuardResult::fail(
                message: sprintf('Vereist veld ontbreekt: %s', $field),
                details: ['field' => $field],
            );
        }

        return GuardResult::pass(details: ['field' => $field]);
    }//end evaluate()
}//end class
