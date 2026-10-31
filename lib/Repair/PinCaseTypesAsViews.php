<?php

/**
 * Dossiq Repair: the case types a user pinned become views in their menu.
 *
 * The "My case types" section of dossiq's menu is gone (Ruben, 19 October 2026):
 * a pinned case type is a view of the case list, and the library's "My views"
 * section at the bottom of the menu lists the views a user added. This step
 * carries each user's pins over once, so nothing a user chose is lost: every
 * case type in their `menu_case_types` list (or the Woo request case type, the
 * one a user who never chose saw) is added to their menu views preference, in
 * their order, after whatever views they added themselves.
 *
 * ONCE PER USER, MARKED. A view a user takes out of their menu later must stay
 * out, so the step records that it ran for a user and never adds again.
 * `menu_case_types` is kept: the manifest delta still reads it to offer the
 * per-user views, and nothing is deleted.
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
 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseTypeViews;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds each user's pinned case types to their menu views, once.
 *
 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
 */
class PinCaseTypesAsViews implements IRepairStep {

	use RunsUnderSystemIdentity;

	/**
	 * The user value that records the step ran for a user.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'menu_case_types_as_views';

	/**
	 * Users whose views were written in this run.
	 *
	 * @var integer
	 */
	private int $written = 0;

	/**
	 * Constructor.
	 *
	 * @param IConfig              $config        Per-user values.
	 * @param IUserManager         $userManager   The users to walk.
	 * @param MenuCaseTypesService $menuCaseTypes The case types a user pinned.
	 * @param CaseTypeViews        $caseTypeViews The view and menu entry per case type.
	 * @param SettingsService      $settings      OpenRegister, to read the case types as the system.
	 * @param LoggerInterface      $logger        Where a failure is written.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly MenuCaseTypesService $menuCaseTypes,
		private readonly CaseTypeViews $caseTypeViews,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function getName(): string {
		return 'Add the case types each user pinned to their menu views';
	}//end getName()

	/**
	 * Carry every seen user's pins over, once per user.
	 *
	 * @param IOutput $output Where progress is reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function run(IOutput $output): void {
		$this->written = 0;
		$caseTypes = [];
		try {
			// A repair step has no user: the case types are read as the system,
			// or the read is refused as anonymous and looks like an empty register.
			$this->withSystemIdentity(
				objectService: $this->settings->getObjectService(),
				work: function () use (&$caseTypes): void {
					$caseTypes = $this->menuCaseTypes->visibleCaseTypes();
				}
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: case types unreadable; pinned case types not yet added to menu views', ['exception' => $e->getMessage()]);
			$output->warning('PinCaseTypesAsViews: case types unreadable; will try again on the next upgrade.');
			return;
		}

		if ($caseTypes === []) {
			$output->info('PinCaseTypesAsViews: no case types yet; nothing to carry over.');
			return;
		}

		$this->userManager->callForSeenUsers(
			function (IUser $user) use ($caseTypes): ?bool {
				$this->carryOver(userId: $user->getUID(), caseTypes: $caseTypes);
				return null;
			}
		);

		$output->info("PinCaseTypesAsViews: added pinned case types to the menu views of {$this->written} user(s).");
	}//end run()

	/**
	 * Add one user's pins to their menu views, unless the step ran for them.
	 *
	 * @param string                                       $userId    The user.
	 * @param array<int, array{id: string, title: string}> $caseTypes Every case type, for the names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function carryOver(string $userId, array $caseTypes): void {
		if ($this->config->getUserValue($userId, Application::APP_ID, self::DONE_KEY, '') !== '') {
			return;
		}

		$pinned = $this->menuCaseTypes->chosen(userId: $userId, visible: $caseTypes);
		$entries = $this->existingEntries(userId: $userId);
		$present = array_map(static fn (array $entry): string => ($entry['page'] ?? '') . '|' . ($entry['id'] ?? ''), $entries);
		foreach ($this->caseTypeViews->menuEntries(caseTypes: $pinned) as $entry) {
			if (in_array($entry['page'] . '|' . $entry['id'], $present, true) === false) {
				$entries[] = $entry;
			}
		}

		if ($pinned !== []) {
			$this->config->setUserValue($userId, Application::APP_ID, CaseTypeViews::MENU_PREFERENCE, (string)json_encode(array_values($entries)));
			$this->written++;
		}

		$this->config->setUserValue($userId, Application::APP_ID, self::DONE_KEY, '1');
	}//end carryOver()

	/**
	 * The views a user already added to their menu.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function existingEntries(string $userId): array {
		$stored = json_decode($this->config->getUserValue($userId, Application::APP_ID, CaseTypeViews::MENU_PREFERENCE, ''), true);
		if (is_array($stored) === false) {
			return [];
		}

		return array_values(array_filter($stored, 'is_array'));
	}//end existingEntries()
}//end class
