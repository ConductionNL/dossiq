<?php

/**
 * Convert a dossiq caseModel into an OpenRegister case-plan definition.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use UnexpectedValueException;

/**
 * The plan-item half of the caseModel conversion: pure, no I/O.
 *
 * The flat `planItems` list with `parentId` links becomes the nested `items`
 * tree OpenRegister validates; every criteria list goes through the
 * {@see SentryConverter}. Moved out of {@see CasePlanProjectionService}
 * unchanged (method-decomposition).
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
final class CaseModelConverter {

	/**
	 * Constructor.
	 *
	 * @param SentryConverter $sentries Converts every entry and exit criterion.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function __construct(
		private readonly SentryConverter $sentries = new SentryConverter(),
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

		$byId = $this->indexPlanItems(planItems: $planItems);
		$order = array_map(static fn ($id): string => (string)$id, array_keys($byId));

		$this->assertParentsResolve(byId: $byId);
		$items = $this->convertRootItems(byId: $byId, order: $order);

		return ['settings' => $this->convertSettings(caseModel: $caseModel), 'items' => $items];
	}//end convertModel()

	/**
	 * Convert every plan item without a parent, each with its subtree, in declared order.
	 *
	 * @param array<string, array<string, mixed>> $byId  The plan items by id.
	 * @param array<int, string>                  $order The ids in declared order.
	 *
	 * @return array<int, array<string, mixed>> The top-level nodes.
	 */
	private function convertRootItems(array $byId, array $order): array {
		$index = new PlanItemIndex(byId: $byId, order: $order);
		$items = [];
		foreach ($order as $id) {
			if (trim((string)($byId[$id]['parentId'] ?? '')) === '') {
				$items[] = $this->convertItem(item: $byId[$id], index: $index, seen: []);
			}
		}

		return $items;
	}//end convertRootItems()

	/**
	 * Index the plan items by their trimmed id, in declared order.
	 *
	 * @param array<int|string, mixed> $planItems The declared plan items.
	 *
	 * @return array<string, array<string, mixed>> The items by id.
	 *
	 * @throws UnexpectedValueException Naming the entry without an object, without an id, or with a repeated id.
	 */
	private function indexPlanItems(array $planItems): array {
		$byId = [];
		foreach ($planItems as $index => $item) {
			$id = $this->planItemId(item: $item, index: (int)$index);
			if (isset($byId[$id]) === true) {
				throw new UnexpectedValueException(sprintf("caseModel repeats plan-item id '%s'; ids are unique within a model.", $id));
			}

			$byId[$id] = $item;
		}

		return $byId;
	}//end indexPlanItems()

	/**
	 * The trimmed id of one declared plan item.
	 *
	 * @param mixed $item  The declared entry.
	 * @param int   $index Its position, for the refusal message.
	 *
	 * @return string The id.
	 *
	 * @throws UnexpectedValueException When the entry is not an object or has no id.
	 */
	private function planItemId(mixed $item, int $index): string {
		if (is_array($item) === false) {
			throw new UnexpectedValueException(sprintf('caseModel.planItems[%d] must be an object.', $index));
		}

		$id = trim((string)($item['id'] ?? ''));
		if ($id === '') {
			throw new UnexpectedValueException(sprintf('caseModel.planItems[%d] has no id.', $index));
		}

		return $id;
	}//end planItemId()

	/**
	 * Convert one plan item and, for a stage, its children.
	 *
	 * @param array<string, mixed>   $item  The dossiq plan item.
	 * @param PlanItemIndex          $index Every plan item, keyed by id, and the declared order of ids.
	 * @param array<string, boolean> $seen  Ancestor ids, to catch a parent cycle.
	 *
	 * @return array<string, mixed> The OpenRegister node.
	 *
	 * @throws UnexpectedValueException Naming the item it refuses.
	 */
	private function convertItem(array $item, PlanItemIndex $index, array $seen): array {
		$id = trim((string)($item['id'] ?? ''));
		if (isset($seen[$id]) === true) {
			throw new UnexpectedValueException(sprintf("caseModel plan item '%s' is its own ancestor.", $id));
		}

		$seen[$id] = true;
		$node = $this->nodeFor(item: $item, id: $id);
		if ($node['type'] !== 'stage') {
			return $node;
		}

		$node['children'] = $this->childrenOf(id: $id, index: $index, seen: $seen);

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
		$type = $this->convertibleType(item: $item, id: $id);
		$discretionary = (($item['discretionary'] ?? false) === true);

		$node = [
			'key' => $id,
			'type' => $type,
			'name' => (string)($item['name'] ?? $id),
			// The mandatory/discretionary split IS OpenRegister's `required`:
			// a stage waits for every non-discretionary direct child, and
			// OpenRegister's CasePlanTree waits for every required one.
			'required' => ($discretionary === false),
			'discretionary' => $discretionary,
			'entryCriteria' => $this->sentries->convertCriteria(criteria: ($item['entryCriteria'] ?? []), where: sprintf("plan item '%s' entry", $id)),
			'exitCriteria' => $this->sentries->convertCriteria(criteria: ($item['exitCriteria'] ?? []), where: sprintf("plan item '%s' exit", $id)),
		];

		return array_merge($node, $this->optionalNodeFields(item: $item));
	}//end nodeFor()

	/**
	 * The type of a plan item, refused when the case layer cannot hold it.
	 *
	 * @param array<string, mixed> $item The dossiq plan item.
	 * @param string               $id   Its id.
	 *
	 * @return string The type: stage, humanTask or milestone.
	 *
	 * @throws UnexpectedValueException For an unknown type or a discretionary milestone.
	 */
	private function convertibleType(array $item, string $id): string {
		$type = (string)($item['type'] ?? '');
		if (in_array($type, ['stage', 'humanTask', 'milestone'], true) === false) {
			throw new UnexpectedValueException(
				sprintf("caseModel plan item '%s' has type '%s'; expected stage, humanTask or milestone.", $id, $type)
			);
		}

		if ($type === 'milestone' && ($item['discretionary'] ?? false) === true) {
			throw new UnexpectedValueException(
				sprintf("caseModel plan item '%s' is a discretionary milestone, which the case layer refuses: a milestone is never enabled.", $id)
			);
		}

		return $type;
	}//end convertibleType()

	/**
	 * The description and authorization of a node, each only when the plan item sets it.
	 *
	 * @param array<string, mixed> $item The dossiq plan item.
	 *
	 * @return array<string, mixed> `{description?, authorization?}`.
	 */
	private function optionalNodeFields(array $item): array {
		$fields = [];
		$description = trim((string)($item['description'] ?? ''));
		if ($description !== '') {
			$fields['description'] = $description;
		}

		$authorization = ($item['authorization'] ?? []);
		if (is_array($authorization) === true && $authorization !== []) {
			$fields['authorization'] = array_values(array_map(static fn ($rule): string => (string)$rule, $authorization));
		}

		return $fields;
	}//end optionalNodeFields()

	/**
	 * Convert the direct children of a stage, in declared order.
	 *
	 * @param string                 $id    The stage's id.
	 * @param PlanItemIndex          $index Every plan item, keyed by id, and the declared order of ids.
	 * @param array<string, boolean> $seen  Ancestor ids, to catch a parent cycle.
	 *
	 * @return array<int, array<string, mixed>> The child nodes.
	 *
	 * @throws UnexpectedValueException Naming the child it refuses.
	 */
	private function childrenOf(string $id, PlanItemIndex $index, array $seen): array {
		$byId = $index->byId;
		$children = [];
		foreach ($index->order as $childId) {
			if (trim((string)($byId[$childId]['parentId'] ?? '')) === $id) {
				$children[] = $this->convertItem(item: $byId[$childId], index: $index, seen: $seen);
			}
		}

		return $children;
	}//end childrenOf()

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
}//end class
