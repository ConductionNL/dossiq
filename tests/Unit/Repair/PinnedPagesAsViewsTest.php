<?php

/**
 * Tests for PinnedPagesAsViews.
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
 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\PinnedPagesAsViews;
use OCA\Dossiq\Service\CaseTypeViews;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Pins of the team queue and Assigned to me become menu views, once.
 *
 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
 */
class PinnedPagesAsViewsTest extends TestCase {

	private const QUEUE = ['id' => 'default:team-queue', 'page' => 'Cases', 'name' => 'Team queue'];

	private const MY_WORK = ['id' => 'default:my-work', 'page' => 'Cases', 'name' => 'Assigned to me'];

	/**
	 * User values, by user and key.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $values = [];

	/**
	 * A step over the given users.
	 *
	 * @param array<int, string> $users The seen users.
	 *
	 * @return PinnedPagesAsViews The step.
	 */
	private function step(array $users): PinnedPagesAsViews {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $user, string $app, string $key, string $default = ''): string => ($this->values[$user][$key] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $user, string $app, string $key, string $value): void {
				$this->values[$user][$key] = $value;
			}
		);

		$manager = $this->createMock(originalClassName: IUserManager::class);
		$manager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($users): void {
				foreach ($users as $uid) {
					$user = $this->createMock(originalClassName: IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			}
		);

		return new PinnedPagesAsViews(config: $config, userManager: $manager);
	}//end step()

	/**
	 * Run the step over the given users.
	 *
	 * @param array<int, string> $users The seen users.
	 *
	 * @return void
	 */
	private function runStep(array $users): void {
		$this->step(users: $users)->run(output: $this->createMock(originalClassName: IOutput::class));
	}//end runStep()

	/**
	 * A JSON list a user holds under a key.
	 *
	 * @param string $user The user.
	 * @param string $key  The key.
	 *
	 * @return array<int, mixed>|null The list, or null when unset.
	 */
	private function stored(string $user, string $key): ?array {
		if (isset($this->values[$user][$key]) === false) {
			return null;
		}

		return (array)json_decode(json: $this->values[$user][$key], associative: true);
	}//end stored()

	/**
	 * Pins of the retired pages land after the user's own views, in pin
	 * order, and leave the pinned list; other pins stay.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function testPinsBecomeViewsAfterTheUsersOwn(): void {
		$own = ['id' => 'view-1', 'page' => 'Cases', 'name' => 'Mine'];
		$this->values['anna'][CaseTypeViews::MENU_PREFERENCE] = (string)json_encode([$own]);
		$this->values['anna'][PinnedPagesAsViews::PINNED_PREFERENCE] = (string)json_encode(['MyWork', 'Tasks', 'Queue']);

		$this->runStep(users: ['anna']);

		$this->assertSame(expected: [$own, self::MY_WORK, self::QUEUE], actual: $this->stored(user: 'anna', key: CaseTypeViews::MENU_PREFERENCE));
		$this->assertSame(expected: ['Tasks'], actual: $this->stored(user: 'anna', key: PinnedPagesAsViews::PINNED_PREFERENCE));
	}//end testPinsBecomeViewsAfterTheUsersOwn()

	/**
	 * A user who never set pins saw the former default, so both views are
	 * added and no pinned list is written for them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function testAUserWithoutPinsGetsTheFormerDefault(): void {
		$this->runStep(users: ['bert']);

		$this->assertSame(expected: [self::QUEUE, self::MY_WORK], actual: $this->stored(user: 'bert', key: CaseTypeViews::MENU_PREFERENCE));
		$this->assertNull(actual: $this->stored(user: 'bert', key: PinnedPagesAsViews::PINNED_PREFERENCE));
	}//end testAUserWithoutPinsGetsTheFormerDefault()

	/**
	 * A user who unpinned both pages gets no views, and nothing is written
	 * but the marker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function testAUserWhoUnpinnedThemGetsNothing(): void {
		$this->values['cor'][PinnedPagesAsViews::PINNED_PREFERENCE] = (string)json_encode(['Cases']);

		$this->runStep(users: ['cor']);

		$this->assertNull(actual: $this->stored(user: 'cor', key: CaseTypeViews::MENU_PREFERENCE));
		$this->assertSame(expected: ['Cases'], actual: $this->stored(user: 'cor', key: PinnedPagesAsViews::PINNED_PREFERENCE));
		$this->assertSame(expected: '1', actual: $this->values['cor'][PinnedPagesAsViews::DONE_KEY]);
	}//end testAUserWhoUnpinnedThemGetsNothing()

	/**
	 * A view the user already has in the menu is not added twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function testAViewAlreadyInTheMenuIsNotDuplicated(): void {
		$this->values['dirk'][CaseTypeViews::MENU_PREFERENCE] = (string)json_encode([self::QUEUE]);
		$this->values['dirk'][PinnedPagesAsViews::PINNED_PREFERENCE] = (string)json_encode(['Queue']);

		$this->runStep(users: ['dirk']);

		$this->assertSame(expected: [self::QUEUE], actual: $this->stored(user: 'dirk', key: CaseTypeViews::MENU_PREFERENCE));
		$this->assertSame(expected: [], actual: $this->stored(user: 'dirk', key: PinnedPagesAsViews::PINNED_PREFERENCE));
	}//end testAViewAlreadyInTheMenuIsNotDuplicated()

	/**
	 * A second run changes nothing: a view the user took out of the menu
	 * after the first run stays out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function testASecondRunChangesNothing(): void {
		$this->values['eva'][PinnedPagesAsViews::PINNED_PREFERENCE] = (string)json_encode(['Queue', 'MyWork']);
		$this->runStep(users: ['eva']);

		$this->values['eva'][CaseTypeViews::MENU_PREFERENCE] = (string)json_encode([self::MY_WORK]);
		$before = $this->values['eva'];
		$this->runStep(users: ['eva']);

		$this->assertSame(expected: $before, actual: $this->values['eva']);
	}//end testASecondRunChangesNothing()
}//end class
