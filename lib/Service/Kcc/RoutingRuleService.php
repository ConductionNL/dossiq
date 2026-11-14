<?php

/**
 * Dossiq KCC Routing Rule Service
 *
 * Loads and persists KCC routing rules and KCC agents from OpenRegister, and
 * produces a routing decision plus ranked agent suggestions for a contact
 * moment. Rule evaluation goes through {@see RoutingTableEvaluator} — the
 * rules compiled onto OpenRegister's shared decision-table evaluator
 * (openregister#3329) — while agent ranking stays on the pure
 * {@see RoutingEngine}, which is KCC domain logic, not a rule engine.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use OCA\Dossiq\Kernel\Contracts\RoutingRuleServiceInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SerialisedResult;
use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Persists routing rules / agents and drives the routing engine.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
 */
class RoutingRuleService implements RoutingRuleServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param RoutingEngine $routingEngine The pure agent-ranking engine (and the
	 *                                     parity oracle for rule evaluation
	 *                                     during its staged retirement).
	 * @param RoutingTableEvaluator $tableRouting Rule evaluation on the shared
	 *                                            decision-table engine.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private RoutingEngine $routingEngine,
		private RoutingTableEvaluator $tableRouting,
	) {
	}//end __construct()

	/**
	 * List all routing rules.
	 *
	 * @return array<int, array<string, mixed>> The routing rules.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function listRules(): array {
		[$objectService, $register, $schema] = $this->resolve(schemaKey: 'routing_rule_schema');
		$results = $objectService->findAll(['filters' => ['register' => (int)$register, 'schema' => (int)$schema]]);
		return array_map([$this, 'toArray'], $results);
	}//end listRules()

	/**
	 * Create a routing rule.
	 *
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function createRule(array $data): array {
		$payload = $this->validateRule(data: $data);
		[$objectService, $register, $schema] = $this->resolve(schemaKey: 'routing_rule_schema');
		return $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $schema));
	}//end createRule()

	/**
	 * Update a routing rule.
	 *
	 * @param string $id The rule id.
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws OCSBadRequestException When validation fails or not found.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function updateRule(string $id, array $data): array {
		$payload = $this->validateRule(data: $data);
		[$objectService, $register, $schema] = $this->resolve(schemaKey: 'routing_rule_schema');
		return $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $schema, uuid: (string)$id));
	}//end updateRule()

	/**
	 * Delete a routing rule.
	 *
	 * @param string $id The rule id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function deleteRule(string $id): void {
		[$objectService, $register, $schema] = $this->resolve(schemaKey: 'routing_rule_schema');
		$objectService->deleteObject(uuid: $id, register: $register, schema: $schema);
	}//end deleteRule()

	/**
	 * Evaluate routing for a contact moment and rank candidate agents.
	 *
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param \DateTimeImmutable|null $now Reference time.
	 *
	 * @return array<string, mixed> The routing decision plus agent suggestions.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine
	 */
	public function route(array $contactMoment, ?\DateTimeImmutable $now = null): array {
		$rules = $this->listRules();
		$routing = $this->tableRouting->route(rules: $rules, contactMoment: $contactMoment, now: $now);

		if ($routing === null) {
			return ['matched' => false, 'suggestedAgents' => []];
		}

		$team = (string)($routing['assignedTeam'] ?? '');
		$agents = $this->listAgents();
		$ranked = $this->rankPrimaryTeam(agents: $agents, team: $team, contactMoment: $contactMoment, routing: $routing);

		[$ranked, $escalated] = $this->withEscalation(ranked: $ranked, agents: $agents, contactMoment: $contactMoment, routing: $routing);

		return $this->routingDecision(routing: $routing, team: $team, escalated: $escalated, ranked: $ranked);
	}//end route()

	/**
	 * Rank the agents of the routed team for the contact moment.
	 *
	 * @param array<int, array<string, mixed>> $agents The agents.
	 * @param string $team The routed team.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param array<string, mixed> $routing The routing decision.
	 *
	 * @return array<int, array<string, mixed>> The ranked agents.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine
	 */
	private function rankPrimaryTeam(array $agents, string $team, array $contactMoment, array $routing): array {
		return $this->routingEngine->rankAgents(
			agents: $agents,
			team: $team,
			contactMoment: array_merge($contactMoment, ['assignedDomain' => ($routing['assignedDomain'] ?? '')]),
		);
	}//end rankPrimaryTeam()

	/**
	 * Fall back to the escalation team when nobody in the primary team is available.
	 *
	 * @param array<int, array<string, mixed>> $ranked The primary team's ranking.
	 * @param array<int, array<string, mixed>> $agents The agents.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param array<string, mixed> $routing The routing decision.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: bool} The ranking, and whether it escalated.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine
	 */
	private function withEscalation(array $ranked, array $agents, array $contactMoment, array $routing): array {
		// Escalation: if no agent is available in the primary team, fall back
		// to the configured escalation team.
		$escalated = false;
		if ($ranked === [] && ((string)($routing['escalationTeam'] ?? '')) !== '') {
			$ranked = $this->routingEngine->rankAgents(
				agents: $agents,
				team: (string)$routing['escalationTeam'],
				contactMoment: $contactMoment,
			);
			$escalated = true;
		}

		return [$ranked, $escalated];
	}//end withEscalation()

	/**
	 * The routing decision with its agent suggestions.
	 *
	 * @param array<string, mixed> $routing The routing decision.
	 * @param string $team The routed team.
	 * @param bool $escalated Whether the escalation team was used.
	 * @param array<int, array<string, mixed>> $ranked The ranked agents.
	 *
	 * @return array<string, mixed> The routing decision plus agent suggestions.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine
	 */
	private function routingDecision(array $routing, string $team, bool $escalated, array $ranked): array {
		return [
			'matched' => true,
			'assignedDomain' => ($routing['assignedDomain'] ?? ''),
			'assignedTeam' => $team,
			'rule' => ($routing['rule'] ?? ''),
			'escalated' => $escalated,
			'suggestedAgents' => $ranked,
		];
	}//end routingDecision()

	/**
	 * List all KCC agents.
	 *
	 * @return array<int, array<string, mixed>> The agents.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine
	 */
	public function listAgents(): array {
		[$objectService, $register, $schema] = $this->resolve(schemaKey: 'kcc_agent_schema');
		$results = $objectService->findAll(['filters' => ['register' => (int)$register, 'schema' => (int)$schema]]);
		return array_map([$this, 'toArray'], $results);
	}//end listAgents()

	/**
	 * Validate and normalise a routing rule payload.
	 *
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return array<string, mixed> The validated payload.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 */
	private function validateRule(array $data): array {
		$name = $this->requiredRuleName(data: $data);

		$valid = $this->validConditions(data: $data);

		return [
			'name' => $name,
			'priority' => (int)($data['priority'] ?? 0),
			'matchConditions' => $valid,
			'assignedDomain' => trim((string)($data['assignedDomain'] ?? '')),
			'assignedTeam' => trim((string)($data['assignedTeam'] ?? '')),
			'escalationTeam' => trim((string)($data['escalationTeam'] ?? '')),
			'enabled' => (bool)($data['enabled'] ?? true),
		];
	}//end validateRule()

	/**
	 * The rule's name, refusing an empty one.
	 *
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return string The trimmed name.
	 *
	 * @throws OCSBadRequestException When the name is empty.
	 */
	private function requiredRuleName(array $data): string {
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			throw new OCSBadRequestException('Rule name is required');
		}

		return $name;
	}//end requiredRuleName()

	/**
	 * The rule's match conditions, validated.
	 *
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return array<int, array{type: string, value: string}> The conditions.
	 *
	 * @throws OCSBadRequestException When the conditions are no array or a type is unknown.
	 */
	private function validConditions(array $data): array {
		$conditions = ($data['matchConditions'] ?? []);
		if (is_array($conditions) === false) {
			throw new OCSBadRequestException('matchConditions must be an array');
		}

		$valid = [];
		foreach ($conditions as $condition) {
			if (is_array($condition) === false) {
				continue;
			}

			$valid[] = $this->validCondition(condition: $condition);
		}

		return $valid;
	}//end validConditions()

	/**
	 * One match condition, validated.
	 *
	 * @param array<string, mixed> $condition The condition.
	 *
	 * @return array{type: string, value: string} The condition.
	 *
	 * @throws OCSBadRequestException When the type is unknown.
	 */
	private function validCondition(array $condition): array {
		$type = (string)($condition['type'] ?? '');
		if (in_array($type, ['keyword', 'regex', 'channel', 'customer_type', 'time_of_day', 'day_of_week'], true) === false) {
			throw new OCSBadRequestException('Invalid condition type: ' . $type);
		}

		return ['type' => $type, 'value' => (string)($condition['value'] ?? '')];
	}//end validCondition()

	/**
	 * Resolve the ObjectService and register/schema identifiers.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(string $schemaKey): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);

		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('KCC schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Normalise an ObjectService result to a plain array.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return array<string, mixed> The normalised array.
	 */
	private function toArray(mixed $value): array {
		return SerialisedResult::arrayOf(value: $value);
	}//end toArray()
}//end class
