<?php

/**
 * Procest Step Config Validator
 *
 * Pure-function validator for the additive `config` sub-object on every
 * embedded WorkflowStep inside a workflowTemplate. Runs at publish time
 * inside WorkflowDefinitionService::publish() (when wired) and rejects
 * malformed SLA, unknown action keys, dangling field references, and
 * escalation rules without an accompanying SLA.
 *
 * The escalationRule half of the contract (rules 5, 6 and 7) lives in
 * {@see \OCA\Procest\Service\StepConfig\EscalationRuleValidator}; this class
 * owns the shape-level rules and composes that validator's errors into the
 * single flat list the caller receives.
 *
 * No DI, no I/O, no Nextcloud APIs. Returns a list of structured
 * validation errors with keys {path, code, message}. Never returns raw
 * exception messages — callers log the structured errors via the host
 * logger and surface a static generic error string to the UI.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\StepConfig\EscalationRuleValidator;

/**
 * Pure-function validator for WorkflowStep.config.
 *
 * Implements the seven validation rules from
 * openspec/changes/process-step-configuration/design.md.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
final class StepConfigValidator
{
    /**
     * Allowed enum values for the `sla.unit` property.
     *
     * @var array<int, string>
     */
    public const SLA_UNITS = ['hours', 'businessDays', 'calendarDays'];

    /**
     * Allowed enum values for the `escalationRule.offsetUnit` property.
     *
     * Re-exported from EscalationRuleValidator, which owns the escalation
     * rules, so existing `StepConfigValidator::OFFSET_UNITS` callers keep
     * reading the single source of truth.
     *
     * @var array<int, string>
     */
    public const OFFSET_UNITS = EscalationRuleValidator::OFFSET_UNITS;

    /**
     * Allowed enum values for the `escalationRule.trigger` property.
     *
     * Re-exported from EscalationRuleValidator — see OFFSET_UNITS.
     *
     * @var array<int, string>
     */
    public const TRIGGERS = EscalationRuleValidator::TRIGGERS;

    /**
     * Upper bound on `sla.value` (inclusive).
     *
     * @var int
     */
    public const SLA_VALUE_MAX = 10000;

    /**
     * Maximum recognised auto-action keys.
     *
     * Mirrors the action catalog defined by the `automatic-actions` spec.
     * Kept in sync with that spec by hand for V1; a future change may
     * inject the catalog via the constructor when the catalog service
     * exists.
     *
     * @var array<int, string>
     */
    public const DEFAULT_ACTION_CATALOG = [
        'sendEmail',
        'createTask',
        'createSubCase',
        'webhook',
        'setField',
        'notify',
        'notifySteller',
        'logToAuditTrail',
    ];

    /**
     * Validate a single step's config block.
     *
     * The caller is expected to invoke this once per step entry in the
     * `steps[]` array of a workflowTemplate during publish. The provided
     * caseType schema is consulted to verify field/role references; pass
     * an empty array to skip those checks (useful in unit tests).
     *
     * Returns the empty array on success. Each returned error has the
     * keys `path`, `code`, and `message`. The `message` is an internal
     * description — callers MUST NOT include it in user-facing responses.
     *
     * @param array<string, mixed> $step           The step (with optional `config`).
     * @param array<string, mixed> $caseTypeSchema The linked caseType — properties +
     *                                             roleTypes.
     * @param array<int, string>   $actionCatalog  Optional override of recognised action keys.
     * @param int                  $stepIndex      The step's index in `steps[]` for path prefix.
     *
     * @return array<int, array{path: string, code: string, message: string}>

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public static function validate(
        array $step,
        array $caseTypeSchema=[],
        array $actionCatalog=self::DEFAULT_ACTION_CATALOG,
        int $stepIndex=0
    ): array {
        $errors = [];
        $config = ($step['config'] ?? null);
        if ($config === null) {
            return $errors;
        }

        if (is_array($config) === false) {
            $errors[] = self::error(
                path: "steps[$stepIndex].config",
                code: 'malformed_config',
                message: 'config must be an object'
            );
            return $errors;
        }

        $base = "steps[$stepIndex].config";

        $errors = array_merge(
            $errors,
            self::validateSla(sla: ($config['sla'] ?? null), path: $base.'.sla')
        );

        $errors = array_merge(
            $errors,
            self::validateRequiredFields(
                fields: ($config['requiredFields'] ?? null),
                caseTypeProperties: ($caseTypeSchema['properties'] ?? []),
                path: $base.'.requiredFields'
            )
        );

        $errors = array_merge(
            $errors,
            self::validateAutoActions(
                actions: ($config['autoActions'] ?? null),
                actionCatalog: $actionCatalog,
                path: $base.'.autoActions'
            )
        );

        $errors = array_merge(
            $errors,
            (new EscalationRuleValidator())->validate(
                rule: ($config['escalationRule'] ?? null),
                sla: ($config['sla'] ?? null),
                roleTypes: ($caseTypeSchema['roleTypes'] ?? []),
                path: $base.'.escalationRule'
            )
        );

        return $errors;
    }//end validate()

    /**
     * Build a structured error record.
     *
     * @param string $path    The JSON-pointer-like path to the bad value.
     * @param string $code    The stable error code (snake_case).
     * @param string $message An internal description (never user-facing).
     *
     * @return array{path: string, code: string, message: string}
     */
    private static function error(string $path, string $code, string $message): array
    {
        return [
            'path'    => $path,
            'code'    => $code,
            'message' => $message,
        ];
    }//end error()

    /**
     * Validate `config.sla`.
     *
     * Rules 1 + 2 from design.md.
     *
     * @param mixed  $sla  The raw sla value (object or null).
     * @param string $path The path prefix for any error.
     *
     * @return array<int, array{path: string, code: string, message: string}>
     */
    private static function validateSla(mixed $sla, string $path): array
    {
        if ($sla === null) {
            return [];
        }

        if (is_array($sla) === false) {
            return [self::error(path: $path, code: 'malformed_sla', message: 'sla must be an object')];
        }

        $errors = [];

        $value = ($sla['value'] ?? null);
        if (is_int($value) === false || $value < 1 || $value > self::SLA_VALUE_MAX) {
            $errors[] = self::error(
                path: $path.'.value',
                code: 'out_of_range',
                message: 'sla.value must be a positive integer not greater than '
                .self::SLA_VALUE_MAX
            );
        }

        $unit = ($sla['unit'] ?? null);
        if (is_string($unit) === false || in_array($unit, self::SLA_UNITS, true) === false) {
            $errors[] = self::error(
                path: $path.'.unit',
                code: 'unknown_sla_unit',
                message: 'sla.unit must be one of: '.implode(', ', self::SLA_UNITS)
            );
        }

        return $errors;
    }//end validateSla()

    /**
     * Validate `config.requiredFields`.
     *
     * Rule 3 from design.md.
     *
     * @param mixed                $fields             The raw requiredFields value.
     * @param array<string, mixed> $caseTypeProperties Map of property name to definition.
     * @param string               $path               The path prefix for any error.
     *
     * @return array<int, array{path: string, code: string, message: string}>
     */
    private static function validateRequiredFields(
        mixed $fields,
        array $caseTypeProperties,
        string $path
    ): array {
        if ($fields === null) {
            return [];
        }

        if (is_array($fields) === false) {
            return [self::error(path: $path, code: 'malformed_required_fields', message: 'requiredFields must be an array')];
        }

        $errors = [];
        // Skip dangling-reference check when caller supplied no schema.
        $checkRefs = ($caseTypeProperties !== []);

        foreach ($fields as $index => $field) {
            if (is_string($field) === false || $field === '') {
                $errors[] = self::error(
                    path: $path."[$index]",
                    code: 'malformed_required_field',
                    message: 'requiredFields entries must be non-empty strings'
                );
                continue;
            }

            if ($checkRefs === true && array_key_exists($field, $caseTypeProperties) === false) {
                $errors[] = self::error(
                    path: $path."[$index]",
                    code: 'unknown_field_reference',
                    message: 'requiredFields entry does not resolve to a caseType property'
                );
            }
        }

        return $errors;
    }//end validateRequiredFields()

    /**
     * Validate `config.autoActions`.
     *
     * Rule 4 from design.md.
     *
     * @param mixed              $actions       The raw autoActions value.
     * @param array<int, string> $actionCatalog Allowed action keys.
     * @param string             $path          The path prefix for any error.
     *
     * @return array<int, array{path: string, code: string, message: string}>
     */
    private static function validateAutoActions(
        mixed $actions,
        array $actionCatalog,
        string $path
    ): array {
        if ($actions === null) {
            return [];
        }

        if (is_array($actions) === false) {
            return [self::error(path: $path, code: 'malformed_auto_actions', message: 'autoActions must be an array')];
        }

        $errors = [];
        foreach ($actions as $index => $action) {
            if (is_array($action) === false) {
                $errors[] = self::error(
                    path: $path."[$index]",
                    code: 'malformed_action_ref',
                    message: 'autoActions entries must be objects with `key` and `parameters`'
                );
                continue;
            }

            $key = ($action['key'] ?? null);
            if (is_string($key) === false || $key === '') {
                $errors[] = self::error(
                    path: $path."[$index].key",
                    code: 'malformed_action_key',
                    message: 'autoActions[i].key must be a non-empty string'
                );
                continue;
            }

            if (in_array($key, $actionCatalog, true) === false) {
                $errors[] = self::error(
                    path: $path."[$index].key",
                    code: 'unknown_action_key',
                    message: 'autoActions[i].key is not registered in the action catalog'
                );
            }
        }//end foreach

        return $errors;
    }//end validateAutoActions()
}//end class
