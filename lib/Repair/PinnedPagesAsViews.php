<?php

/**
 * Dossiq Repair: menu pins of list pages that became views point at the views.
 *
 * Assigned to me was a page with a menu entry a user could pin; it is a view
 * of the case list now (lists-are-views). A pin of a menu entry that no longer
 * exists points at nothing, so this step adds the view the pin stood for to
 * the user's menu views preference, after the views they added themselves,
 * and takes the retired id out of their pins. A user who never set pins saw
 * the old default pins, so for them the old default stands in. The team
 * queue keeps its menu id (`Queue`, now leading to its view), so its pins
 * need nothing.
 *
 * ONCE PER USER, MARKED. A view a user takes out of their menu later must stay
 * out, so the step records that it ran for a user and never adds again.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseTypeViews;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Maps each user's pins of retired menu entries onto their menu views, once.
 *
 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
 */
class PinnedPagesAsViews implements IRepairStep {

	/**
	 * The user value that records the step ran for a user.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'pinned_pages_as_views';

	/**
	 * The user preference (PreferencesController's `pref_` namespace) the
	 * library keeps a user's pinned menu ids under.
	 *
	 * @var string
	 */
	public const PINNED_PREFERENCE = 'pref_cn_pinned_menu';

	/**
	 * The pins a user who never set any saw (the manifest's former
	 * `personalisation.pinnedMenu`).
	 *
	 * @var array<int, string>
	 */
	public const FORMER_DEFAULT_PINS = ['Queue', 'MyWork', 'Cases'];

	/**
	 * The retired menu id => the default view of the Cases page it became.
	 *
	 * @var array<string, array{slug: string, name: string}>
	 */
	public const RETIRED_PAGES = [
		'MyWork' => ['slug' => 'my-work', 'name' => 'Assigned to me'],
	];

	/**
	 * Users whose menu views were written in this run.
	 *
	 * @var integer
	 */
	private int $written = 0;

	/**
	 * Constructor.
	 *
	 * @param IConfig      $config      Per-user values.
	 * @param IUserManager $userManager The users to walk.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function getName(): string {
		return 'Point menu pins of Assigned to me at its view';
	}//end getName()

	/**
	 * Map every seen user's pins, once per user.
	 *
	 * @param IOutput $output Where progress is reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function run(IOutput $output): void {
		$this->written = 0;
		$this->userManager->callForSeenUsers(
			function (IUser $user): ?bool {
				$this->carryOver(userId: $user->getUID());
				return null;
			}
		);

		$output->info("PinnedPagesAsViews: pointed the pins of {$this->written} user(s) at their views.");
	}//end run()

	/**
	 * Map one user's pins of retired pages onto menu views, unless the step
	 * ran for them.
	 *
	 * @param string $userId The user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
	 */
	public function carryOver(string $userId): void {
		if ($this->config->getUserValue($userId, Application::APP_ID, self::DONE_KEY, '') !== '') {
			return;
		}

		$stored = $this->readList(userId: $userId, key: self::PINNED_PREFERENCE);
		$pins = $stored ?? self::FORMER_DEFAULT_PINS;
		$retired = array_values(array_filter($pins, static fn (mixed $pin): bool => is_string($pin) === true && isset(self::RETIRED_PAGES[$pin]) === true));

		if ($retired !== []) {
			$entries = array_values(array_filter(($this->readList(userId: $userId, key: CaseTypeViews::MENU_PREFERENCE) ?? []), 'is_array'));
			$present = array_map(static fn (array $entry): string => ($entry['page'] ?? '') . '|' . ($entry['id'] ?? ''), $entries);
			foreach ($retired as $pin) {
				$entry = ['id' => 'default:' . self::RETIRED_PAGES[$pin]['slug'], 'page' => CaseTypeViews::PAGE, 'name' => self::RETIRED_PAGES[$pin]['name']];
				if (in_array($entry['page'] . '|' . $entry['id'], $present, true) === false) {
					$entries[] = $entry;
					$present[] = $entry['page'] . '|' . $entry['id'];
				}
			}

			$this->config->setUserValue($userId, Application::APP_ID, CaseTypeViews::MENU_PREFERENCE, (string)json_encode($entries));
			$this->written++;
		}

		if ($stored !== null && $retired !== []) {
			$kept = array_values(array_filter($stored, static fn (mixed $pin): bool => in_array($pin, $retired, true) === false));
			$this->config->setUserValue($userId, Application::APP_ID, self::PINNED_PREFERENCE, (string)json_encode($kept));
		}

		$this->config->setUserValue($userId, Application::APP_ID, self::DONE_KEY, '1');
	}//end carryOver()

	/**
	 * A JSON list a user preference holds, or null when it holds none.
	 *
	 * @param string $userId The user.
	 * @param string $key    The preference key.
	 *
	 * @return array<int, mixed>|null The list.
	 */
	private function readList(string $userId, string $key): ?array {
		$stored = json_decode($this->config->getUserValue($userId, Application::APP_ID, $key, ''), true);
		if (is_array($stored) === false) {
			return null;
		}

		return array_values($stored);
	}//end readList()
}//end class
