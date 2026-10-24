<?php

/**
 * Projects a case type's rules onto the engines that own their actions.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Run at publish, next to the status field rule projection.
 *
 * Dossiq evaluates no rule. An action no engine performs on its own (add a
 * task) becomes a guarded flow in OpenRegister's flow engine, one per action,
 * owned by the publisher and published at once. The flows of a case type are
 * replaced on every publish: the ones a rule no longer declares are deleted,
 * so a rule switched off or removed stops acting.
 *
 * An action this projection does not carry yet is named in the warnings
 * publication returns, so a rule that would do nothing never reads as active.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class CaseTypeRuleProjector {

	/**
	 * Flows read per page when looking for a case type's rule flows.
	 *
	 * @var integer
	 */
	private const FLOW_PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore $store Reads the case type.
	 * @param SettingsService $settings The register and case schema.
	 * @param CaseTypeRuleFlowDocument $documents Builds the flow of one action.
	 * @param ContainerInterface $container OpenRegister's flow services.
	 * @param LoggerInterface $logger The logger.
	 * @param CaseTypeResolver|null $resolver The case type's blueprint, for its property definition names.
	 * @param RuleConditionLogic $logic Writes a requirement's condition.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly SettingsService $settings,
		private readonly CaseTypeRuleFlowDocument $documents,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?CaseTypeResolver $resolver = null,
		private readonly RuleConditionLogic $logic = new RuleConditionLogic(),
	) {
	}//end __construct()

	/**
	 * The flow documents a case type's enabled rules project to, and the actions left over.
	 *
	 * Pure apart from reading the case type and the settings.
	 *
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array{flows: array<int, array<string, mixed>>, unprojected: array<int, string>} The plan.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function plan(string $caseTypeId): array {
		$caseType = $this->store->readCaseType(caseTypeId: $caseTypeId);
		$title = (string)($caseType['title'] ?? $caseTypeId);
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_schema');

		$definitionNames = $this->definitionNames(caseTypeId: $caseTypeId);
		$flows = [];
		$unprojected = [];
		foreach ((array)($caseType['rules'] ?? []) as $rule) {
			if (is_array($rule) === false || ($rule['enabled'] ?? true) === false) {
				continue;
			}

			foreach ((array)($rule['actions'] ?? []) as $action) {
				if ($this->entryRequirementOf(rule: $rule, action: (array)$action, caseTypeId: $caseTypeId) !== null) {
					continue;
				}

				$document = $this->documents->build(
					rule: $rule,
					action: (array)$action,
					caseTypeId: $caseTypeId,
					caseTypeTitle: $title,
					register: $register,
					schema: $schema,
					definitionNames: $definitionNames
				);
				if ($document === null) {
					$unprojected[] = (string)($rule['id'] ?? '?') . ': ' . (string)(((array)$action)['action'] ?? '?');
					continue;
				}

				$flows[] = $document;
			}
		}

		return ['flows' => $flows, 'unprojected' => $unprojected];
	}//end plan()

	/**
	 * What each status requires before a case enters it, from the case type's rules.
	 *
	 * Written onto the case type at publish as `entryRequirements`, keyed by
	 * statusType uuid, and read by dossiq's StatusEntryRequirementsGuard. A rule
	 * on entering a status becomes a requirement; its other conditions travel
	 * with it as JSONLogic, so it applies only while they hold.
	 *
	 * @param array<string, mixed> $caseType The case type, with its rules.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The requirements.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function entryRequirements(array $caseType): array {
		$requirements = [];
		foreach ((array)($caseType['rules'] ?? []) as $rule) {
			if (is_array($rule) === false || ($rule['enabled'] ?? true) === false) {
				continue;
			}

			foreach ((array)($rule['actions'] ?? []) as $action) {
				$requirement = $this->entryRequirementOf(
					rule: $rule,
					action: (array)$action,
					caseTypeId: (string)($caseType['id'] ?? '')
				);
				if ($requirement !== null) {
					$requirements[(string)$rule['when']['status']][] = $requirement;
				}
			}
		}

		return $requirements;
	}//end entryRequirements()

	/**
	 * The entry requirement one action is, or null when it is not one.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param array<string, mixed> $action The action.
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array{documentType: string, rule: string, condition?: array<string, mixed>}|null The requirement.
	 */
	private function entryRequirementOf(array $rule, array $action, string $caseTypeId): ?array {
		$documentType = trim((string)($action['documentType'] ?? ''));
		$status = trim((string)($rule['when']['status'] ?? ''));
		if (($action['action'] ?? null) !== 'requireDocument' || $documentType === '' || $status === ''
			|| ($rule['when']['event'] ?? null) !== 'statusEntered'
		) {
			return null;
		}

		$requirement = ['documentType' => $documentType, 'rule' => (string)($rule['id'] ?? '')];
		$conditions = (array)($rule['conditions'] ?? []);
		if ($conditions !== []) {
			$requirement['condition'] = $this->logic->forRule(caseTypeId: $caseTypeId, conditions: $conditions);
		}

		return $requirement;
	}//end entryRequirementOf()

	/**
	 * The names of the case type's property definitions, inherited ones included.
	 *
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array<int, string> The names.
	 */
	private function definitionNames(string $caseTypeId): array {
		$rows = (array)($this->resolver?->blueprintFor(caseTypeId: $caseTypeId)['propertyDefinitions'] ?? []);

		return array_values(array_filter(array_map(static fn ($row): string => (string)(((array)$row)['name'] ?? ''), $rows)));
	}//end definitionNames()

	/**
	 * Replace the case type's rule flows with the ones its rules declare now.
	 *
	 * Never raises: a case type publishes even when the flow engine cannot
	 * take the flows. What did not land is returned as warnings.
	 *
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array<int, string> Warnings for publication, empty when every rule landed.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function publish(string $caseTypeId): array {
		$plan = $this->plan(caseTypeId: $caseTypeId);
		$warnings = [];
		foreach ($plan['unprojected'] as $action) {
			$warnings[] = 'Rule action ' . $action . ' is not carried out: it names no target, or a required document '
				. 'names no status to enter.';
		}

		$flowService = $this->service(name: 'OCA\OpenRegister\Service\Flow\FlowService');
		if ($flowService === null) {
			if ($plan['flows'] !== []) {
				$warnings[] = 'The rules were not published: this instance has no OpenRegister flow engine.';
			}

			return $warnings;
		}

		foreach ($this->existing(flowService: $flowService, caseTypeId: $caseTypeId) as $uuid) {
			try {
				$flowService->delete($uuid);
			} catch (Throwable $e) {
				$warnings[] = 'An earlier rule flow could not be removed: ' . $e->getMessage();
			}
		}

		foreach ($plan['flows'] as $document) {
			$warning = $this->create(flowService: $flowService, document: $document);
			if ($warning !== null) {
				$warnings[] = $warning;
			}
		}

		return $warnings;
	}//end publish()

	/**
	 * Store and publish one rule flow.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param array<string, mixed> $document The flow document.
	 *
	 * @return string|null A warning, or null when the flow is live.
	 */
	private function create(object $flowService, array $document): ?string {
		try {
			$flow = $flowService->save($document, null);
			$this->service(name: 'OCA\OpenRegister\Service\Flow\FlowVersionService')?->publish($flow);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a case type rule flow could not be published',
				['app' => Application::APP_ID, 'flow' => $document['notes'] ?? '', 'exception' => $e->getMessage()]
			);
			return 'Rule ' . (string)($document['name'] ?? '') . ' was not published: ' . $e->getMessage();
		}

		return null;
	}//end create()

	/**
	 * The uuids of the flows this case type's rules wrote before.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return array<int, string> The flow uuids.
	 */
	private function existing(object $flowService, string $caseTypeId): array {
		$prefix = $this->documents->marker(caseTypeId: $caseTypeId);
		$uuids = [];
		$offset = 0;
		$more = true;

		while ($more === true) {
			$page = $flowService->findAll(Application::APP_ID, null, null, self::FLOW_PAGE, $offset);
			if (is_array($page) === false) {
				break;
			}

			foreach ($page as $flow) {
				if (str_starts_with((string)($flow->getNotes() ?? ''), $prefix) === true) {
					$uuids[] = (string)$flow->getUuid();
				}
			}

			$more = (count($page) === self::FLOW_PAGE);
			$offset += self::FLOW_PAGE;
		}

		return $uuids;
	}//end existing()

	/**
	 * One of OpenRegister's services, or null when this instance has none.
	 *
	 * @param string $name The class name.
	 *
	 * @return object|null The service.
	 */
	private function service(string $name): ?object {
		if ($this->container->has($name) === false) {
			return null;
		}

		$service = $this->container->get($name);

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end service()
}//end class
