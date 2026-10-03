<?php

/**
 * Dossiq Portal Pages
 *
 * Builds the pages dossiq declares in its portal contribution: one per
 * listable collection, as portaliq would build them itself, with a `group`
 * (portaliq's group contract) and, on the resident's case page, portaliq's
 * case screen. Split from PortalContributionProvider to keep that class
 * readable. Plain: no dependencies, no portaliq imports.
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
 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * Builds dossiq's declared portal pages.
 *
 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
 */
class PortalPages {

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
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
	 */
	public function forCollections(array $collections, array $actions, string $group, array $labels=[]): array {
		$pages = [];
		foreach ($collections as $collection) {
			if (($collection['listable'] ?? true) !== true) {
				continue;
			}

			$id = (string)$collection['id'];
			$blocks = [];
			foreach ($actions as $action) {
				if (($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === ($collection['schema'] ?? '')) {
					$blocks[] = ['type' => 'action', 'action' => (string)$action['id']];
					break;
				}
			}

			$blocks[] = ['type' => 'collection', 'collection' => $id];
			$blocks[] = ['type' => 'detail', 'collection' => $id];

			$pages[] = [
				'id' => $id,
				'label' => ($labels[$id] ?? (string)($collection['label'] ?? $id)),
				'group' => $group,
				'blocks' => $blocks,
			];
		}

		return $pages;
	}//end forCollections()

	/**
	 * The case page also mounts portaliq's case screen (development #3247).
	 *
	 * Portaliq's case screen (status, amend, documents, withdraw) mounts only
	 * through a `citizenCase` block. A resident opened a Woo request from
	 * "Mijn zaken", read its fields and found no way to withdraw it, although
	 * the server would have accepted the withdrawal. So the `mijnZaken` page
	 * gets that block after its detail.
	 *
	 * @param array<int, array<string, mixed>> $pages The citizen pages.
	 *
	 * @return array<int, array<string, mixed>> The pages, the case page with the case screen.
	 *
	 * @spec openspec/changes/portal-case-page-withdraws/specs/portal-contribution/spec.md#requirement-req-portal-021-a-resident-must-open-their-own-case-on-a-page-that-can-withdraw-it
	 */
	public function withCaseScreen(array $pages): array {
		foreach ($pages as $index => $page) {
			if ($page['id'] === 'mijnZaken') {
				// What the detail card does not carry: the status in words,
				// the answers the resident may still change, the documents
				// and the withdrawal the case type declares.
				$pages[$index]['blocks'][] = ['type' => 'citizenCase', 'collection' => 'mijnZaken'];
			}
		}

		return $pages;
	}//end withCaseScreen()
}//end class
