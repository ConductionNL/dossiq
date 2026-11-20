<?php

/**
 * Widget audience tests: the declared groups of a widget and who is in them.
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
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Dashboard\CasesOverviewWidget;
use OCA\Dossiq\Dashboard\DashboardWidgetList;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Dashboard\WidgetAudience;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for {@see WidgetAudience}.
 *
 * @covers \OCA\Dossiq\Service\Dashboard\WidgetAudience
 * @uses   \OCA\Dossiq\Dashboard\DashboardPageWidget
 * @uses   \OCA\Dossiq\Dashboard\CasesOverviewWidget
 * @uses   \OCA\Dossiq\Dashboard\DashboardWidgetList
 */
final class WidgetAudienceTest extends TestCase {

	/**
	 * The audience over a configured `widget_roles` value and a membership table.
	 *
	 * @param string                        $configured The `widget_roles` setting.
	 * @param array<string, list<string>>   $members    Group ids by user id.
	 *
	 * @return WidgetAudience The audience.
	 */
	private function audience(string $configured, array $members = []): WidgetAudience {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				WidgetAudience::SETTING => $configured,
				default => $default,
			}
		);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $groupId): bool => in_array($groupId, ($members[$userId] ?? []), true)
		);

		return new WidgetAudience(settings: $settings, groups: $groups);
	}//end audience()

	/**
	 * A configured list is the audience; blanks and non-strings are dropped.
	 *
	 * @return void
	 */
	public function testAConfiguredListIsTheAudience(): void {
		$audience = $this->audience(configured: '{"procest_cases_overview_widget": ["teamleiders", " ", 3, " kcc "]}');

		$this->assertSame(expected: ['teamleiders', 'kcc'], actual: $audience->groupsFor(widgetId: 'procest_cases_overview_widget'));
	}//end testAConfiguredListIsTheAudience()

	/**
	 * Unset, or unreadable, the shipped default applies: empty for every shipped widget.
	 *
	 * @return void
	 */
	public function testUnsetFallsBackToTheShippedDefault(): void {
		foreach (['', 'not json', '[]'] as $configured) {
			$this->assertSame(expected: [], actual: $this->audience(configured: $configured)->groupsFor(widgetId: 'dossiq_my_open_work_widget'));
		}
	}//end testUnsetFallsBackToTheShippedDefault()

	/**
	 * An empty list, or an unknown widget, admits everybody; a declared group admits only its members.
	 *
	 * @return void
	 */
	public function testAnEmptyListOrUnknownWidgetAdmitsEverybody(): void {
		$audience = $this->audience(
			configured: '{"procest_cases_overview_widget": [], "procest_stalled_cases_widget": ["teamleiders"]}',
			members: ['anna' => ['teamleiders'], 'bob' => ['kcc'], 'admin' => ['admin']]
		);

		$this->assertTrue(condition: $audience->admits(widgetId: 'procest_cases_overview_widget', userId: 'bob'));
		$this->assertTrue(condition: $audience->admits(widgetId: 'some_other_widget', userId: null));
		$this->assertTrue(condition: $audience->admits(widgetId: 'procest_stalled_cases_widget', userId: 'anna'));
		$this->assertFalse(condition: $audience->admits(widgetId: 'procest_stalled_cases_widget', userId: 'bob'));
		$this->assertFalse(condition: $audience->admits(widgetId: 'procest_stalled_cases_widget', userId: 'admin'));
		$this->assertFalse(condition: $audience->admits(widgetId: 'procest_stalled_cases_widget', userId: null));
	}//end testAnEmptyListOrUnknownWidgetAdmitsEverybody()

	/**
	 * Every widget the app contributes has a shipped entry, and all() lists them with their groups.
	 *
	 * @return void
	 */
	public function testEveryContributedWidgetShipsAnAudience(): void {
		$all = $this->audience(configured: '{"procest_start_case_widget": ["kcc"]}')->all();

		$ids = [];
		foreach ((new DashboardWidgetList())->classes() as $class) {
			$constant = (new ReflectionClass($class))->newInstanceWithoutConstructor()->getId();
			$ids[] = $constant;
		}

		sort($ids);
		$shipped = array_keys($all);
		sort($shipped);
		$this->assertSame(expected: $ids, actual: $shipped);
		$this->assertSame(expected: ['kcc'], actual: $all['procest_start_case_widget']);
	}//end testEveryContributedWidgetShipsAnAudience()

	/**
	 * The shared widget base asks the audience about the signed-in user, administrator or not.
	 *
	 * @return void
	 */
	public function testTheWidgetBaseIsEnabledOnlyForItsAudience(): void {
		$audience = $this->audience(
			configured: '{"procest_cases_overview_widget": ["teamleiders"]}',
			members: ['anna' => ['teamleiders'], 'admin' => ['admin']]
		);

		foreach (['anna' => true, 'admin' => false] as $uid => $expected) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session = $this->createMock(originalClassName: IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$widget = new CasesOverviewWidget(
				l10n: $this->createMock(originalClassName: IL10N::class),
				url: $this->createMock(originalClassName: IURLGenerator::class),
				audience: $audience,
				userSession: $session
			);

			$this->assertSame(expected: $expected, actual: $widget->isEnabled(), message: $uid);
		}
	}//end testTheWidgetBaseIsEnabledOnlyForItsAudience()
	/**
	 * Saving writes the shipped widgets only, splits text on commas, keeps a widget left out, and reads back.
	 *
	 * @return void
	 */
	public function testSaveStoresTheShippedWidgetsOnly(): void {
		$stored   = '{"procest_stalled_cases_widget": ["teamleiders"]}';
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use (&$stored): string {
				return $key === WidgetAudience::SETTING ? $stored : $default;
			}
		);
		$settings->expects($this->once())->method('setConfigValue')->willReturnCallback(
			static function (string $key, string $value) use (&$stored): void {
				$stored = $value;
			}
		);
		$audience = new WidgetAudience(settings: $settings, groups: $this->createMock(originalClassName: IGroupManager::class));

		$saved = $audience->save(
			audiences: [
				'procest_deadline_alerts_widget' => 'vergunningen, toezicht, ',
				'procest_cases_overview_widget'  => ['kcc', ''],
				'not_a_widget'                   => ['admin'],
			]
		);

		$this->assertSame(expected: ['vergunningen', 'toezicht'], actual: $saved['procest_deadline_alerts_widget']);
		$this->assertSame(expected: ['kcc'], actual: $saved['procest_cases_overview_widget']);
		$this->assertSame(expected: ['teamleiders'], actual: $saved['procest_stalled_cases_widget']);
		$this->assertArrayNotHasKey(key: 'not_a_widget', array: $saved);
		$this->assertStringNotContainsString(needle: 'not_a_widget', haystack: $stored);
	}//end testSaveStoresTheShippedWidgetsOnly()

	/**
	 * Saving an empty text opens a widget to everybody again.
	 *
	 * @return void
	 */
	public function testSavingAnEmptyTextOpensTheWidget(): void {
		$stored   = '{"procest_start_case_widget": ["kcc"]}';
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use (&$stored): string {
				return $key === WidgetAudience::SETTING ? $stored : $default;
			}
		);
		$settings->method('setConfigValue')->willReturnCallback(
			static function (string $key, string $value) use (&$stored): void {
				$stored = $value;
			}
		);
		$audience = new WidgetAudience(settings: $settings, groups: $this->createMock(originalClassName: IGroupManager::class));

		$saved = $audience->save(audiences: ['procest_start_case_widget' => '']);

		$this->assertSame(expected: [], actual: $saved['procest_start_case_widget']);
		$this->assertTrue(condition: $audience->admits(widgetId: 'procest_start_case_widget', userId: 'anyone'));
	}//end testSavingAnEmptyTextOpensTheWidget()
}//end class
