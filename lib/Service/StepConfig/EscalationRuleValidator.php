<?php

/**
 * Dossiq Escalation Rule Validator.
 *
 * Validation rules 5, 6 and 7 of the WorkflowStep `config` block — everything
 * that concerns `config.escalationRule`. Split out of StepConfigValidator so
 * that class keeps only the shape-level rules (sla bounds, required-field
 * references, action-catalog membership): the escalation contract — a trigger
 * from a fixed enum, a non-negative offset in a known unit, a preBreach offset
 * that cannot outrun the SLA it precedes, notify/escalate roles that resolve
 * on the linked caseType, and the "an escalationRule without an sla is
 * meaningless" invariant — lives here and nowhere else.
 *
 * Stateless: no DI, no I/O, no Nextcloud APIs — an instance is cheap and
 * carries nothing between calls. Returns the same {path, code, message}
 * error records StepConfigValidator returns, so a caller cannot tell which
 * class produced a given error.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\StepConfig
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\StepConfig;

/**
 * Pure-function validator for WorkflowStep.config.escalationRule.
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
final class EscalationRuleValidator {

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
	 * Validate `config.escalationRule`.
	 *
	 * Rules 5, 6, and 7 from design.md.
	 *
	 * @param mixed $rule The raw escalationRule value.
	 * @param mixed $sla The raw sla value (for rules 6 + 7).
	 * @param array<string, mixed> $roleTypes Map of role name/uuid to definition.
	 * @param string $path The path prefix for any error.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	public function validate(
		mixed $rule,
		mixed $sla,
		array $roleTypes,
		string $path,
	): array {
		if ($rule === null) {
			return [];
		}

		if (is_array($rule) === false) {
			return [$this->error(path: $path, code: 'malformed_escalation_rule', message: 'escalationRule must be an object')];
		}

		$errors = [];

		// Rule 6: escalationRule requires an SLA.
		if ($sla === null) {
			$errors[] = $this->error(
				path: $path,
				code: 'escalation_requires_sla',
				message: 'escalationRule cannot be set without a sla'
			);
		}

		$errors = array_merge($errors, $this->validateTiming(rule: $rule, path: $path));
		$errors = array_merge($errors, $this->validatePreBreachOffset(rule: $rule, sla: $sla, path: $path));
		$errors = array_merge(
			$errors,
			$this->validateRoles(rule: $rule, roleTypes: $roleTypes, path: $path)
		);

		$openIncident = ($rule['openIncident'] ?? null);
		if ($openIncident !== null && is_bool($openIncident) === false) {
			$errors[] = $this->error(
				path: $path . '.openIncident',
				code: 'malformed_open_incident',
				message: 'escalationRule.openIncident must be a boolean'
			);
		}

		return $errors;
	}//end validate()

	/**
	 * Validate the trigger / offset / offsetUnit triplet of an escalationRule.
	 *
	 * @param array<string, mixed> $rule The escalationRule object.
	 * @param string $path The path prefix for any error.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	private function validateTiming(array $rule, string $path): array {
		$errors = [];

		$trigger = ($rule['trigger'] ?? null);
		if (is_string($trigger) === false || in_array($trigger, self::TRIGGERS, true) === false) {
			$errors[] = $this->error(
				path: $path . '.trigger',
				code: 'unknown_trigger',
				message: 'escalationRule.trigger must be one of: ' . implode(', ', self::TRIGGERS)
			);
		}

		$offset = ($rule['offset'] ?? null);
		if (is_int($offset) === false || $offset < 0) {
			$errors[] = $this->error(
				path: $path . '.offset',
				code: 'out_of_range',
				message: 'escalationRule.offset must be a non-negative integer'
			);
		}

		$offsetUnit = ($rule['offsetUnit'] ?? null);
		if (is_string($offsetUnit) === false
			|| in_array($offsetUnit, self::OFFSET_UNITS, true) === false
		) {
			$errors[] = $this->error(
				path: $path . '.offsetUnit',
				code: 'unknown_offset_unit',
				message: 'escalationRule.offsetUnit must be one of: ' . implode(', ', self::OFFSET_UNITS)
			);
		}

		return $errors;
	}//end validateTiming()

	/**
	 * Rule 7: a preBreach offset cannot exceed sla.value.
	 *
	 * @param array<string, mixed> $rule The escalationRule object.
	 * @param mixed $sla The raw sla value.
	 * @param string $path The path prefix for any error.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	private function validatePreBreachOffset(array $rule, mixed $sla, string $path): array {
		$trigger = ($rule['trigger'] ?? null);
		$offset = ($rule['offset'] ?? null);

		if ($trigger === 'preBreach'
			&& is_int($offset) === true
			&& is_array($sla) === true
			&& is_int(($sla['value'] ?? null)) === true
			&& $offset > $sla['value']
		) {
			return [
				$this->error(
					path: $path . '.offset',
					code: 'offset_exceeds_sla',
					message: 'escalationRule.offset must not exceed sla.value when trigger is preBreach'
				),
			];
		}

		return [];
	}//end validatePreBreachOffset()

	/**
	 * Rule 5: notifyRole + escalateToRole must resolve when roleTypes provided.
	 *
	 * @param array<string, mixed> $rule The escalationRule object.
	 * @param array<string, mixed> $roleTypes Map of role name/uuid to definition.
	 * @param string $path The path prefix for any error.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	private function validateRoles(array $rule, array $roleTypes, string $path): array {
		$errors = [];
		$checkRoles = ($roleTypes !== []);

		foreach (['notifyRole', 'escalateToRole'] as $roleKey) {
			$role = ($rule[$roleKey] ?? null);
			if ($role === null) {
				continue;
			}

			if (is_string($role) === false || $role === '') {
				$errors[] = $this->error(
					path: $path . '.' . $roleKey,
					code: 'malformed_role_reference',
					message: $roleKey . ' must be a non-empty role reference'
				);
				continue;
			}

			if ($checkRoles === true && array_key_exists($role, $roleTypes) === false) {
				$errors[] = $this->error(
					path: $path . '.' . $roleKey,
					code: 'unknown_role_reference',
					message: $roleKey . ' does not resolve to a roleType on the linked caseType'
				);
			}
		}//end foreach

		return $errors;
	}//end validateRoles()

	/**
	 * Build a structured error record.
	 *
	 * @param string $path The JSON-pointer-like path to the bad value.
	 * @param string $code The stable error code (snake_case).
	 * @param string $message An internal description (never user-facing).
	 *
	 * @return array{path: string, code: string, message: string}
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	private function error(string $path, string $code, string $message): array {
		return [
			'path' => $path,
			'code' => $code,
			'message' => $message,
		];
	}//end error()
}//end class
