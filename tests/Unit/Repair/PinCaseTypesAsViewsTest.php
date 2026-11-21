<?php

/**
 * Tests for PinCaseTypesAsViews.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\PinCaseTypesAsViews;
use OCA\Dossiq\Service\CaseTypeViews;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The pins of the retired My case types menu become menu views, once.
 *
 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
 */
class PinCaseTypesAsViewsTest extends TestCase {

	/**
	 * The case types the register holds.
	 */
	private const CASE_TYPES = [
		['id' => MenuCaseTypesService::DEFAULT_CASE_TYPE, 'title' => 'Woo-verzoek'],
		['id' => 'uuid-b', 'title' => 'Bezwaar'],
		['id' => 'uuid-k', 'title' => 'Klacht'],
	];

	/**
	 * User values, by user and key.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $values = [];

	/**
	 * A step over the given pins per user, with the given case types.
	 *
	 * @param array<string, array<int, array{id: string, title: string}>> $pins      The case types each user pinned.
	 * @param bool                                                       $readable  Whether the case types can be read.
	 *
	 * @return PinCaseTypesAsViews The step.
	 */
	private function step(array $pins, bool $readable = true): PinCaseTypesAsViews {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $user, string $app, string $key, string $default = ''): string => ($this->values[$user][$key] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $user, string $app, string $key, string $value): void {
				$this->values[$user][$key] = $value;
			}
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($pins): void {
				foreach (array_keys($pins) as $uid) {
					$user = $this->createMock(originalClassName: IUser::class);
					$user->method('getUID')->willReturn((string)$uid);
					$callback($user);
				}
			}
		);

		$menuCaseTypes = $this->createMock(originalClassName: MenuCaseTypesService::class);
		if ($readable === true) {
			$menuCaseTypes->method('visibleCaseTypes')->willReturn(self::CASE_TYPES);
		} else {
			$menuCaseTypes->method('visibleCaseTypes')->willThrowException(new RuntimeException('register offline'));
		}

		$menuCaseTypes->method('chosen')->willReturnCallback(
			static fn (string $userId, array $visible): array => ($pins[$userId] ?? [])
		);

		return new PinCaseTypesAsViews(
			config: $config,
			userManager: $users,
			menuCaseTypes: $menuCaseTypes,
			caseTypeViews: new CaseTypeViews(),
			settings: $this->createMock(originalClassName: SettingsService::class),
			logger: new NullLogger(),
		);
	}//end step()

	/**
	 * The menu views stored for a user.
	 *
	 * @param string $user The user.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function menu(string $user): array {
		return (array)json_decode(json: ($this->values[$user][CaseTypeViews::MENU_PREFERENCE] ?? '[]'), associative: true);
	}//end menu()

	/**
	 * A user's pins land after the views they added themselves, in their order;
	 * the Woo request case type maps onto the view the page ships.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function testPinsBecomeMenuViewsAfterTheUsersOwn(): void {
		$own = ['id' => 'view-1', 'page' => 'Cases', 'name' => 'Mine'];
		$this->values['anna'][CaseTypeViews::MENU_PREFERENCE] = (string)json_encode([$own]);

		$this->step(pins: ['anna' => [self::CASE_TYPES[1], self::CASE_TYPES[0]]])->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(
			expected: [
				$own,
				['id' => 'default:case-type-uuid-b', 'page' => 'Cases', 'name' => 'Bezwaar'],
				['id' => 'default:woo-requests', 'page' => 'Cases', 'name' => 'Woo-verzoek'],
			],
			actual: $this->menu(user: 'anna')
		);
		$this->assertSame(expected: '1', actual: $this->values['anna'][PinCaseTypesAsViews::DONE_KEY]);
		$this->assertArrayNotHasKey(
			key: MenuCaseTypesService::USER_KEY,
			array: $this->values['anna'],
			message: 'the old list is kept as it was, not rewritten'
		);
	}//end testPinsBecomeMenuViewsAfterTheUsersOwn()

	/**
	 * The step runs once per user: a view taken out of the menu afterwards stays out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function testAViewTakenOutAfterwardsStaysOut(): void {
		$step = $this->step(pins: ['anna' => [self::CASE_TYPES[2]]]);
		$step->run(output: $this->createMock(originalClassName: IOutput::class));
		$this->values['anna'][CaseTypeViews::MENU_PREFERENCE] = '[]';

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $this->menu(user: 'anna'));
	}//end testAViewTakenOutAfterwardsStaysOut()

	/**
	 * A user who pinned nothing gets no menu views written, and is marked done.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function testAUserWithoutPinsGetsNothing(): void {
		$this->step(pins: ['bram' => []])->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertArrayNotHasKey(key: CaseTypeViews::MENU_PREFERENCE, array: $this->values['bram']);
		$this->assertSame(expected: '1', actual: $this->values['bram'][PinCaseTypesAsViews::DONE_KEY]);
	}//end testAUserWithoutPinsGetsNothing()

	/**
	 * Unreadable case types write nothing and mark nobody, so the next upgrade tries again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function testUnreadableCaseTypesLeaveEveryoneForTheNextRun(): void {
		$this->step(pins: ['anna' => [self::CASE_TYPES[1]]], readable: false)->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $this->values);
	}//end testUnreadableCaseTypesLeaveEveryoneForTheNextRun()
}//end class
