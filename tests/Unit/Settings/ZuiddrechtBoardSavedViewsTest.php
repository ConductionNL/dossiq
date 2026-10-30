<?php

/**
 * Example data for DqFilterBeheren and DqFilterInMenu: Pieter Jansen's three
 * own views and the view a colleague shared with him.
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
 * @spec openspec/specs/example-set-board-saved-views/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The saved views the DqFilter boards draw in Pieter's Weergaven tab.
 *
 * @spec openspec/specs/example-set-board-saved-views/spec.md
 */
class ZuiddrechtBoardSavedViewsTest extends TestCase {

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
	 * The Cases page config.
	 *
	 * @return array<string, mixed>
	 */
	private function casesConfig(): array {
		$manifest = json_decode((string)file_get_contents(__DIR__.'/../../../src/manifest.json'), true);
		foreach ($manifest['pages'] as $page) {
			if (($page['id'] ?? '') === 'Cases' && isset($page['config']) === true) {
				return $page['config'];
			}
		}

		$this->fail(message: 'no Cases page');
	}//end casesConfig()

	/**
	 * Every condition of a view, filters and locked ones, sorted by key.
	 *
	 * @param array<string, mixed> $view The view.
	 *
	 * @return array<string, mixed>
	 */
	private function conditions(array $view): array {
		$conditions = array_merge(($view['query']['overrides']['lockedFilters'] ?? []), $view['query']['filters']);
		ksort($conditions);

		return $conditions;
	}//end conditions()

	/**
	 * "Wacht op inwoner" reads as the board describes it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-board-saved-views/spec.md#requirement-pieter-owns-the-boards-three-views
	 */
	public function testWaitingViewHasTheBoardsDescription(): void {
		$this->assertSame(
			expected: 'Status wacht op inwoner, gedeeld met team Woo',
			actual: $this->views()['pieter-wacht-op-inwoner']['description']
		);
	}//end testWaitingViewHasTheBoardsDescription()

	/**
	 * Pieter owns "Opgeschort", the case list's Suspended lens.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-board-saved-views/spec.md#requirement-pieter-owns-the-boards-three-views
	 */
	public function testPieterOwnsTheSuspendedView(): void {
		$views = $this->views();
		$this->assertArrayHasKey(key: 'pieter-opgeschort', array: $views);

		$view = $views['pieter-opgeschort'];
		$this->assertSame(expected: 'Opgeschort', actual: $view['name']);
		$this->assertSame(expected: 'Status opgeschort, alle teams', actual: $view['description']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $view['owner']);
		$this->assertFalse(condition: $view['isPublic']);
		$this->assertSame(expected: 'dossiq/case', actual: $view['query']['scope']);

		$lens = null;
		foreach ($this->casesConfig()['quickFilters'] as $quickFilter) {
			if ($quickFilter['label'] === 'Suspended') {
				$lens = $quickFilter['filter'];
			}
		}

		$this->assertNotNull(actual: $lens);
		ksort($lens);
		$this->assertSame(expected: $lens, actual: $this->conditions(view: $view));
	}//end testPieterOwnsTheSuspendedView()

	/**
	 * Anouk shares "Woo-verzoeken team" with Woo en informatie to use.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-board-saved-views/spec.md#requirement-a-colleague-shares-a-view-with-pieter
	 */
	public function testAnoukSharesTheWooTeamView(): void {
		$views = $this->views();
		$this->assertArrayHasKey(key: 'anouk-woo-verzoeken-team', array: $views);

		$view = $views['anouk-woo-verzoeken-team'];
		$this->assertSame(expected: 'Woo-verzoeken team', actual: $view['name']);
		$this->assertSame(expected: '{{cast:anouk.uid}}', actual: $view['owner']);
		$this->assertFalse(condition: $view['isPublic']);
		$this->assertSame(expected: [['group' => '{{cast:woo-en-informatie.gid}}', 'mode' => 'read']], actual: $view['sharedWith']);
		$this->assertSame(expected: 'dossiq/case', actual: $view['query']['scope']);

		$default = null;
		foreach ($this->casesConfig()['defaultSavedViews'] as $candidate) {
			if ($candidate['slug'] === 'woo-requests') {
				$default = $candidate['filters'];
			}
		}

		$this->assertNotNull(actual: $default);
		ksort($default);
		$this->assertSame(expected: $default, actual: $this->conditions(view: $view));
	}//end testAnoukSharesTheWooTeamView()
}//end class
