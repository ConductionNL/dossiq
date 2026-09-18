<?php

/**
 * Which tiles a reader is shown, over the shipped manifest.
 *
 * 🔑 IT RUNS OVER THE REAL MANIFEST, NOT A FIXTURE. The fixture-driven
 * assertions about the VERDICT already exist in `WidgetRoleResolutionTest`;
 * what this adds is that the shipped declarations produce the answer the page
 * needs, which a fixture can never say.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dashboard
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

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCA\Dossiq\Service\Dashboard\WidgetRoles;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The layout answer the page reads.
 *
 * @covers \OCA\Dossiq\Service\Dashboard\DashboardWidgetScope
 */
class WidgetVisibilityMapTest extends TestCase {

	/**
	 * A tile the shipped manifest declares.
	 */
	private const DECLARED = 'td-annual';

	/**
	 * A tile it leaves to everyone.
	 */
	private const OPEN = 'kpi-my-tasks';

	/**
	 * The scope over a world where the given groups exist and the reader holds
	 * the given ones.
	 *
	 * @param array<int, string> $exists The groups on the instance.
	 * @param array<int, string> $held   The groups the reader is in.
	 *
	 * @return DashboardWidgetScope The service.
	 */
	private function scope(array $exists, array $held): DashboardWidgetScope {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('search')->willReturnCallback(
			function (string $search) use ($exists): array {
				return array_map(
					function (string $gid): IGroup {
						$group = $this->createMock(originalClassName: IGroup::class);
						$group->method('getGID')->willReturn($gid);

						return $group;
					},
					$exists
				);
			}
		);
		$groups->method('getUserGroupIds')->willReturn($held);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturn($this->createMock(originalClassName: IUser::class));

		return new DashboardWidgetScope(
			widgets: new WidgetRoles(),
			groups: $groups,
			users: $users,
			logger: new NullLogger(),
		);
	}

	/**
	 * A member of the audience is shown the declared tiles.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function testAMemberIsShownTheDeclaredTiles(): void {
		$map = $this->scope(exists: ['controllers', 'beheerders', 'admin'], held: ['controllers'])
			->visibilityFor(userId: 'sanne');

		$this->assertArrayHasKey(
			self::DECLARED,
			$map,
			self::DECLARED . ' must declare roles in the shipped manifest, or this test measures nothing'
		);
		$this->assertTrue($map[self::DECLARED]);
	}//end testAMemberIsShownTheDeclaredTiles()

	/**
	 * A case handler is not, and the rest of the page is untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function testACaseHandlerIsNotShownThem(): void {
		$map = $this->scope(exists: ['controllers', 'beheerders', 'admin', 'behandelaars'], held: ['behandelaars'])
			->visibilityFor(userId: 'sanne');

		$this->assertFalse($map[self::DECLARED]);

		// A tile nothing restricts is ABSENT from the map rather than true in
		// it: `visibleWhen` only names the ones that carry it, and a map
		// answering for every widget in the app would be a second, partial copy
		// of the manifest.
		$this->assertArrayNotHasKey(self::OPEN, $map);
	}//end testACaseHandlerIsNotShownThem()

	/**
	 * A renamed group takes the tile off the page rather than opening it.
	 *
	 * The fail-closed direction, asserted over the SHIPPED declarations: if
	 * `controllers` were renamed tomorrow, every declared tile would vanish and
	 * none would be shown to a reader the double still reports as a member.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-role-that-resolves-to-nothing-hides-the-widget-req-wrd-03
	 */
	public function testARenamedGroupHidesTheTile(): void {
		$map = $this->scope(exists: ['behandelaars'], held: ['controllers'])
			->visibilityFor(userId: 'sanne');

		$this->assertNotSame([], $map, 'the shipped manifest must declare something');
		foreach ($map as $widgetId => $shown) {
			$this->assertFalse($shown, $widgetId . ' must be hidden when its role answers to no group');
		}
	}//end testARenamedGroupHidesTheTile()

	/**
	 * The map answers for every declared tile and for nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function testTheMapCoversExactlyTheDeclaredTiles(): void {
		$scope = $this->scope(exists: ['controllers'], held: ['controllers']);
		$map = $scope->visibilityFor(userId: 'sanne');

		$manifest = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../src/manifest.json'),
			true
		);
		$widgets = new WidgetRoles();

		$declared = [];
		foreach ($widgets->dashboardWidgets(manifest: (array)$manifest) as $widget) {
			if ($widgets->rolesOf(widget: $widget) !== []) {
				$declared[] = (string)$widget['id'];
			}
		}

		sort($declared);
		$answered = array_keys($map);
		sort($answered);

		$this->assertSame($declared, $answered);
	}//end testTheMapCoversExactlyTheDeclaredTiles()
}//end class
