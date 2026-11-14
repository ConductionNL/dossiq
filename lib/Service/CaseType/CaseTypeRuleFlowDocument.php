<?php

/**
 * The flow one case type rule action becomes, where no engine owns the action.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Kernel\AppIdentity;


/**
 * Builds a guarded flow: object trigger, filter, the action's step, end.
 *
 * Pure. The trigger carries the case type as its scope, so OpenRegister's rule
 * inventory lists the flow under this case type; the filter holds the case
 * type guard and the rule's conditions, so the scope is never the only thing
 * standing between a rule and another type's case.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class CaseTypeRuleFlowDocument {

	/**
	 * The prefix of the marker every rule flow carries in its notes.
	 *
	 * The marker is how the projector finds the flows of one case type again,
	 * to replace them on the next publish.
	 *
	 * @var string
	 */
	public const MARKER_PREFIX = 'dossiq:case-type-rule:';

	/**
	 * The trigger events per rule moment.
	 *
	 * @var array<string, string>
	 */
	private const EVENTS = [
		'created' => 'object.created',
		'saved' => 'object.updated',
		'statusEntered' => 'object.updated',
	];

	/**
	 * The edges every rule flow has: trigger, filter, action, end.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const EDGES = [
		['id' => 'trigger-conditions', 'from' => ['trigger'], 'to' => ['conditions']],
		['id' => 'conditions-action', 'from' => ['conditions'], 'to' => ['action']],
		['id' => 'action-end', 'from' => ['action'], 'to' => ['end']],
	];

	/**
	 * Constructor.
	 *
	 * @param RuleConditionLogic $logic Writes the filter's condition.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleConditionLogic $logic,
	) {
	}//end __construct()

	/**
	 * The marker of one rule's flows, or of all flows of a case type when no rule is given.
	 *
	 * @param string $caseTypeId The case type uuid.
	 * @param string $ruleId The rule id, or '' for the case type's prefix.
	 *
	 * @return string The marker.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function marker(string $caseTypeId, string $ruleId = ''): string {
		$marker = self::MARKER_PREFIX . $caseTypeId . ':';
		if ($ruleId === '') {
			return $marker;
		}

		return $marker . $ruleId;
	}//end marker()

	/**
	 * The flow document for one action of one rule, or null when the action has no flow step.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param array<string, mixed> $action One of its actions.
	 * @param RuleFlowScope        $scope  The case type the flow belongs to.
	 *
	 * @return array<string, mixed>|null The flow document.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-a-rules-effect-happens-once-per-rule-case-and-trigger-version
	 */
	public function build(array $rule, array $action, RuleFlowScope $scope): ?array {
		$step = $this->step(rule: $rule, action: $action, scope: $scope);
		$ruleId = (string)($rule['id'] ?? '');
		if ($step === null || $ruleId === '') {
			return null;
		}

		$conditions = $this->conditionsOf(rule: $rule, step: $step);
		$step['config'] = $this->actionConfig(step: $step, scope: $scope);

		return $this->header(rule: $rule, scope: $scope, ruleId: $ruleId) + [
			'nodes' => $this->nodes(rule: $rule, step: $step, scope: $scope, conditions: $conditions),
			'edges' => self::EDGES,
		];
	}//end build()

	/**
	 * The flow document's name, description, settings and marker.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param RuleFlowScope        $scope  The case type the flow belongs to.
	 * @param string               $ruleId The rule's id.
	 *
	 * @return array<string, mixed> The document fields before its nodes.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	private function header(array $rule, RuleFlowScope $scope, string $ruleId): array {
		return [
			'name' => $scope->caseTypeTitle . ': ' . $this->nameOf(rule: $rule),
			'description' => 'Projected from rule "' . $ruleId . '" of case type ' . $scope->caseTypeTitle
				. ' when the case type was published. Edit the rule on the case type, not this flow.',
			'app' => AppIdentity::APP_ID,
			'enabled' => true,
			'executionMode' => 'async',
			'notes' => $this->marker(caseTypeId: $scope->caseTypeId, ruleId: $ruleId),
		];
	}//end header()

	/**
	 * The flow's nodes: trigger, condition filter, action and end.
	 *
	 * @param array<string, mixed>                                                 $rule       The rule.
	 * @param array{type: string, config: array<string, mixed>, guard?: array<string, mixed>} $step       The action's step, with its config.
	 * @param RuleFlowScope                                                        $scope      The case type the flow belongs to.
	 * @param array<int|string, mixed>                                             $conditions The conditions the filter holds.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	private function nodes(array $rule, array $step, RuleFlowScope $scope, array $conditions): array {
		return [
			$this->trigger(rule: $rule, scope: $scope),
			[
				'id' => 'conditions',
				'type' => 'openregister.filter',
				'config' => ['condition' => $this->logic->forRule(caseTypeId: $scope->caseTypeId, conditions: $conditions)],
			],
			['id' => 'action', 'type' => $step['type'], 'config' => $step['config']],
			['id' => 'end', 'type' => 'openregister.end'],
		];
	}//end nodes()

	/**
	 * The rule's conditions, with its status and the step's guard added.
	 *
	 * @param array<string, mixed>                                                 $rule The rule.
	 * @param array{type: string, config: array<string, mixed>, guard?: array<string, mixed>} $step The action's step.
	 *
	 * @return array<int|string, mixed> The conditions the filter holds besides the case type guard.
	 */
	private function conditionsOf(array $rule, array $step): array {
		$when = (array)($rule['when'] ?? []);
		$conditions = (array)($rule['conditions'] ?? []);
		if (($when['event'] ?? null) === 'statusEntered') {
			$conditions[] = ['kind' => 'statusIs', 'status' => (string)($when['status'] ?? '')];
		}

		if (isset($step['guard']) === true) {
			$conditions[] = $step['guard'];
		}

		return $conditions;
	}//end conditionsOf()

	/**
	 * The step's config; an object write is aimed at the register and schema cases live in.
	 *
	 * @param array{type: string, config: array<string, mixed>} $step  The action's step.
	 * @param RuleFlowScope                                     $scope The case type the flow belongs to.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function actionConfig(array $step, RuleFlowScope $scope): array {
		if ($step['type'] !== 'openregister.object-write') {
			return $step['config'];
		}

		return array_merge(['register' => $scope->register, 'schema' => $scope->schema], $step['config']);
	}//end actionConfig()

	/**
	 * The rule's name, or its id when it has none.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return string The name.
	 */
	private function nameOf(array $rule): string {
		$name = trim((string)($rule['name'] ?? ''));
		if ($name === '') {
			return (string)($rule['id'] ?? '');
		}

		return $name;
	}//end nameOf()

	/**
	 * The trigger node: the rule's moment on a case of this case type.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param RuleFlowScope        $scope The case type the flow belongs to.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function trigger(array $rule, RuleFlowScope $scope): array {
		$when = (array)($rule['when'] ?? []);

		return [
			'id' => 'trigger',
			'type' => 'openregister.trigger-object',
			'config' => [
				'event' => (self::EVENTS[(string)($when['event'] ?? '')] ?? self::EVENTS['saved']),
				'register' => $scope->register,
				'schema' => $scope->schema,
				'scope' => ['property' => 'caseType', 'value' => $scope->caseTypeId],
			],
		];
	}//end trigger()

	/**
	 * The flow step an action becomes, or null when no flow step performs it.
	 *
	 * An added task carries a once-key of the rule, so the task is added once
	 * per case however often the case is saved with the condition still true.
	 * A written field carries a guard that it does not hold the value yet, so
	 * the write it causes does not fire the rule again.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param array<string, mixed> $action The action.
	 * @param RuleFlowScope        $scope  The case type the flow belongs to.
	 *
	 * @return array{type: string, config: array<string, mixed>, guard?: array<string, mixed>}|null The step.
	 */
	private function step(array $rule, array $action, RuleFlowScope $scope): ?array {
		return match ($action['action'] ?? null) {
			'addTask' => $this->addTask(rule: $rule, action: $action, caseTypeId: $scope->caseTypeId),
			'setField' => $this->writeField(
				field: trim((string)($action['field'] ?? '')),
				value: ($action['value'] ?? null),
				definitionNames: $scope->definitionNames
			),
			'sendMessage' => $this->sendMessage(rule: $rule, action: $action, caseTypeId: $scope->caseTypeId),
			'chooseVariant' => $this->writeField(field: 'variant', value: trim((string)($action['variant'] ?? '')), definitionNames: []),
			default => null,
		};
	}//end step()

	/**
	 * The create-task step, keyed once per rule and case.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param array<string, mixed> $action The action.
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array{type: string, config: array<string, mixed>}|null The step.
	 */
	private function addTask(array $rule, array $action, string $caseTypeId): ?array {
		$title = trim((string)($action['title'] ?? ''));
		if ($title === '') {
			return null;
		}

		$config = [
			'title' => $title,
			'onceKey' => 'rule:' . $caseTypeId . ':' . (string)($rule['id'] ?? ''),
		];
		$taskType = trim((string)($action['taskType'] ?? ''));
		if ($taskType !== '') {
			$config['workflowStepId'] = $taskType;
		}

		return ['type' => 'dossiq.createTask', 'config' => $config];
	}//end addTask()

	/**
	 * The send-message step: once per case, or once per status entry for a status rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param array<string, mixed> $action The action.
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array{type: string, config: array<string, mixed>}|null The step.
	 */
	private function sendMessage(array $rule, array $action, string $caseTypeId): ?array {
		$template = trim((string)($action['emailTemplate'] ?? ''));
		if ($template === '') {
			return null;
		}

		$config = [
			'emailTemplate' => $template,
			'onceKey' => 'rule:' . $caseTypeId . ':' . (string)($rule['id'] ?? ''),
		];
		$role = trim((string)($action['recipientRole'] ?? ''));
		if ($role !== '') {
			$config['recipientRole'] = $role;
		}

		if (($rule['when']['event'] ?? null) === 'statusEntered') {
			$config['oncePer'] = 'currentStatusEnteredAt';
		}

		return ['type' => 'dossiq.sendMessage', 'config' => $config];
	}//end sendMessage()

	/**
	 * A write of one field, guarded on the field not holding the value yet.
	 *
	 * A field of the case itself is an `openregister.object-write` update. A
	 * property definition's answer lives in the case's `properties` list, which
	 * a field patch cannot address, so it is a `dossiq.setCaseAnswer` step.
	 *
	 * @param string $field The case field or property definition name.
	 * @param mixed $value The value to write.
	 * @param array<int, string> $definitionNames The case type's property definition names.
	 *
	 * @return array{type: string, config: array<string, mixed>, guard: array<string, mixed>}|null The step.
	 */
	private function writeField(string $field, mixed $value, array $definitionNames): ?array {
		if ($field === '' || $value === '') {
			return null;
		}

		$guard = ['kind' => 'fieldDiffers', 'field' => $field, 'value' => $value];
		if (in_array($field, $definitionNames, true) === true) {
			return ['type' => 'dossiq.setCaseAnswer', 'config' => ['name' => $field, 'value' => $value], 'guard' => $guard];
		}

		return [
			'type' => 'openregister.object-write',
			'config' => [
				'operation' => 'update',
				'match' => [['property' => 'id', 'value' => '{{ id }}']],
				'fields' => [$field => $value],
			],
			'guard' => $guard,
		];
	}//end writeField()
}//end class
