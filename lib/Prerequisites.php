<?php

/**
 * What dossiq needs to run, declared once.
 *
 * 🔴 THIS FILE IS THE ONLY PLACE A PREREQUISITE IS WRITTEN DOWN. Before it
 * there were four, and they disagreed: `composer.json` required PHP 8.3 and
 * ext-zip, `appinfo/info.xml` declared PHP 8.3 and Nextcloud 32 to 34, the
 * README said Nextcloud 28 to 34, and nothing anywhere listed the sibling apps
 * dossiq wants. An administrator learned of a missing extension from a stack
 * trace. `PrerequisitesTest` parses the other three sources and holds each of
 * them to this array, so they cannot drift apart again.
 *
 * 🔴 EXT-MBSTRING WAS BEING USED AND NOT DECLARED. Nineteen files under `lib/`
 * call `mb_*`, and `composer.json` never asked for the extension. That is
 * exactly the failure this change exists to end, so the requirement is added
 * to `composer.json` in the same commit rather than quietly declared here:
 * a declaration composer does not enforce would let an install succeed and
 * then report itself broken on the settings page.
 *
 * @category Prerequisites
 * @package  OCA\Dossiq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq;

use OCP\App\IAppManager;

/**
 * The declaration, and a live reading of it.
 *
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */
final class Prerequisites {

	/**
	 * The lowest PHP this app runs on. Mirrored by `composer.json` `require.php`
	 * and by `<php min-version>` in `appinfo/info.xml`.
	 *
	 * @var string
	 */
	public const PHP_MIN = '8.3';

	/**
	 * The Nextcloud range, mirrored by `<nextcloud>` in `appinfo/info.xml`.
	 *
	 * @var string
	 */
	public const NEXTCLOUD_MIN = '32';

	/**
	 * The highest Nextcloud this app is tested against.
	 *
	 * @var string
	 */
	public const NEXTCLOUD_MAX = '34';

	/**
	 * The PHP extensions dossiq calls. Each one is also a `ext-*` line in
	 * `composer.json`, which is what actually refuses the install.
	 *
	 * @var array<string, string>
	 */
	public const EXTENSIONS = [
		'json' => 'Reads and writes every register payload and manifest.',
		'mbstring' => 'Counts and cuts text that is not plain ASCII, in 19 services.',
		'zip' => 'Packs and unpacks a case type export.',
	];

	/**
	 * The app dossiq cannot run without. Its absence is the one prerequisite
	 * that blocks rather than reports.
	 *
	 * @var array<string, string>
	 */
	public const APPS_REQUIRED = [
		'openregister' => 'Stores every case, case type and object dossiq owns.',
	];

	/**
	 * The apps dossiq works with when they are there, and what each one adds.
	 *
	 * 🔴 THE KEYS ARE APP IDS AND A RENAMED APP MOVES ON ITS OWN SCHEDULE.
	 * `IAppManager::isInstalled()` is a duck-typed lookup: pointing one at a
	 * name nothing answers to reports "missing" rather than failing, so a key
	 * changed ahead of the app it names would tell every administrator to
	 * install something they already have.
	 *
	 * @var array<string, string>
	 */
	public const APPS_OPTIONAL = [
		'openconnector' => 'Sends and receives over the municipal integrations.',
		'docudesk' => 'Generates and anonymises the documents on a case.',
		'hrmq' => 'Books the hours a handler writes on a case.',
		'decidesk' => 'Takes a decision that needs a committee.',
		'portaliq' => 'Shows the applicant their own case.',
		'pipelinq' => 'Runs the intake and routing pipelines.',
		'hermiq' => 'Answers the AI-assisted steps.',
		'nldesign' => 'Themes the app to the government design system.',
	];

	/**
	 * Read every prerequisite against this instance, right now.
	 *
	 * No cache. It runs on the admin settings page and nowhere else, so the
	 * cost is one page load, and a cached answer is exactly the wrong thing to
	 * show somebody who has just installed the extension they were told about.
	 *
	 * @param IAppManager   $appManager     Whether a sibling app is installed.
	 * @param callable|null $extensionLoaded Whether a PHP extension is loaded.
	 *                                       Injected so a test can report one
	 *                                       absent; defaults to the real thing.
	 * @param int|null      $phpVersionId   The running PHP, as PHP_VERSION_ID.
	 *
	 * @return array<string, mixed> The declaration with a `present` on every item.
	 *
	 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
	 */
	public function check(
		IAppManager $appManager,
		?callable $extensionLoaded = null,
		?int $phpVersionId = null,
	): array {
		$loaded = $extensionLoaded ?? static fn (string $name): bool => extension_loaded($name);
		$running = $phpVersionId ?? PHP_VERSION_ID;

		$extensions = [];
		foreach (self::EXTENSIONS as $name => $why) {
			$extensions[] = [
				'name' => $name,
				'why' => $why,
				'present' => (bool)$loaded($name),
			];
		}

		return [
			'php' => [
				'required' => self::PHP_MIN,
				'running' => PHP_VERSION,
				'present' => $running >= self::phpMinVersionId(),
			],
			'nextcloud' => [
				'min' => self::NEXTCLOUD_MIN,
				'max' => self::NEXTCLOUD_MAX,
			],
			'extensions' => $extensions,
			'apps' => [
				'required' => self::readApps($appManager, self::APPS_REQUIRED),
				'optional' => self::readApps($appManager, self::APPS_OPTIONAL),
			],
		];
	}//end check()

	/**
	 * PHP_VERSION_ID for the declared minimum.
	 *
	 * Computed from PHP_MIN rather than written twice, because two numbers
	 * that must agree and are typed separately eventually do not.
	 *
	 * @return int The minimum as PHP_VERSION_ID.
	 */
	public static function phpMinVersionId(): int {
		[$major, $minor] = array_pad(
			array_map('intval', explode('.', self::PHP_MIN)),
			2,
			0
		);

		return (($major * 10000) + ($minor * 100));
	}//end phpMinVersionId()

	/**
	 * Read one block of apps against the instance.
	 *
	 * @param IAppManager           $appManager Whether an app is installed.
	 * @param array<string, string> $declared   App id to what it adds.
	 *
	 * @return array<int, array<string, mixed>> One row per app.
	 */
	private static function readApps(IAppManager $appManager, array $declared): array {
		$rows = [];
		foreach ($declared as $appId => $unlocks) {
			$rows[] = [
				'id' => $appId,
				'unlocks' => $unlocks,
				// 🔴 A MISSING APP IS REPORTED, NEVER GUESSED AT. isInstalled()
				// can throw on a broken app directory, and a throw here would
				// take down the whole settings page over an optional sibling.
				'present' => self::isInstalled($appManager, $appId),
			];
		}

		return $rows;
	}//end readApps()

	/**
	 * Whether one app is installed, without letting a broken one take the page.
	 *
	 * @param IAppManager $appManager Whether an app is installed.
	 * @param string      $appId      The app id.
	 *
	 * @return bool True when the app is installed.
	 */
	private static function isInstalled(IAppManager $appManager, string $appId): bool {
		try {
			return $appManager->isInstalled($appId);
		} catch (\Throwable) {
			return false;
		}
	}//end isInstalled()
}//end class
