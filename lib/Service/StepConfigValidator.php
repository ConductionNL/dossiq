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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

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
     * @var array<int, string>
     */
    public const OFFSET_UNITS = ['hours', 'businessDays'];

    /**
     * Allowed enum values for the `escalationRule.trigger` property.
     *
     * @var array<int, string>
     */
    public const TRIGGERS = ['preBreach', 'slaBreached'];

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
                "steps[$stepIndex].config",
                'malformed_config',
                'config must be an object'
            );
            return $errors;
        }

        $base = "steps[$stepIndex].config";

        $errors = array_merge(
            $errors,
            self::validateSla($config['sla'] ?? null, $base.'.sla')
        );

        $errors = array_merge(
            $errors,
            self::validateRequiredFields(
                ($config['requiredFields'] ?? null),
                ($caseTypeSchema['properties'] ?? []),
                $base.'.requiredFields'
            )
        );

        $errors = array_merge(
            $errors,
            self::validateAutoActions(
                ($config['autoActions'] ?? null),
                $actionCatalog,
                $base.'.autoActions'
            )
        );

        $errors = array_merge(
            $errors,
            self::validateEscalationRule(
                ($config['escalationRule'] ?? null),
                ($config['sla'] ?? null),
                ($caseTypeSchema['roleTypes'] ?? []),
                $base.'.escalationRule'
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
            return [self::error($path, 'malformed_sla', 'sla must be an object')];
        }

        $errors = [];

        $value = ($sla['value'] ?? null);
        if (is_int($value) === false || $value < 1 || $value > self::SLA_VALUE_MAX) {
            $errors[] = self::error(
                $path.'.value',
                'out_of_range',
                'sla.value must be a positive integer not greater than '
                .self::SLA_VALUE_MAX
            );
        }

        $unit = ($sla['unit'] ?? null);
        if (is_string($unit) === false || in_array($unit, self::SLA_UNITS, true) === false) {
            $errors[] = self::error(
                $path.'.unit',
                'unknown_sla_unit',
                'sla.unit must be one of: '.implode(', ', self::SLA_UNITS)
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
            return [self::error($path, 'malformed_required_fields', 'requiredFields must be an array')];
        }

        $errors = [];
        // Skip dangling-reference check when caller supplied no schema.
        $checkRefs = ($caseTypeProperties !== []);

        foreach ($fields as $index => $field) {
            if (is_string($field) === false || $field === '') {
                $errors[] = self::error(
                    $path."[$index]",
                    'malformed_required_field',
                    'requiredFields entries must be non-empty strings'
                );
                continue;
            }

            if ($checkRefs === true && array_key_exists($field, $caseTypeProperties) === false) {
                $errors[] = self::error(
                    $path."[$index]",
                    'unknown_field_reference',
                    'requiredFields entry does not resolve to a caseType property'
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
            return [self::error($path, 'malformed_auto_actions', 'autoActions must be an array')];
        }

        $errors = [];
        foreach ($actions as $index => $action) {
            if (is_array($action) === false) {
                $errors[] = self::error(
                    $path."[$index]",
                    'malformed_action_ref',
                    'autoActions entries must be objects with `key` and `parameters`'
                );
                continue;
            }

            $key = ($action['key'] ?? null);
            if (is_string($key) === false || $key === '') {
                $errors[] = self::error(
                    $path."[$index].key",
                    'malformed_action_key',
                    'autoActions[i].key must be a non-empty string'
                );
                continue;
            }

            if (in_array($key, $actionCatalog, true) === false) {
                $errors[] = self::error(
                    $path."[$index].key",
                    'unknown_action_key',
                    'autoActions[i].key is not registered in the action catalog'
                );
            }
        }//end foreach

        return $errors;
    }//end validateAutoActions()

    /**
     * Validate `config.escalationRule`.
     *
     * Rules 5, 6, and 7 from design.md.
     *
     * @param mixed                $rule      The raw escalationRule value.
     * @param mixed                $sla       The raw sla value (for rules 6 + 7).
     * @param array<string, mixed> $roleTypes Map of role name/uuid to definition.
     * @param string               $path      The path prefix for any error.
     *
     * @return array<int, array{path: string, code: string, message: string}>
     */
    private static function validateEscalationRule(
        mixed $rule,
        mixed $sla,
        array $roleTypes,
        string $path
    ): array {
        if ($rule === null) {
            return [];
        }

        if (is_array($rule) === false) {
            return [self::error($path, 'malformed_escalation_rule', 'escalationRule must be an object')];
        }

        $errors = [];

        // Rule 6: escalationRule requires an SLA.
        if ($sla === null) {
            $errors[] = self::error(
                $path,
                'escalation_requires_sla',
                'escalationRule cannot be set without a sla'
            );
        }

        $trigger = ($rule['trigger'] ?? null);
        if (is_string($trigger) === false || in_array($trigger, self::TRIGGERS, true) === false) {
            $errors[] = self::error(
                $path.'.trigger',
                'unknown_trigger',
                'escalationRule.trigger must be one of: '.implode(', ', self::TRIGGERS)
            );
        }

        $offset = ($rule['offset'] ?? null);
        if (is_int($offset) === false || $offset < 0) {
            $errors[] = self::error(
                $path.'.offset',
                'out_of_range',
                'escalationRule.offset must be a non-negative integer'
            );
        }

        $offsetUnit = ($rule['offsetUnit'] ?? null);
        if (is_string($offsetUnit) === false
            || in_array($offsetUnit, self::OFFSET_UNITS, true) === false
        ) {
            $errors[] = self::error(
                $path.'.offsetUnit',
                'unknown_offset_unit',
                'escalationRule.offsetUnit must be one of: '.implode(', ', self::OFFSET_UNITS)
            );
        }

        // Rule 7: preBreach offset cannot exceed sla.value.
        if ($trigger === 'preBreach'
            && is_int($offset) === true
            && is_array($sla) === true
            && is_int(($sla['value'] ?? null)) === true
            && $offset > $sla['value']
        ) {
            $errors[] = self::error(
                $path.'.offset',
                'offset_exceeds_sla',
                'escalationRule.offset must not exceed sla.value when trigger is preBreach'
            );
        }

        // Rule 5: notifyRole + escalateToRole must resolve when roleTypes provided.
        $checkRoles = ($roleTypes !== []);
        foreach (['notifyRole', 'escalateToRole'] as $roleKey) {
            $role = ($rule[$roleKey] ?? null);
            if ($role === null) {
                continue;
            }

            if (is_string($role) === false || $role === '') {
                $errors[] = self::error(
                    $path.'.'.$roleKey,
                    'malformed_role_reference',
                    $roleKey.' must be a non-empty role reference'
                );
                continue;
            }

            if ($checkRoles === true && array_key_exists($role, $roleTypes) === false) {
                $errors[] = self::error(
                    $path.'.'.$roleKey,
                    'unknown_role_reference',
                    $roleKey.' does not resolve to a roleType on the linked caseType'
                );
            }
        }//end foreach

        $openIncident = ($rule['openIncident'] ?? null);
        if ($openIncident !== null && is_bool($openIncident) === false) {
            $errors[] = self::error(
                $path.'.openIncident',
                'malformed_open_incident',
                'escalationRule.openIncident must be a boolean'
            );
        }

        return $errors;
    }//end validateEscalationRule()
}//end class
