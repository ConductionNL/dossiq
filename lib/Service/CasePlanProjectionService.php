<?php

/**
 * Project a dossiq `caseModel` onto OpenRegister's case layer.
 *
 * The app keeps `caseModel` as authoring data and stops keeping a runtime for
 * it. OpenRegister has no `caseModel` entity: a case's plan items are rows
 * created for that case. So the definition is projected at case start, once,
 * into `CasePlanService::createPlan()`, and every later read and transition
 * goes to OpenRegister.
 *
 * THIS IS A MAPPING, NOT AN ENGINE. Nothing here decides a lifecycle, fires a
 * sentry or cascades. It translates one vocabulary into another and refuses
 * what it cannot translate, in the shape of
 * {@see \OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator}.
 *
 * WHY IT DOES NOT USE `Service\Cmmn\CaseModelLoader`. That whole namespace
 * retires with the engine (retire-cmmn-caseplanstate tasks 3.1), and the
 * structural guard in 4.2 fails on any surviving reference to it. A bridge
 * built on a class scheduled for deletion would have to be rewritten at the
 * moment the removal lands, so it resolves the published model itself.
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Converts a published `caseModel` into an OpenRegister case-plan definition
 * and creates the plan for one case.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity IS the
 * refusals. Every branch here is one shape the projection declines to guess
 * at, each with its own message naming the construct, because design.md
 * section 2 calls the conversion tables closed. OpenRegister's own
 * `CasePlanDefinition` carries the same suppression for the same reason.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
class CasePlanProjectionService {

	/**
	 * OpenRegister's case layer, resolved by name.
	 *
	 * Named as a constant because getting it wrong is SILENT: the container
	 * throws, the projection reports "OpenRegister exposes no case layer" and
	 * the case starts with no plan, which reads as "this caseType has no
	 * model" rather than "I looked in the wrong place". The sibling
	 * {@see \OCA\Dossiq\Service\Support\ProjectsOntoFlows} lost its
	 * `\Flow\` segment exactly that way.
	 *
	 * @var string
	 */
	public const CASE_PLAN_SERVICE = 'OCA\\OpenRegister\\Service\\Case\\CasePlanService';

	/**
	 * The closed if-part operator table (design.md section 2).
	 *
	 * Closed on purpose. An operator outside it is refused by name rather
	 * than approximated, because an approximated sentry fires at the wrong
	 * moment and nothing reports it.
	 *
	 * @var array<string, string>
	 */
	public const OPERATORS = [
		'eq' => '==',
		'neq' => '!=',
		'gt' => '>',
		'gte' => '>=',
		'lt' => '<',
		'lte' => '<=',
		'in' => 'in',
		'notIn' => 'notIn',
		'truthy' => '!!',
		'falsy' => '!',
	];

	/**
	 * dossiq `onPart.standardEvent` to the OpenRegister flow event catalog.
	 *
	 * @var array<string, string>
	 */
	public const STANDARD_EVENTS = [
		'complete' => 'case.item.completed',
		'terminate' => 'case.item.terminated',
		'disable' => 'case.item.disabled',
	];

	/**
	 * The catalog event an ordinary write to the case object dispatches.
	 *
	 * A dossiq `onPart.caseFileItem` watched a named slot inside the blob.
	 * OpenRegister has no case file: the slots become declared properties on
	 * the case object, and a write to them is an object write. Both
	 * `caseFileEvent` values map here, because OpenRegister does not separate
	 * a first write from a later one; the fidelity note is in the class docs
	 * of the change's design.md section 2.
	 *
	 * @var string
	 */
	public const OBJECT_WRITE_EVENT = 'object.updated';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and config.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Convert a whole `caseModel` into an OpenRegister case-plan definition.
	 *
	 * Pure: no I/O, no container. The flat `planItems` list with `parentId`
	 * links becomes the nested `items` tree OpenRegister validates, and every
	 * sentry is converted through the closed table.
	 *
	 * @param array<string, mixed> $caseModel The published caseModel object.
	 *
	 * @return array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} The definition.
	 *
	 * @throws UnexpectedValueException Naming the construct it refuses to convert.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertModel(array $caseModel): array {
		$planItems = ($caseModel['planItems'] ?? []);
		if (is_array($planItems) === false) {
			throw new UnexpectedValueException('caseModel.planItems must be a list of plan items.');
		}

		$byId = [];
		$order = [];
		foreach ($planItems as $index => $item) {
			if (is_array($item) === false) {
				throw new UnexpectedValueException(sprintf('caseModel.planItems[%d] must be an object.', (int)$index));
			}

			$id = trim((string)($item['id'] ?? ''));
			if ($id === '') {
				throw new UnexpectedValueException(sprintf('caseModel.planItems[%d] has no id.', (int)$index));
			}

			if (isset($byId[$id]) === true) {
				throw new UnexpectedValueException(sprintf("caseModel repeats plan-item id '%s'; ids are unique within a model.", $id));
			}

			$byId[$id] = $item;
			$order[] = $id;
		}

		$this->assertParentsResolve(byId: $byId);

		$items = [];
		foreach ($order as $id) {
			if (trim((string)($byId[$id]['parentId'] ?? '')) === '') {
				$items[] = $this->convertItem(item: $byId[$id], byId: $byId, order: $order, seen: []);
			}
		}

		return ['settings' => $this->convertSettings(caseModel: $caseModel), 'items' => $items];
	}//end convertModel()

	/**
	 * Convert one dossiq sentry into an OpenRegister sentry.
	 *
	 * @param array<string, mixed> $sentry The dossiq `{id?, onPart?, ifPart?}` sentry.
	 * @param string               $where  Which criteria list, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister `{id?, on?, if?}` sentry.
	 *
	 * @throws UnexpectedValueException Naming the part it refuses.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertSentry(array $sentry, string $where): array {
		$converted = [];
		$id = trim((string)($sentry['id'] ?? ''));
		if ($id !== '') {
			$converted['id'] = $id;
		}

		$onPart = ($sentry['onPart'] ?? null);
		if (is_array($onPart) === true && $onPart !== []) {
			$converted['on'] = $this->convertOnPart(onPart: $onPart, where: $where);
		}

		$ifPart = ($sentry['ifPart'] ?? null);
		if (is_array($ifPart) === true && $ifPart !== []) {
			$converted['if'] = $this->convertIfPart(ifPart: $ifPart, where: $where);
		}

		if ($converted === [] || (isset($converted['on']) === false && isset($converted['if']) === false)) {
			throw new UnexpectedValueException(sprintf('%s has neither an onPart nor an ifPart; there is nothing to convert.', $where));
		}

		return $converted;
	}//end convertSentry()

	/**
	 * Convert a dossiq `{field, operator, value}` if-part into JSONLogic.
	 *
	 * The table is closed (design.md section 2). An operator it does not name
	 * is refused, never guessed: `FlowExpression::isTrue()` answers false for
	 * an expression it cannot evaluate, so a mistranslated condition would
	 * look exactly like a sentry that has not fired yet.
	 *
	 * @param array<string, mixed> $ifPart The dossiq if-part.
	 * @param string               $where  Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The JSONLogic rule.
	 *
	 * @throws UnexpectedValueException Naming the operator or the missing field.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertIfPart(array $ifPart, string $where): array {
		$field = trim((string)($ifPart['field'] ?? ''));
		if ($field === '') {
			throw new UnexpectedValueException(sprintf('%s has an ifPart with no field.', $where));
		}

		$operator = (string)($ifPart['operator'] ?? 'eq');
		if (array_key_exists($operator, self::OPERATORS) === false) {
			throw new UnexpectedValueException(
				sprintf(
					"%s uses operator '%s', which is outside the conversion table (%s).",
					$where,
					$operator,
					implode(', ', array_keys(self::OPERATORS))
				)
			);
		}

		$value = ($ifPart['value'] ?? null);
		$variable = ['var' => $field];

		return match ($operator) {
			'truthy' => ['!!' => $variable],
			'falsy' => ['!' => $variable],
			'in' => ['in' => [$variable, $value]],
			'notIn' => ['!' => ['in' => [$variable, $value]]],
			default => [self::OPERATORS[$operator] => [$variable, $value]],
		};
	}//end convertIfPart()

	/**
	 * Project a case by id, resolving its caseType first.
	 *
	 * The entry point the case-created listener calls, so the listener stays
	 * a pure observer and every decision lives here (ADR-022).
	 *
	 * @param string $caseId     The case object uuid.
	 * @param string $caseTypeId The caseType uuid the case names.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function projectForCase(string $caseId, string $caseTypeId): array {
		$caseType = $this->findCaseType(caseTypeId: $caseTypeId);
		if ($caseType === null) {
			return ['projected' => false, 'reason' => 'case_type_not_found'];
		}

		return $this->projectAtCaseStart(caseId: $caseId, caseType: $caseType);
	}//end projectForCase()

	/**
	 * Project the published caseModel of a CMMN-managed case onto OpenRegister.
	 *
	 * Called at case start. Does nothing and says so when the caseType is not
	 * CMMN-managed, when no model is published (the `CaseModelLoader` rule:
	 * no published model means an empty plan) or when OpenRegister already
	 * holds a plan for this case, so a re-run never mints a second plan.
	 *
	 * @param string               $caseId   The case object uuid.
	 * @param array<string, mixed> $caseType The caseType object.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function projectAtCaseStart(string $caseId, array $caseType): array {
		if ((string)($caseType['handlingModel'] ?? 'bpmn') !== 'cmmn') {
			return ['projected' => false, 'reason' => 'case_not_cmmn_managed'];
		}

		$plans = $this->settingsService->getOpenRegisterClass(class: self::CASE_PLAN_SERVICE);
		if ($plans === null) {
			$this->logger->warning('CasePlanProjectionService: OpenRegister exposes no case layer', ['caseId' => $caseId]);
			return ['projected' => false, 'reason' => 'case_layer_unavailable'];
		}

		$caseTypeId = trim((string)($caseType['id'] ?? $caseType['uuid'] ?? ''));
		$model = $this->findPublishedModel(caseTypeId: $caseTypeId);
		if ($model === null) {
			return ['projected' => false, 'reason' => 'no_published_case_model'];
		}

		try {
			$definition = $this->convertModel(caseModel: $model);
		} catch (UnexpectedValueException $e) {
			$this->logger->error(
				'CasePlanProjectionService: refused to project a caseModel',
				['caseId' => $caseId, 'caseType' => $caseTypeId, 'reason' => $e->getMessage()]
			);
			return ['projected' => false, 'reason' => $e->getMessage()];
		}

		return $this->createPlan(plans: $plans, caseId: $caseId, definition: $definition);
	}//end projectAtCaseStart()

	/**
	 * Hand the converted definition to OpenRegister's case layer.
	 *
	 * @param object                                                                       $plans      The resolved CasePlanService.
	 * @param string                                                                       $caseId     The case object uuid.
	 * @param array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} $definition The converted definition.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 */
	private function createPlan(object $plans, string $caseId, array $definition): array {
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		$registerId = null;
		if ($register !== '') {
			$registerId = (int)$register;
		}

		$schemaId = null;
		if ($schema !== '') {
			$schemaId = (int)$schema;
		}

		try {
			$plans->createPlan(
				objectUuid: $caseId,
				registerId: $registerId,
				schemaId: $schemaId,
				definition: $definition,
				uid: null,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CasePlanProjectionService: OpenRegister refused the projected plan',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return ['projected' => false, 'reason' => $e->getMessage()];
		}

		return ['projected' => true, 'reason' => 'projected'];
	}//end createPlan()

	/**
	 * Convert one plan item and, for a stage, its children.
	 *
	 * @param array<string, mixed>                      $item  The dossiq plan item.
	 * @param array<string, array<string, mixed>>       $byId  Every plan item, keyed by id.
	 * @param array<int, string>                        $order The declared order of ids.
	 * @param array<string, boolean>                    $seen  Ancestor ids, to catch a parent cycle.
	 *
	 * @return array<string, mixed> The OpenRegister node.
	 *
	 * @throws UnexpectedValueException Naming the item it refuses.
	 */
	private function convertItem(array $item, array $byId, array $order, array $seen): array {
		$id = trim((string)($item['id'] ?? ''));
		if (isset($seen[$id]) === true) {
			throw new UnexpectedValueException(sprintf("caseModel plan item '%s' is its own ancestor.", $id));
		}

		$seen[$id] = true;
		$node = $this->nodeFor(item: $item, id: $id);
		if ($node['type'] !== 'stage') {
			return $node;
		}

		$node['children'] = $this->childrenOf(id: $id, byId: $byId, order: $order, seen: $seen);

		return $node;
	}//end convertItem()

	/**
	 * Build the OpenRegister node for one plan item, children aside.
	 *
	 * @param array<string, mixed> $item The dossiq plan item.
	 * @param string               $id   Its id.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @throws UnexpectedValueException Naming the item it refuses.
	 */
	private function nodeFor(array $item, string $id): array {
		$type = (string)($item['type'] ?? '');
		if (in_array($type, ['stage', 'humanTask', 'milestone'], true) === false) {
			throw new UnexpectedValueException(
				sprintf("caseModel plan item '%s' has type '%s'; expected stage, humanTask or milestone.", $id, $type)
			);
		}

		$discretionary = (($item['discretionary'] ?? false) === true);
		if ($type === 'milestone' && $discretionary === true) {
			throw new UnexpectedValueException(
				sprintf("caseModel plan item '%s' is a discretionary milestone, which the case layer refuses: a milestone is never enabled.", $id)
			);
		}

		$node = [
			'key' => $id,
			'type' => $type,
			'name' => (string)($item['name'] ?? $id),
			// The mandatory/discretionary split IS OpenRegister's `required`:
			// PlanItemTree waits for every non-discretionary direct child,
			// CasePlanTree waits for every required one.
			'required' => ($discretionary === false),
			'discretionary' => $discretionary,
			'entryCriteria' => $this->convertCriteria(criteria: ($item['entryCriteria'] ?? []), where: sprintf("plan item '%s' entry", $id)),
			'exitCriteria' => $this->convertCriteria(criteria: ($item['exitCriteria'] ?? []), where: sprintf("plan item '%s' exit", $id)),
		];

		$description = trim((string)($item['description'] ?? ''));
		if ($description !== '') {
			$node['description'] = $description;
		}

		$authorization = ($item['authorization'] ?? []);
		if (is_array($authorization) === true && $authorization !== []) {
			$node['authorization'] = array_values(array_map(static fn ($rule): string => (string)$rule, $authorization));
		}

		return $node;
	}//end nodeFor()

	/**
	 * Convert the direct children of a stage, in declared order.
	 *
	 * @param string                              $id    The stage's id.
	 * @param array<string, array<string, mixed>> $byId  Every plan item, keyed by id.
	 * @param array<int, string>                  $order The declared order of ids.
	 * @param array<string, boolean>              $seen  Ancestor ids, to catch a parent cycle.
	 *
	 * @return array<int, array<string, mixed>> The child nodes.
	 *
	 * @throws UnexpectedValueException Naming the child it refuses.
	 */
	private function childrenOf(string $id, array $byId, array $order, array $seen): array {
		$children = [];
		foreach ($order as $childId) {
			if (trim((string)($byId[$childId]['parentId'] ?? '')) === $id) {
				$children[] = $this->convertItem(item: $byId[$childId], byId: $byId, order: $order, seen: $seen);
			}
		}

		return $children;
	}//end childrenOf()

	/**
	 * Convert a criteria list, dropping nothing and refusing what it cannot map.
	 *
	 * @param mixed  $criteria The dossiq criteria list.
	 * @param string $where    Which list, for the refusal message.
	 *
	 * @return array<int, array<string, mixed>> The converted sentries.
	 *
	 * @throws UnexpectedValueException Naming the sentry it refuses.
	 */
	private function convertCriteria(mixed $criteria, string $where): array {
		if (is_array($criteria) === false || $criteria === []) {
			return [];
		}

		$converted = [];
		foreach ($criteria as $index => $sentry) {
			if (is_array($sentry) === false) {
				throw new UnexpectedValueException(sprintf('%s sentry #%d is not an object.', $where, (int)$index + 1));
			}

			$converted[] = $this->convertSentry(sentry: $sentry, where: sprintf('%s sentry #%d', $where, (int)$index + 1));
		}

		return $converted;
	}//end convertCriteria()

	/**
	 * Convert a dossiq on-part into an OpenRegister on-part.
	 *
	 * @param array<string, mixed> $onPart The dossiq on-part.
	 * @param string               $where  Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister on-part.
	 *
	 * @throws UnexpectedValueException Naming the event it refuses.
	 */
	private function convertOnPart(array $onPart, string $where): array {
		$planItem = trim((string)($onPart['planItem'] ?? ''));
		if ($planItem !== '') {
			$standardEvent = (string)($onPart['standardEvent'] ?? '');
			if (array_key_exists($standardEvent, self::STANDARD_EVENTS) === false) {
				throw new UnexpectedValueException(
					sprintf(
						"%s names standardEvent '%s', which is outside the conversion table (%s).",
						$where,
						$standardEvent,
						implode(', ', array_keys(self::STANDARD_EVENTS))
					)
				);
			}

			return ['event' => self::STANDARD_EVENTS[$standardEvent], 'item' => $planItem];
		}

		$caseFileItem = trim((string)($onPart['caseFileItem'] ?? ''));
		if ($caseFileItem !== '') {
			$caseFileEvent = (string)($onPart['caseFileEvent'] ?? 'set');
			if (in_array($caseFileEvent, ['set', 'changed'], true) === false) {
				throw new UnexpectedValueException(
					sprintf("%s names caseFileEvent '%s', which is outside the conversion table (set, changed).", $where, $caseFileEvent)
				);
			}

			return ['event' => self::OBJECT_WRITE_EVENT];
		}

		throw new UnexpectedValueException(sprintf('%s has an onPart naming neither a planItem nor a caseFileItem.', $where));
	}//end convertOnPart()

	/**
	 * Build the definition's `settings` block.
	 *
	 * @param array<string, mixed> $caseModel The caseModel object.
	 *
	 * @return array<string, mixed> The settings.
	 */
	private function convertSettings(array $caseModel): array {
		$settings = [];
		$title = trim((string)($caseModel['title'] ?? ''));
		if ($title !== '') {
			$settings['name'] = $title;
		}

		return $settings;
	}//end convertSettings()

	/**
	 * Refuse a `parentId` that names an item the model does not declare.
	 *
	 * A dangling parent would otherwise drop the child out of the tree
	 * silently: it is neither a root (it has a parentId) nor anybody's child.
	 *
	 * @param array<string, array<string, mixed>> $byId Every plan item, keyed by id.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException Naming the dangling parent.
	 */
	private function assertParentsResolve(array $byId): void {
		foreach ($byId as $id => $item) {
			$parentId = trim((string)($item['parentId'] ?? ''));
			if ($parentId === '') {
				continue;
			}

			if (isset($byId[$parentId]) === false) {
				throw new UnexpectedValueException(sprintf("caseModel plan item '%s' names parent '%s', which the model does not declare.", $id, $parentId));
			}

			if ((string)($byId[$parentId]['type'] ?? '') !== 'stage') {
				throw new UnexpectedValueException(
					sprintf("caseModel plan item '%s' names parent '%s', which is not a stage; only a stage nests.", $id, $parentId)
				);
			}
		}
	}//end assertParentsResolve()

	/**
	 * Read one caseType object.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array<string, mixed>|null The caseType, or null when it cannot be read.
	 */
	private function findCaseType(string $caseTypeId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if ($objectService === null || $caseTypeId === '' || $register === '' || $schema === '') {
			return null;
		}

		try {
			$caseType = $objectService->find($caseTypeId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'CasePlanProjectionService: caseType lookup failed',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->firstAsArray(value: [$caseType]);
	}//end findCaseType()

	/**
	 * Find the single published caseModel for a caseType.
	 *
	 * Same lookup as the retiring `CaseModelLoader`, restated here rather
	 * than called: that class goes with the engine (tasks 3.1) and the
	 * structural guard (4.2) refuses a surviving reference to it.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array<string, mixed>|null The model, or null when none is published.
	 */
	private function findPublishedModel(string $caseTypeId): ?array {
		if ($caseTypeId === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$modelSchema = $this->settingsService->getConfigValue(key: 'case_model_schema');
		if ($objectService === null || $register === '' || $modelSchema === '') {
			return null;
		}

		try {
			$found = $objectService->searchObjects(
				[
					'@self' => [
						'register' => (int)$register,
						'schema' => (int)$modelSchema,
					],
					'caseType' => $caseTypeId,
					'lifecycleStatus' => 'published',
				],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CasePlanProjectionService: caseModel lookup failed',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$model = $this->firstAsArray(value: $found);
		if ($model === null) {
			return null;
		}

		return $this->decodePlanItems(model: $model);
	}//end findPublishedModel()

	/**
	 * Decode `planItems` when OpenRegister hands it back as JSON text.
	 *
	 * The property is declared `type: array`, but OpenRegister's magic-mapper
	 * read path answers some rows with the text they were written as; the
	 * retiring loader decoded it in the same place.
	 *
	 * @param array<string, mixed> $model The caseModel as read.
	 *
	 * @return array<string, mixed> The caseModel with a list-shaped `planItems`.
	 */
	private function decodePlanItems(array $model): array {
		$planItems = ($model['planItems'] ?? null);
		if (is_string($planItems) === false || $planItems === '') {
			return $model;
		}

		$decoded = json_decode($planItems, true);
		$model['planItems'] = [];
		if (is_array($decoded) === true) {
			$model['planItems'] = $decoded;
		}

		return $model;
	}//end decodePlanItems()

	/**
	 * The first result of a search, as a plain array.
	 *
	 * @param mixed $value Whatever `searchObjects()` answered.
	 *
	 * @return array<string, mixed>|null The first row, or null when there is none.
	 */
	private function firstAsArray(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		foreach ($value as $item) {
			if (is_array($item) === true && $item !== []) {
				return $item;
			}

			if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
				$serialized = $item->jsonSerialize();
				if (is_array($serialized) === true && $serialized !== []) {
					return $serialized;
				}
			}
		}

		return null;
	}//end firstAsArray()

}//end class
