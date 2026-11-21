<?php

/**
 * Dossiq Portal Collection Pages
 *
 * Builds one portal page per listable collection, as portaliq would build it
 * itself (the create action for the collection's schema, the list, the
 * selected row), with a `group` and a name of its own. Split from
 * PortalPages, which keeps the resident's pages. Plain: no dependencies, no
 * portaliq imports.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * Builds one portal page per listable collection.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
class CollectionPages {

	/**
	 * One page per listable collection, under one menu group.
	 *
	 * The pages portaliq would make when an app declares none (the create
	 * action for the collection's schema, the list, the selected row), so the
	 * screens stay as they were. They are declared because only a declared
	 * page carries a `group` and a name of its own.
	 *
	 * @param array<int, array<string, mixed>> $collections The audience's collections.
	 * @param array<int, array<string, mixed>> $actions     The audience's actions.
	 * @param string                           $group       The menu heading.
	 * @param array<string, string>            $labels      Page names that differ from the collection label, by collection id.
	 *
	 * @return array<int, array<string, mixed>> The pages.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function forCollections(array $collections, array $actions, string $group, array $labels=[]): array {
		$pages = [];
		foreach ($this->listable(collections: $collections) as $collection) {
			$id = (string)$collection['id'];
			$pages[] = [
				'id' => $id,
				'label' => ($labels[$id] ?? (string)($collection['label'] ?? $id)),
				'group' => $group,
				'blocks' => $this->blocksFor(collection: $collection, actions: $actions),
			];
		}

		return $pages;
	}//end forCollections()

	/**
	 * The collections that are listable, in order.
	 *
	 * @param array<int, array<string, mixed>> $collections The audience's collections.
	 *
	 * @return array<int, array<string, mixed>> The listable collections.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function listable(array $collections): array {
		$listable = [];
		foreach ($collections as $collection) {
			if (($collection['listable'] ?? true) === true) {
				$listable[] = $collection;
			}
		}

		return $listable;
	}//end listable()

	/**
	 * A collection page's blocks: the create action for its schema, the list, the selected row.
	 *
	 * @param array<string, mixed>             $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    The audience's actions.
	 *
	 * @return array<int, array<string, string>> The blocks.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function blocksFor(array $collection, array $actions): array {
		$id = (string)$collection['id'];
		$blocks = $this->createBlocks(collection: $collection, actions: $actions);
		$blocks[] = ['type' => 'collection', 'collection' => $id];
		$blocks[] = ['type' => 'detail', 'collection' => $id];

		return $blocks;
	}//end blocksFor()

	/**
	 * The first create action for the collection's schema, as a block.
	 *
	 * @param array<string, mixed>             $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    The audience's actions.
	 *
	 * @return array<int, array<string, string>> The action block, or none.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function createBlocks(array $collection, array $actions): array {
		foreach ($actions as $action) {
			if (($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === ($collection['schema'] ?? '')) {
				return [['type' => 'action', 'action' => (string)$action['id']]];
			}
		}

		return [];
	}//end createBlocks()
}//end class
