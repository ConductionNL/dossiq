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
	 * The four pages a resident reads (site-resident-portal-design D4), in
	 * place of one page per collection.
	 *
	 * WHY THESE ARE WRITTEN OUT. `forCollections()` gives every listable
	 * collection the same three blocks, which is the right default for a
	 * supplier's four lists. A resident reads something else: an overview
	 * that opens with what they still have to do, a case page that shows
	 * where the case stands, and two pages that stay as they were. Each page
	 * carries `menu: false`, so the route keeps working while the portal's
	 * own menu names them (portaliq REQ-SMO-020), and the group of #3245.
	 *
	 * Every key here is one portaliq's resolvers accept today. Portaliq drops
	 * an unknown key in silence, so a key invented here would read as
	 * declared for ever and do nothing.
	 *
	 * @param array<int, array<string, mixed>> $collections The citizen collections.
	 * @param array<int, array<string, mixed>> $actions The citizen actions.
	 * @param string $group The group every page sits in.
	 * @param array<string, string> $labels The label per page id.
	 *
	 * @return array<int, array<string, mixed>> The pages.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-pages-are-declared-and-none-of-them-is-a-menu-entry-req-srpd-005
	 */
	public function forResident(array $collections, array $actions, string $group, array $labels = []): array {
		$pages = [];
		$pages[] = [
			'id' => 'overzicht',
			'label' => ($labels['overzicht'] ?? 'Overzicht'),
			'group' => $group,
			// The page a resident lands on. Portaliq gathers the rows of every
			// `tasks` block on a home page under "Dit moet u nog doen" and
			// renders the rest below it.
			'home' => true,
			'menu' => false,
			'blocks' => $this->residentOverviewBlocks(collections: $collections, labels: $labels),
		];

		$pages[] = [
			'id' => 'mijnZaken',
			'label' => ($labels['mijnZaken'] ?? 'Uw zaak'),
			'group' => $group,
			'menu' => false,
			// THE PAGE A CASE OPENS ON. `steps`, `documents` and `timeline`
			// render only on a record page whose collection names that
			// provider, and portaliq finds this page by its `record` key
			// whenever a case is opened from a list, a card or a notice.
			// The case's own title is the page heading, and the way back
			// passes through Mijn zaken, as on the board (portaliq#1253).
			'record' => ['collection' => 'mijnZaken', 'titleFields' => ['title'], 'heading' => 'record', 'under' => 'cases'],
			'blocks' => $this->residentCaseBlocks(collections: $collections, actions: $actions),
		];

		foreach (['berichten', 'verzoeken'] as $id) {
			if ($this->declares(rows: $collections, id: $id) === false) {
				continue;
			}

			$page = [
				'id' => $id,
				'label' => ($labels[$id] ?? $id),
				'group' => $group,
				'menu' => false,
				'blocks' => [],
			];
			foreach ($actions as $action) {
				if (($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === $this->schemaOf(collections: $collections, id: $id)) {
					$page['blocks'][] = ['type' => 'action', 'action' => (string)$action['id']];
					break;
				}
			}

			$page['blocks'][] = ['type' => 'collection', 'collection' => $id];
			$page['blocks'][] = ['type' => 'detail', 'collection' => $id];
			$pages[] = $page;
		}//end foreach

		return $pages;
	}//end forResident()

	/**
	 * The overview's blocks: what the resident still has to do, their running
	 * cases and the newest messages.
	 *
	 * @param array<int, array<string, mixed>> $collections The citizen collections.
	 * @param array<string, string>            $labels      The label per page id, and `lopendeZaken` for the running cases.
	 *
	 * @return array<int, array<string, mixed>> The blocks, in order.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-pages-are-declared-and-none-of-them-is-a-menu-entry-req-srpd-005
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-overview-greets-and-names-its-lists-req-rod-001
	 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
	 */
	private function residentOverviewBlocks(array $collections, array $labels = []): array {
		// THE OVERVIEW OPENS WITH THE TIME OF DAY AND THE RESIDENT'S FIRST NAME
		// ("Goedemiddag, Sanne"), as the Zuiddrecht design draws it. Portaliq's
		// `greeting` block says that itself and takes the place of the plain
		// "Welkom" heading (site-school-blocks); without it the page opened on
		// a heading the design does not have. The date stays off: the design
		// shows none.
		$blocks   = [];
		$blocks[] = ['type' => 'greeting', 'showDate' => false];
		if ($this->declares(rows: $collections, id: 'vragenAanU') === true) {
			// LABELLED AS THE DESIGN READS THEM. Every list block takes a
			// `label` (portaliq ListBlockNormaliser); without one the lists
			// ran into each other with no heading between them.
			$blocks[] = [
				'type' => 'tasks',
				'collection' => 'vragenAanU',
				'dueField' => 'hersteltermijn',
				'titleFields' => ['summary'],
				'label' => 'Wat u nog moet doen',
				// THE BOARD'S CARD (portaliq#1253 board keys): one highlighted
				// question in the warning tone, the due day in its sub line
				// and the button that answers it. Portaliq drops a key it does
				// not know, so these are the names in its BoardKeys and
				// SchoolBlockKeys, read from fb7a5203.
				'display' => 'highlight',
				'tone' => 'warning',
				'dueInLine' => true,
				'buttonLabel' => 'Document toevoegen',
			];
		}

		// The board draws the running cases compact, with "Alle zaken" beside
		// the heading, and tags a case the resident must act on with the
		// turn's words in the warning tone.
		$blocks[] = [
			'type' => 'cases',
			'collection' => 'mijnZaken',
			'open' => true,
			'limit' => 5,
			'label' => ($labels['lopendeZaken'] ?? 'Lopende zaken'),
			'display' => 'compact',
			'showAll' => true,
			'yourTurn' => ['applicant'],
		];
		// The messages as a plain list: a title and the day, no badge.
		$blocks[] = ['type' => 'inbox', 'collection' => 'berichten', 'limit' => 3, 'label' => 'Nieuwe berichten', 'display' => 'list'];
		// "Bezwaar maken" and "Klacht indienen" act on one case, so they
		// stand on the case page (residentCaseBlocks), not here.

		return $blocks;
	}//end residentOverviewBlocks()

	/**
	 * The case page's blocks, in the order the design reads them: the question
	 * on THIS case, where it stands, the facts, its documents, what happened,
	 * a way to write, a bezwaar or a klacht about it, and the case screen.
	 *
	 * @param array<int, array<string, mixed>> $collections The citizen collections.
	 * @param array<int, array<string, mixed>> $actions     The citizen actions.
	 *
	 * @return array<int, array<string, mixed>> The blocks, in order.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-pages-are-declared-and-none-of-them-is-a-menu-entry-req-srpd-005
	 * @spec openspec/changes/case-actions-on-the-case-page/specs/portal-contribution/spec.md#requirement-the-case-page-offers-bezwaar-and-klacht-for-the-case-on-screen
	 */
	private function residentCaseBlocks(array $collections, array $actions): array {
		$blocks = [];
		if ($this->declares(rows: $collections, id: 'vragenAanU') === true) {
			$blocks[] = [
				'type' => 'tasks',
				'collection' => 'vragenAanU',
				// Only the question on THIS case.
				'recordField' => 'case',
				'dueField' => 'hersteltermijn',
				'titleFields' => ['summary'],
			];
		}

		$blocks[] = ['type' => 'steps', 'collection' => 'mijnZaken'];
		// "Gegevens" over the facts without a second history under them, then
		// "Stukken" with the add button beside it, as the Zaak board orders
		// them (portaliq#1253).
		$blocks[] = ['type' => 'detail', 'collection' => 'mijnZaken', 'label' => 'Gegevens', 'timeline' => false];
		$blocks[] = ['type' => 'documents', 'collection' => 'mijnZaken', 'label' => 'Stukken', 'upload' => true];
		$blocks[] = ['type' => 'timeline', 'collection' => 'mijnZaken'];
		// A way to write, and the two things a resident can start about THIS
		// case: each lands the open case in the action's recordField.
		foreach (['replyToMessage' => 'Bericht sturen', 'createBezwaar' => 'Bezwaar maken', 'createKlacht' => 'Klacht indienen'] as $action => $label) {
			if ($this->declares(rows: $actions, id: $action) === true) {
				$blocks[] = ['type' => 'cta', 'action' => $action, 'label' => $label, 'withRecord' => true];
			}
		}

		// The case screen as the board's notice: the closed-window sentence,
		// no second status block and no second documents list.
		$blocks[] = ['type' => 'citizenCase', 'collection' => 'mijnZaken', 'display' => 'actions'];

		return $blocks;
	}//end residentCaseBlocks()

	/**
	 * Whether a declared row with this id is there.
	 *
	 * @param array<int, array<string, mixed>> $rows The collections or actions.
	 * @param string                           $id   The id to look for.
	 *
	 * @return bool True when it is declared.
	 */
	private function declares(array $rows, string $id): bool {
		foreach ($rows as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				return true;
			}
		}

		return false;
	}//end declares()

	/**
	 * The schema one collection reads, or '' when it is not there.
	 *
	 * @param array<int, array<string, mixed>> $collections The collections.
	 * @param string $id The collection id.
	 *
	 * @return string The schema.
	 */
	private function schemaOf(array $collections, string $id): string {
		foreach ($collections as $collection) {
			if ((string)($collection['id'] ?? '') === $id) {
				return (string)($collection['schema'] ?? '');
			}
		}

		return '';
	}//end schemaOf()

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
