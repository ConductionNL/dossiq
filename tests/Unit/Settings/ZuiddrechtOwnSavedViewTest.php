<?php

/**
 * Example data for the saved-view boards: the handler the boards draw owns a
 * view on the case list, so its row menu (edit, share, menu, delete) exists.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/example-set-own-saved-view/specs/example-set-own-saved-view/spec.md#requirement-the-handler-the-boards-draw-owns-a-saved-view-on-the-case-list
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pieter Jansen's own saved view on the case list.
 *
 * @spec openspec/changes/example-set-own-saved-view/specs/example-set-own-saved-view/spec.md#requirement-the-handler-the-boards-draw-owns-a-saved-view-on-the-case-list
 */
class ZuiddrechtOwnSavedViewTest extends TestCase {

	/**
	 * The views the Zuiddrecht set declares, by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function views(): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $decoded);

		return array_column($decoded['views'], 'data', 'key');
	}//end views()

	/**
	 * The view is Pieter's own, private, and scoped to the case list.
	 *
	 * @return void
	 */
	public function testPieterOwnsAViewOnTheCaseList(): void {
		$views = $this->views();
		$this->assertArrayHasKey(key: 'pieter-termijn-deze-week', array: $views);

		$view = $views['pieter-termijn-deze-week'];
		$this->assertSame(expected: 'Termijn deze week', actual: $view['name']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $view['owner']);
		$this->assertFalse(condition: $view['isPublic']);
		$this->assertSame(expected: 'dossiq/case', actual: $view['query']['scope']);
		$this->assertSame(expected: ['dossiq'], actual: $view['query']['registers']);
		$this->assertSame(expected: ['case'], actual: $view['query']['schemas']);
	}//end testPieterOwnsAViewOnTheCaseList()

	/**
	 * Its conditions are the default view "Mine, due this week"'s, so it names no status or case type id.
	 *
	 * @return void
	 */
	public function testItsConditionsAreTheShippedDefaultViews(): void {
		$view     = $this->views()['pieter-termijn-deze-week'];
		$manifest = json_decode((string)file_get_contents(__DIR__.'/../../../src/manifest.json'), true);
		$defaults = [];
		foreach ($manifest['pages'] as $page) {
			foreach (($page['config']['defaultSavedViews'] ?? []) as $default) {
				$defaults[$default['slug']] = $default;
			}
		}

		$this->assertSame(expected: $defaults['mine-due-this-week']['filters'], actual: $view['query']['filters']);
		$this->assertSame(expected: [['key' => 'deadline', 'order' => 'asc'], ['key' => 'receivedAt', 'order' => 'asc']], actual: $view['query']['sort']);
		$this->assertSame(expected: '', actual: $view['query']['search']);
	}//end testItsConditionsAreTheShippedDefaultViews()

	/**
	 * Pieter also owns "Wacht op inwoner", the view the DqFilterBeheren, -Bewerken, -Delen and
	 * -Verwijderen boards open; its conditions are the case list's "Waiting on the applicant" lens.
	 *
	 * @return void
	 */
	public function testPieterOwnsTheViewTheFilterBoardsOpen(): void {
		$views = $this->views();
		$this->assertArrayHasKey(key: 'pieter-wacht-op-inwoner', array: $views);

		$view = $views['pieter-wacht-op-inwoner'];
		$this->assertSame(expected: 'Wacht op inwoner', actual: $view['name']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $view['owner']);
		$this->assertFalse(condition: $view['isPublic']);
		$this->assertSame(expected: 'dossiq/case', actual: $view['query']['scope']);

		$manifest = json_decode((string)file_get_contents(__DIR__.'/../../../src/manifest.json'), true);
		$lens     = null;
		foreach ($manifest['pages'] as $page) {
			if (($page['id'] ?? '') !== 'Cases') {
				continue;
			}

			foreach ($page['config']['quickFilters'] as $quickFilter) {
				if ($quickFilter['label'] === 'Waiting on the applicant') {
					$lens = $quickFilter;
				}
			}
		}

		$this->assertNotNull(actual: $lens);
		$conditions = array_merge($view['query']['overrides']['lockedFilters'], $view['query']['filters']);
		ksort($conditions);
		$expected = $lens['filter'];
		ksort($expected);
		$this->assertSame(expected: $expected, actual: $conditions);
	}//end testPieterOwnsTheViewTheFilterBoardsOpen()

	/**
	 * DqFilterBeheren's Active line names what the view chose, not the list's own conditions:
	 * the view's filter is "waiting on the applicant" alone, and the conditions every case list
	 * applies (not hidden, not a draft, still open) are locked, so they show no chip.
	 *
	 * @return void
	 */
	public function testTheFilterBoardViewShowsOnlyItsOwnCondition(): void {
		$view = $this->views()['pieter-wacht-op-inwoner'];

		$this->assertSame(expected: ['waitingOnApplicant' => true], actual: $view['query']['filters']);
		$this->assertSame(
			expected: ['statusHiddenInLists' => false, 'isDraft' => false, 'isFinalStatus' => false],
			actual: $view['query']['overrides']['lockedFilters']
		);
	}//end testTheFilterBoardViewShowsOnlyItsOwnCondition()
}//end class
