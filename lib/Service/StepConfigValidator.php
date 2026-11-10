<?php

/**
 * Dossiq Step Config Validator
 *
 * Pure-function validator for the additive `config` sub-object on every
 * embedded WorkflowStep inside a workflowTemplate. Runs at publish time
 * inside WorkflowDefinitionService::publish() (when wired) and rejects
 * malformed SLA, unknown action keys, dangling field references, and
 * escalation rules without an accompanying SLA.
 *
 * The escalationRule half of the contract (rules 5, 6 and 7) lives in
 * {@see \OCA\Dossiq\Service\StepConfig\EscalationRuleValidator}; this class
 * owns the shape-level rules and composes that validator's errors into the
 * single flat list the caller receives.
 *
 * No DI, no I/O, no Nextcloud APIs. Returns a list of structured
 * validation errors with keys {path, code, message}. Never returns raw
 * exception messages — callers log the structured errors via the host
 * logger and surface a static generic error string to the UI.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\StepConfig\EscalationRuleValidator;

/**
 * Pure-function validator for WorkflowStep.config.
 *
 * Implements the seven validation rules from
 * openspec/changes/process-step-configuration/design.md.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
final class StepConfigValidator {
	/**
	 * Allowed enum values for the `sla.unit` property.
	 *
	 * @var array<int, string>
	 */
	public const SLA_UNITS = ['hours', 'businessDays', 'calendarDays'];

	/**
	 * The message of each refusal code whose sentence is fixed.
	 *
	 * @var array<string, string>
	 */
	private const MESSAGES = [
		'malformed_config' => 'config must be an object',
		'malformed_sla' => 'sla must be an object',
		'malformed_required_fields' => 'requiredFields must be an array',
		'malformed_auto_actions' => 'autoActions must be an array',
		'malformed_required_field' => 'requiredFields entries must be non-empty strings',
		'unknown_field_reference' => 'requiredFields entry does not resolve to a caseType property',
		'malformed_action_ref' => 'autoActions entries must be objects with `key` and `parameters`',
		'malformed_action_key' => 'autoActions[i].key must be a non-empty string',
		'unknown_action_key' => 'autoActions[i].key is not registered in the action catalog',
	];

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
	 * @param array<string, mixed> $step The step (with optional `config`).
	 * @param array<string, mixed> $caseTypeSchema The linked caseType — properties +
	 *                                             roleTypes.
	 * @param array<int, string> $actionCatalog Optional override of recognised action keys.
	 * @param int $stepIndex The step's index in `steps[]` for path prefix.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public static function validate(
		array $step,
		array $caseTypeSchema = [],
		array $actionCatalog = self::DEFAULT_ACTION_CATALOG,
		int $stepIndex = 0,
	): array {
		$config = ($step['config'] ?? null);
		if ($config === null) {
			return [];
		}

		if (is_array($config) === false) {
			return [self::entryError(path: "steps[$stepIndex].config", code: 'malformed_config')];
		}

		$scope = new StepConfigScope();
		$scope->config = $config;
		$scope->caseTypeSchema = $caseTypeSchema;
		$scope->actionCatalog = $actionCatalog;
		$scope->base = "steps[$stepIndex].config";

		return array_merge(
			self::validateSla(sla: ($config['sla'] ?? null), path: $scope->base . '.sla'),
			self::validateRequiredFields(scope: $scope),
			self::validateAutoActions(scope: $scope),
			self::escalationErrors(scope: $scope)
		);
	}//end validate()

	/**
	 * The escalation rule's errors, read against the step's SLA and the case type's roles.
	 *
	 * @param StepConfigScope $scope The config being validated.
	 *
	 * @return list<array{path: string, code: string, message: string}>
	 */
	private static function escalationErrors(StepConfigScope $scope): array {
		return (new EscalationRuleValidator())->validate(
			rule: ($scope->config['escalationRule'] ?? null),
			sla: ($scope->config['sla'] ?? null),
			roleTypes: ($scope->caseTypeSchema['roleTypes'] ?? []),
			path: $scope->base . '.escalationRule'
		);
	}//end escalationErrors()

	/**
	 * Build a structured error record.
	 *
	 * @param string $path The JSON-pointer-like path to the bad value.
	 * @param string $code The stable error code (snake_case).
	 * @param string $message An internal description (never user-facing).
	 *
	 * @return array{path: string, code: string, message: string}
	 */
	private static function error(string $path, string $code, string $message): array {
		return [
			'path' => $path,
			'code' => $code,
			'message' => $message,
		];
	}//end error()

	/**
	 * One error whose sentence is fixed by its code.
	 *
	 * @param string $path Dotted path to the offending field.
	 * @param string $code Stable machine-readable code.
	 *
	 * @return array{path: string, code: string, message: string}
	 */
	private static function entryError(string $path, string $code): array {
		return self::error(path: $path, code: $code, message: self::MESSAGES[$code]);
	}//end entryError()

	/**
	 * Validate `config.sla`.
	 *
	 * Rules 1 + 2 from design.md.
	 *
	 * @param mixed $sla The raw sla value (object or null).
	 * @param string $path The path prefix for any error.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 */
	private static function validateSla(mixed $sla, string $path): array {
		if ($sla === null) {
			return [];
		}

		if (is_array($sla) === false) {
			return [self::entryError(path: $path, code: 'malformed_sla')];
		}

		$errors = [];

		$value = ($sla['value'] ?? null);
		if (is_int($value) === false || $value < 1 || $value > self::SLA_VALUE_MAX) {
			$errors[] = self::error(
				path: $path . '.value',
				code: 'out_of_range',
				message: 'sla.value must be a positive integer not greater than '
				. self::SLA_VALUE_MAX
			);
		}

		$unit = ($sla['unit'] ?? null);
		if (is_string($unit) === false || in_array($unit, self::SLA_UNITS, true) === false) {
			$errors[] = self::error(
				path: $path . '.unit',
				code: 'unknown_sla_unit',
				message: 'sla.unit must be one of: ' . implode(', ', self::SLA_UNITS)
			);
		}

		return $errors;
	}//end validateSla()

	/**
	 * Validate `config.requiredFields`.
	 *
	 * Rule 3 from design.md.
	 *
	 * @param StepConfigScope $scope The config being validated: its requiredFields, the case type properties and the path.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 */
	private static function validateRequiredFields(StepConfigScope $scope): array {
		$fields = ($scope->config['requiredFields'] ?? null);
		$caseTypeProperties = ($scope->caseTypeSchema['properties'] ?? []);
		$path = $scope->base . '.requiredFields';
		if ($fields === null) {
			return [];
		}

		if (is_array($fields) === false) {
			return [self::entryError(path: $path, code: 'malformed_required_fields')];
		}

		$errors = [];
		// Skip dangling-reference check when caller supplied no schema.
		$checkRefs = ($caseTypeProperties !== []);

		foreach ($fields as $index => $field) {
			if (is_string($field) === false || $field === '') {
				$errors[] = self::entryError(path: $path . "[$index]", code: 'malformed_required_field');
				continue;
			}

			if ($checkRefs === true && array_key_exists($field, $caseTypeProperties) === false) {
				$errors[] = self::entryError(path: $path . "[$index]", code: 'unknown_field_reference');
			}
		}

		return $errors;
	}//end validateRequiredFields()

	/**
	 * Validate `config.autoActions`.
	 *
	 * Rule 4 from design.md.
	 *
	 * @param StepConfigScope $scope The config being validated: its autoActions, the action catalog and the path.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 */
	private static function validateAutoActions(StepConfigScope $scope): array {
		$actions = ($scope->config['autoActions'] ?? null);
		$actionCatalog = $scope->actionCatalog;
		$path = $scope->base . '.autoActions';
		if ($actions === null) {
			return [];
		}

		if (is_array($actions) === false) {
			return [self::entryError(path: $path, code: 'malformed_auto_actions')];
		}

		$errors = [];
		foreach ($actions as $index => $action) {
			if (is_array($action) === false) {
				$errors[] = self::entryError(path: $path . "[$index]", code: 'malformed_action_ref');
				continue;
			}

			$key = ($action['key'] ?? null);
			if (is_string($key) === false || $key === '') {
				$errors[] = self::entryError(path: $path . "[$index].key", code: 'malformed_action_key');
				continue;
			}

			if (in_array($key, $actionCatalog, true) === false) {
				$errors[] = self::entryError(path: $path . "[$index].key", code: 'unknown_action_key');
			}
		}//end foreach

		return $errors;
	}//end validateAutoActions()
}//end class
