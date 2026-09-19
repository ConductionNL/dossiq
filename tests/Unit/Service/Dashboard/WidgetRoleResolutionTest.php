<?php

/**
 * What a reader receives, and what never leaves the server.
 *
 * 🔴 THE FIGURE MUST NOT TRAVEL. A tile hidden in the browser still puts its
 * number in the page's payload, and anyone who opens the network tab reads it
 * (ADR-004). So the assertions here are about the PAYLOAD: the key is gone,
 * not zeroed, because a zero is a figure and a reader shown one has been told
 * something false about the caseload rather than nothing at all.
 *
 * 🔴 AN UNRESOLVABLE ROLE HIDES THE WIDGET FROM EVERYONE, INCLUDING THE
 * ADMINISTRATOR. Defaulting to visible would turn a rename into a disclosure,
 * quietly: the figure is simply there one day for everyone, and nothing says
 * why.
 *
 * 🔴 A FIELD TWO TILES SHARE IS WITHHELD ONLY WHEN BOTH ARE HIDDEN. The
 * opposite reading blanks a tile for somebody entitled to it because a
 * restricted tile happens to read the same count, which is a bug that looks
 * like a permissions problem and sends people to the rights matrix.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Dashboard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\WidgetRoles;
use PHPUnit\Framework\TestCase;

/**
 * The role resolution and the narrowing.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */
class WidgetRoleResolutionTest extends TestCase {
	private WidgetRoles $widgets;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->widgets = new WidgetRoles();
	}//end setUp()

	/**
	 * A manifest with one open tile and one restricted one.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(): array {
		return [
			'pages' => [
				[
					'id' => 'Dashboard',
					'type' => 'dashboard',
					'config' => [
						'widgets' => [
							[
								'id' => 'kpi-open',
								'type' => 'stat',
								'content' => ['valueField' => 'openCount', 'caption' => '+{newToday} today'],
							],
							[
								'id' => 'kpi-sla',
								'type' => 'stat',
								'roles' => ['dossiq-teamleider'],
								'content' => ['valueField' => 'slaCompliance', 'caption' => '{slaBreaches} breached'],
							],
							// A placement, not a definition: it says WHERE a
							// widget goes and must not be asked to declare an
							// audience of its own.
							['widgetKey' => 'kpi-sla', 'slot' => 'body', 'gridX' => 0, 'gridY' => 0],
						],
					],
				],
				[
					'id' => 'CaseDetail',
					'type' => 'detail',
					'config' => ['widgets' => [['id' => 'case-terms', 'type' => 'data']]],
				],
			],
		];
	}//end manifest()

	/**
	 * Dashboard widgets only, and placements are not widgets.
	 *
	 * @return void
	 */
	public function testItReadsDashboardDefinitionsAndNotPlacementsOrDetailPanels(): void {
		$ids = array_column($this->widgets->dashboardWidgets(manifest: $this->manifest()), 'id');

		$this->assertSame(['kpi-open', 'kpi-sla'], $ids);
	}//end testItReadsDashboardDefinitionsAndNotPlacementsOrDetailPanels()

	/**
	 * A reader holding the role sees the figure.
	 *
	 * @return void
	 */
	public function testAReaderHoldingTheRoleKeepsTheFigure(): void {
		$verdicts = $this->widgets->withheldFields(
			manifest: $this->manifest(),
			held: ['dossiq-teamleider'],
			known: ['dossiq-teamleider'],
		);

		$this->assertSame([], $verdicts['withhold']);
	}//end testAReaderHoldingTheRoleKeepsTheFigure()

	/**
	 * A reader without it never receives it, caption and all.
	 *
	 * @return void
	 */
	public function testAReaderWithoutTheRoleNeverReceivesTheFigure(): void {
		$verdicts = $this->widgets->withheldFields(
			manifest: $this->manifest(),
			held: ['dossiq-behandelaar'],
			known: ['dossiq-teamleider', 'dossiq-behandelaar'],
		);

		// The caption token too: a tile hidden with its caption fields left in
		// the payload leaks the same figure one sentence further down.
		sort($verdicts['withhold']);
		$this->assertSame(['slaBreaches', 'slaCompliance'], $verdicts['withhold']);

		$narrowed = $this->widgets->narrow(
			payload: ['openCount' => 12, 'slaCompliance' => 0.82, 'slaBreaches' => 3],
			withhold: $verdicts['withhold'],
		);

		// Gone, not zeroed.
		$this->assertSame(['openCount' => 12], $narrowed);
		$this->assertArrayNotHasKey('slaCompliance', $narrowed);
	}//end testAReaderWithoutTheRoleNeverReceivesTheFigure()

	/**
	 * A role nothing answers to hides the widget from everyone, and is
	 * reportable.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRoleHidesTheWidgetFromEveryone(): void {
		$verdicts = $this->widgets->withheldFields(
			manifest: $this->manifest(),
			held: ['dossiq-teamleider'],
			// The group was renamed: the declaration now names nothing.
			known: ['dossiq-teamleiders'],
		);

		$this->assertSame(['kpi-sla'], $verdicts['unresolvable']);
		sort($verdicts['withhold']);
		$this->assertSame(['slaBreaches', 'slaCompliance'], $verdicts['withhold']);
	}//end testAnUnresolvableRoleHidesTheWidgetFromEveryone()

	/**
	 * An undeclared widget stays public, because every dossiq widget was until
	 * this change and failing them closed would empty every dashboard on the
	 * fleet. The structural test is what keeps that from being permanent.
	 *
	 * @return void
	 */
	public function testAnUndeclaredWidgetIsStillVisible(): void {
		$this->assertSame('visible', $this->widgets->verdictFor(['id' => 'x'], ['user'], ['user']));
	}//end testAnUndeclaredWidgetIsStillVisible()

	/**
	 * A field two tiles share is withheld only when both are hidden.
	 *
	 * @return void
	 */
	public function testAFieldSharedWithAVisibleTileIsNotWithheld(): void {
		$manifest = $this->manifest();
		$manifest['pages'][0]['config']['widgets'][] = [
			'id' => 'kpi-sla-open',
			'type' => 'stat',
			'content' => ['valueField' => 'slaCompliance'],
		];

		$verdicts = $this->widgets->withheldFields(
			manifest: $manifest,
			held: ['dossiq-behandelaar'],
			known: ['dossiq-teamleider', 'dossiq-behandelaar'],
		);

		// Only the caption field of the restricted tile goes: the value is
		// shown by a tile this reader may see.
		$this->assertSame(['slaBreaches'], $verdicts['withhold']);
	}//end testAFieldSharedWithAVisibleTileIsNotWithheld()

	/**
	 * A widget declaring several roles is visible to anyone holding one.
	 *
	 * @return void
	 */
	public function testHoldingAnyOneOfTheDeclaredRolesIsEnough(): void {
		$widget = ['id' => 'w', 'roles' => ['a', 'b']];

		$this->assertSame('visible', $this->widgets->verdictFor($widget, ['b'], ['a', 'b']));
		$this->assertSame('hidden', $this->widgets->verdictFor($widget, ['c'], ['a', 'b', 'c']));
	}//end testHoldingAnyOneOfTheDeclaredRolesIsEnough()
}//end class
