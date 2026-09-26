<?php

/**
 * Structural guard: a declared widget is actually taken off the page.
 *
 * 🔴 THE FAILURE THIS FILE EXISTS FOR IS A DECLARATION THAT DOES NOTHING.
 * `widget-roles-declared` made 14 widgets name their readers and made the DATA
 * obey. It could not make the TILE obey, because no component in
 * `@conduction/nextcloud-vue` reads a widget's `roles` key: measured
 * 2026-09-18 by grepping the installed 3.2.0 for a reader of it, in
 * CnDashboardPage, CnDetailPage and everywhere else under `src/components`.
 * There is none. So the declaration validated, shipped, was reviewed, and left
 * every tile exactly where it was.
 *
 * What DOES ship in that library is `visibleWhen` with an `endpoint`: it
 * fetches, reads a dot-path out of the answer, and collapses the cell when the
 * fetch or the shape fails. This test is what says every declaration is bound
 * to it, and bound to its OWN id, because `field: "visible.dt-kpis"` on the
 * `td-annual` tile is the kind of copy-paste that hides the wrong figure and
 * looks right in review.
 *
 * 🔑 DERIVED FROM THE MANIFEST. The list is not written here, for the reason
 * the sibling sweeps are not: a test naming the widgets its author knew stays
 * green through the fifteenth.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Dashboard\WidgetRoles;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use PHPUnit\Framework\TestCase;

/**
 * Every declared widget names an audience that exists and a condition that bites.
 *
 * @coversNothing
 */
class WidgetRolesBiteTest extends TestCase {

	/**
	 * The endpoint every declared widget's condition reads.
	 */
	private const ENDPOINT = '/apps/dossiq/api/dashboard/widget-visibility';

	/**
	 * The route table, where that endpoint has to be registered.
	 */
	private const ROUTES = __DIR__ . '/../../../appinfo/routes.php';

	/**
	 * The manifest, decoded.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(): array {
		$decoded = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../src/manifest.json'),
			true
		);
		$this->assertIsArray($decoded, 'the manifest must parse');

		return $decoded;
	}//end manifest()

	/**
	 * Every widget that declares roles, as the shipped reader finds them.
	 *
	 * @return array<int, array<string, mixed>> The declarations.
	 */
	private function declared(): array {
		$widgets = new WidgetRoles();

		$declared = [];
		foreach ($widgets->dashboardWidgets(manifest: $this->manifest()) as $widget) {
			if ($widgets->rolesOf(widget: $widget) !== []) {
				$declared[] = $widget;
			}
		}

		$this->assertNotSame(
			[],
			$declared,
			'no widget declares roles, so every assertion below passes on an empty list'
		);

		return $declared;
	}//end declared()

	/**
	 * Every declaration is bound to the visibility endpoint, on its own id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function testEveryDeclarationIsBoundToTheVisibilityEndpoint(): void {
		$unbound = [];

		foreach ($this->declared() as $widget) {
			$id = (string)$widget['id'];
			$condition = ($widget['visibleWhen'] ?? null);

			if (is_array($condition) === false) {
				$unbound[] = $id . ' (no visibleWhen)';
				continue;
			}

			if ((string)($condition['endpoint'] ?? '') !== self::ENDPOINT) {
				$unbound[] = $id . ' (endpoint is ' . (string)($condition['endpoint'] ?? '') . ')';
				continue;
			}

			// ITS OWN ID. A condition pointing at a sibling's verdict hides the
			// wrong tile and reads correctly in review.
			if ((string)($condition['field'] ?? '') !== 'visible.' . $id) {
				$unbound[] = $id . ' (field is ' . (string)($condition['field'] ?? '') . ')';
				continue;
			}

			if (($condition['value'] ?? null) !== true || (string)($condition['op'] ?? 'eq') !== 'eq') {
				$unbound[] = $id . ' (the condition is not "visible is true")';
			}
		}

		$this->assertSame(
			[],
			$unbound,
			"A widget that declares roles and binds no condition keeps its tile on the page for "
			. "everyone, over a payload that correctly carries none of its figures:\n  "
			. implode("\n  ", $unbound)
		);
	}//end testEveryDeclarationIsBoundToTheVisibilityEndpoint()

	/**
	 * The endpoint those conditions name is registered.
	 *
	 * A `visibleWhen` whose endpoint 404s collapses the cell, which is
	 * fail-closed and therefore INVISIBLE: every declared tile would simply be
	 * gone for everybody and the dashboards would look deliberate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function testTheVisibilityEndpointIsRouted(): void {
		$routes = (string)file_get_contents(self::ROUTES);

		$this->assertStringContainsString('/api/dashboard/widget-visibility', $routes);
		$this->assertStringContainsString('widgetVisibility#index', $routes);
		$this->assertFileExists(
			__DIR__ . '/../../../lib/Controller/WidgetVisibilityController.php',
			'the route names a controller that must exist, or it answers a 500 and every tile vanishes'
		);
	}//end testTheVisibilityEndpointIsRouted()

	/**
	 * Every declared role is a group this app creates, or Nextcloud's own.
	 *
	 * 🔴 THIS IS THE ONE THAT WOULD HAVE CAUGHT `dossiq-teamleider`. Fourteen
	 * widgets named it and `ProvisionAssignedGroups` created nothing, so every
	 * one of them was hidden from everybody on a fresh install, correctly and
	 * silently: a role no group answers to is held by nobody, and
	 * `isInGroup()` cannot tell a missing group from an empty one.
	 * `AccessControlGroupsAreProvisionedTest` did not see it because it reads
	 * the group literals the CODE gates on, and a widget's `roles` key is a
	 * manifest declaration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-role-that-resolves-to-nothing-hides-the-widget-req-wrd-03
	 */
	public function testEveryDeclaredRoleIsAGroupThisAppCreates(): void {
		$widgets = new WidgetRoles();
		// `admin` is Nextcloud's own and exists on every instance by definition.
		$provisioned = array_merge(ProvisionAssignedGroups::ASSIGNED_GROUPS, ['admin']);

		$orphans = [];
		foreach ($this->declared() as $widget) {
			foreach ($widgets->rolesOf(widget: $widget) as $role) {
				if (in_array($role, $provisioned, true) === false) {
					$orphans[] = sprintf('%s declares %s', (string)$widget['id'], $role);
				}
			}
		}

		$this->assertSame(
			[],
			array_values(array_unique($orphans)),
			"These widgets name a group nothing creates, so they are hidden from everyone on a fresh "
			. "install and the dashboard looks deliberate. Provision it in "
			. "ProvisionAssignedGroups::ASSIGNED_GROUPS, or declare a group that exists:\n  "
			. implode("\n  ", array_unique($orphans))
		);
	}//end testEveryDeclaredRoleIsAGroupThisAppCreates()

	/**
	 * The widget's audience is the audience its own endpoint enforces.
	 *
	 * 🔑 THE POINT OF THE WHOLE CHANGE, AND THE REASON THE GROUP WAS REPLACED
	 * RATHER THAN PROVISIONED. Every one of these tiles is fed by an endpoint
	 * that already decides who may read it: `ReportingAudience` for the
	 * doorlooptijd and termijn figures, `ProcessMiningController` for the
	 * mining ones, and `DashboardWidgetScope` for the KPI payload. Declaring a
	 * DIFFERENT audience on the tile does not restrict anything: it produces a
	 * reader who is shown a tile that answers them 403, and a reader entitled
	 * to the figures with no tile to read them in. Two answers to one question
	 * is worse than one answer in the wrong place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function testTheDeclaredAudienceIsTheOneTheEndpointEnforces(): void {
		$widgets = new WidgetRoles();

		foreach ($this->declared() as $widget) {
			$this->assertSame(
				ReportingAudience::ALLOWED_GROUPS,
				$widgets->rolesOf(widget: $widget),
				(string)$widget['id'] . ' declares a different audience from the endpoint that feeds it'
			);
		}
	}//end testTheDeclaredAudienceIsTheOneTheEndpointEnforces()
}//end class
