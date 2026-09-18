<?php

/**
 * Every dashboard widget says who may see it, or says why it need not.
 *
 * 🔴 THIS TEST IS THE CHANGE. Making the declaration optional means the next
 * widget ships without one and nobody notices, which is exactly how the
 * current state arrived: ninety-seven widgets, not one of them saying who its
 * figure is for. The rule is mechanical so the question is asked at the moment
 * a widget is added rather than in a review six months later (ADR-060).
 *
 * 🔴 THE ALLOWLIST SHRINKS AND CANNOT ROT. A widget on the list that has SINCE
 * declared roles fails: the entry is then a stale claim about a widget that
 * moved on, and a list of stale claims is how an allowlist becomes a place
 * things go to be forgotten. A reason is required on every entry, because a
 * widget genuinely visible to everyone is legitimate and silence is not.
 *
 * 🔴 DASHBOARD PAGES ONLY, AND THE SCOPE IS ARGUED RATHER THAN ASSUMED. A
 * widget on a DETAIL page renders the record the reader has already been
 * granted, and its access question is that record's own grants
 * (`case-grants-name-their-source`), not a role on a tile. Asserting it here
 * would put seventy entries on an allowlist to say one thing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use OCA\Dossiq\Service\Dashboard\WidgetRoles;
use PHPUnit\Framework\TestCase;

/**
 * The declaration is not optional.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */
class WidgetDeclaresRolesTest extends TestCase {
	/**
	 * Dashboard widgets that declare no roles, and why each one need not.
	 *
	 * Every entry is a widget whose figure is the READER'S OWN WORK or a count
	 * the lists beside it already show them. Adding an entry is a decision
	 * somebody writes down here; the test fails on an entry with no reason,
	 * and on a widget that has since declared roles.
	 *
	 * @var array<string, string>
	 */
	private const PUBLIC_WIDGETS = [
		'kpi-open-cases' => 'The number of open cases, which the Cases list shows anyone who opens it.',
		'kpi-overdue' => 'Cases past their deadline. Everyone handling work needs to see the ones that are late, and the Overdue lens on the list is the same set.',
		'kpi-completed' => 'What the team finished this month. A figure a gemeente puts on a poster.',
		'kpi-my-tasks' => "The reader's own tasks: it can hold nothing they may not see, because it is filtered to them.",
		'cases-by-status' => 'Where the open caseload sits, which is the shape of the same list everyone can already read.',
		'cases-by-type' => 'The caseload by case type, from the same lists.',
		'favourite-cases' => "The reader's own favourites.",
		'recent-cases' => 'What this reader opened recently, and nobody else can see it by construction.',
		'stalled-cases' => 'Cases nobody has touched, which is the case everyone handling work should be able to pick up.',
		'my-work' => "The reader's own work.",
		'deadlines' => 'The deadlines on the cases the reader can already open.',
		'open-cases' => 'The open cases the reader can already open.',
		'followed-cases' => 'The cases this reader chose to follow.',
		'archival-reviews' => "The reader's own archival reviews, assigned to them.",
	];

	/**
	 * The shipped manifest.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(): array {
		$raw = file_get_contents(__DIR__ . '/../../../src/manifest.json');
		$this->assertIsString($raw, 'the manifest could not be read');

		return (array)json_decode((string)$raw, true);
	}//end manifest()

	/**
	 * Every dashboard widget declares roles or is allowlisted with a reason.
	 *
	 * @return void
	 */
	public function testEveryDashboardWidgetDeclaresRolesOrSaysWhyNot(): void {
		$widgets = new WidgetRoles();
		$undeclared = [];

		foreach ($widgets->dashboardWidgets(manifest: $this->manifest()) as $widget) {
			$id = (string)$widget['id'];
			if ($widgets->rolesOf(widget: $widget) !== []) {
				continue;
			}

			if (array_key_exists($id, self::PUBLIC_WIDGETS) === false) {
				$undeclared[] = $id;
			}
		}

		$this->assertSame(
			[],
			$undeclared,
			"These dashboard widgets say nothing about who may see them. Declare `roles` on the widget, "
			. "or add it to PUBLIC_WIDGETS with the reason it is for everyone: " . implode(', ', $undeclared)
		);
	}//end testEveryDashboardWidgetDeclaresRolesOrSaysWhyNot()

	/**
	 * An allowlisted widget that has since declared roles fails, so the list
	 * shrinks rather than rotting.
	 *
	 * @return void
	 */
	public function testTheAllowlistHoldsNoWidgetThatHasSinceDeclaredRoles(): void {
		$widgets = new WidgetRoles();
		$stale = [];

		foreach ($widgets->dashboardWidgets(manifest: $this->manifest()) as $widget) {
			$id = (string)$widget['id'];
			if (array_key_exists($id, self::PUBLIC_WIDGETS) === true && $widgets->rolesOf(widget: $widget) !== []) {
				$stale[] = $id;
			}
		}

		$this->assertSame([], $stale, 'Remove these from PUBLIC_WIDGETS: they declare roles now. ' . implode(', ', $stale));
	}//end testTheAllowlistHoldsNoWidgetThatHasSinceDeclaredRoles()

	/**
	 * The allowlist names no widget that no longer exists.
	 *
	 * @return void
	 */
	public function testTheAllowlistNamesNoWidgetThatIsGone(): void {
		$widgets = new WidgetRoles();
		$present = [];
		foreach ($widgets->dashboardWidgets(manifest: $this->manifest()) as $widget) {
			$present[] = (string)$widget['id'];
		}

		$ghosts = array_values(array_diff(array_keys(self::PUBLIC_WIDGETS), $present));

		$this->assertSame([], $ghosts, 'These allowlist entries name widgets that are gone: ' . implode(', ', $ghosts));
	}//end testTheAllowlistNamesNoWidgetThatIsGone()

	/**
	 * Every allowlist entry carries a reason, because silence is what this
	 * whole rule exists to refuse.
	 *
	 * @return void
	 */
	public function testEveryAllowlistEntryCarriesAReason(): void {
		foreach (self::PUBLIC_WIDGETS as $id => $reason) {
			$this->assertNotSame('', trim($reason), sprintf('%s is allowlisted with no reason', $id));
			$this->assertGreaterThan(20, strlen(trim($reason)), sprintf('%s is allowlisted with a reason that says nothing', $id));
		}
	}//end testEveryAllowlistEntryCarriesAReason()

	/**
	 * A declared widget says why, beside the declaration, so the audience can
	 * be argued with rather than only obeyed.
	 *
	 * @return void
	 */
	public function testEveryDeclaredWidgetCarriesItsReasoning(): void {
		$widgets = new WidgetRoles();

		foreach ($widgets->dashboardWidgets(manifest: $this->manifest()) as $widget) {
			if ($widgets->rolesOf(widget: $widget) === []) {
				continue;
			}

			$this->assertNotSame(
				'',
				trim((string)($widget['_rolesNote'] ?? '')),
				sprintf('%s declares roles and does not say why', (string)$widget['id'])
			);
		}
	}//end testEveryDeclaredWidgetCarriesItsReasoning()
}//end class
