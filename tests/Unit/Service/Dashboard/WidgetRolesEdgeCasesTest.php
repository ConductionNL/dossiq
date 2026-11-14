<?php

/**
 * The edges of reading roles and fields from widget definitions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\WidgetRoles;
use PHPUnit\Framework\TestCase;

/**
 * Malformed and unusual manifest shapes answer a safe, exact result.
 *
 * @covers \OCA\Dossiq\Service\Dashboard\WidgetRoles
 */
final class WidgetRolesEdgeCasesTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var WidgetRoles
	 */
	private WidgetRoles $roles;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->roles = new WidgetRoles();
	}//end setUp()

	/**
	 * Only pages of type dashboard are read; non-array pages and a missing pages key answer nothing.
	 *
	 * @return void
	 */
	public function testOnlyDashboardPagesAreRead(): void {
		$manifest = [
			'pages' => [
				'not-a-page',
				['type' => 'index', 'config' => ['widgets' => [['id' => 'list-tile']]]],
				['config' => ['widgets' => [['id' => 'untyped-tile']]]],
				['type' => 'dashboard', 'config' => ['widgets' => [['id' => 'dash-tile']]]],
			],
		];

		self::assertSame([['id' => 'dash-tile']], $this->roles->dashboardWidgets(manifest: $manifest));
		self::assertSame([], $this->roles->dashboardWidgets(manifest: []));
	}//end testOnlyDashboardPagesAreRead()

	/**
	 * Widgets come from config.widgets, top-level widgets and each view, in that order; malformed lists are skipped.
	 *
	 * @return void
	 */
	public function testDefinitionsAreGatheredFromEveryPlaceInOrder(): void {
		$page = [
			'widgets' => [['id' => 'top']],
			'config' => [
				'widgets' => [['id' => 'config'], 'not-a-widget', ['title' => 'no id'], ['id' => 'placement', 'widgetKey' => 'config']],
				'views' => [
					'not-a-view',
					['widgets' => 'not-a-list'],
					['widgets' => [['id' => 'view']]],
				],
			],
		];

		self::assertSame(
			[['id' => 'config'], ['id' => 'top'], ['id' => 'view']],
			$this->roles->definitionsOn(page: $page)
		);
	}//end testDefinitionsAreGatheredFromEveryPlaceInOrder()

	/**
	 * A page whose widgets and views are not lists answers no definitions.
	 *
	 * @return void
	 */
	public function testNonListWidgetsAnswerNothing(): void {
		self::assertSame(
			[],
			$this->roles->definitionsOn(page: ['widgets' => 'x', 'config' => ['widgets' => 3, 'views' => 'y']])
		);
	}//end testNonListWidgetsAnswerNothing()

	/**
	 * Roles are trimmed, blanks dropped, and a non-list declaration means none.
	 *
	 * @return void
	 */
	public function testRolesAreTrimmedAndBlanksDropped(): void {
		self::assertSame(['admin', 'kcc'], $this->roles->rolesOf(widget: ['roles' => ['  admin ', '', '   ', 'kcc']]));
		self::assertSame([], $this->roles->rolesOf(widget: ['roles' => 'admin']));
		self::assertSame([], $this->roles->rolesOf(widget: []));
	}//end testRolesAreTrimmedAndBlanksDropped()

	/**
	 * A widget whose roles are only blanks counts as undeclared, so it stays visible.
	 *
	 * @return void
	 */
	public function testBlankRolesAreTreatedAsUndeclared(): void {
		self::assertSame('visible', $this->roles->verdictFor(widget: ['id' => 'w', 'roles' => [' ']], held: [], known: []));
	}//end testBlankRolesAreTreatedAsUndeclared()

	/**
	 * One unknown role makes the widget unresolvable even when another declared role is held.
	 *
	 * @return void
	 */
	public function testAnUnknownRoleWinsOverAHeldOne(): void {
		self::assertSame(
			'unresolvable',
			$this->roles->verdictFor(widget: ['id' => 'w', 'roles' => ['admin', 'renamed']], held: ['admin'], known: ['admin'])
		);
	}//end testAnUnknownRoleWinsOverAHeldOne()

	/**
	 * Fields come from props.content when content is absent, the value field first, caption tokens once each.
	 *
	 * @return void
	 */
	public function testFieldsAreReadFromPropsContentWithoutDuplicates(): void {
		$widget = [
			'props' => [
				'content' => [
					'valueField' => ' openCount ',
					'caption' => '{openCount} open, {late} late, {late} again, {not-a-token}',
				],
			],
		];

		self::assertSame(['openCount', 'late'], $this->roles->fieldsOf(widget: $widget));
	}//end testFieldsAreReadFromPropsContentWithoutDuplicates()

	/**
	 * Content that is not an array, or carries neither a value field nor tokens, names no fields.
	 *
	 * @return void
	 */
	public function testContentWithoutFieldsNamesNone(): void {
		self::assertSame([], $this->roles->fieldsOf(widget: ['content' => 'text']));
		self::assertSame([], $this->roles->fieldsOf(widget: ['content' => ['valueField' => '  ', 'caption' => 'plain text']]));
		self::assertSame([], $this->roles->fieldsOf(widget: []));
	}//end testContentWithoutFieldsNamesNone()

	/**
	 * Narrowing removes exactly the withheld keys and leaves the rest, including absent keys, alone.
	 *
	 * @return void
	 */
	public function testNarrowRemovesOnlyTheWithheldKeys(): void {
		self::assertSame(
			['open' => 3, 'late' => 1],
			$this->roles->narrow(payload: ['open' => 3, 'secret' => 9, 'late' => 1], withhold: ['secret', 'absent'])
		);
	}//end testNarrowRemovesOnlyTheWithheldKeys()

	/**
	 * Unresolvable widgets are reported by id and their fields withheld.
	 *
	 * @return void
	 */
	public function testUnresolvableWidgetsAreReportedAndWithheld(): void {
		$manifest = [
			'pages' => [
				[
					'type' => 'dashboard',
					'config' => [
						'widgets' => [
							['id' => 'gone', 'roles' => ['renamed'], 'content' => ['valueField' => 'hiddenFigure']],
							['id' => 'public', 'content' => ['valueField' => 'openCount']],
						],
					],
				],
			],
		];

		self::assertSame(
			['withhold' => ['hiddenFigure'], 'unresolvable' => ['gone']],
			$this->roles->withheldFields(manifest: $manifest, held: ['admin'], known: ['admin'])
		);
	}//end testUnresolvableWidgetsAreReportedAndWithheld()
}//end class
