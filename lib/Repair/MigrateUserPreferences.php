<?php

/**
 * Dossiq Migrate User Preferences Repair Step
 *
 * Repair step that carries this app's per-user preferences across the
 * `procest` -> `dossiq` app-id rename.
 *
 * WHY THIS EXISTS SEPARATELY FROM MigrateAppConfigKeys. `IAppConfig` and
 * `IConfig`'s user values are different stores: the former is `oc_appconfig`,
 * the latter `oc_preferences`. Both are namespaced by app id, so both are cut
 * off by the rename, but copying one does nothing for the other.
 *
 * DOES THIS APP HAVE PER-USER KEYS AT ALL? Yes. `appinfo/routes.php` registers
 * `preferences#getPreference` (GET) and `preferences#setPreference` (PUT) on
 * `/api/preferences/{key}` — both locally and through the OpenRegister AppHost
 * canonical route table (ADR-040). The shared @conduction/nextcloud-vue
 * manifest renderer writes per-viewer UI state through them (saved table
 * columns, view mode, sort, filters, collapsed panels, dismissed one-time
 * notices). This app's own `src/` never calls the endpoint directly, which is
 * exactly why it is easy to conclude — wrongly — that there is nothing to
 * migrate: the writer is the shared library, not this repository.
 *
 * WHY IT ENUMERATES BY USER RATHER THAN BY VALUE. `IConfig` offers no "list
 * every key this app stored for every user" call. It does offer
 * `getUsersForUserValue(app, key, value)`, which requires the caller to name
 * the key AND the concrete value up front. That is exhaustive only for a closed
 * key set with closed values, and this app has neither: the route's `{key}` is
 * a free path parameter, so the key space is open by construction, and the
 * values are arbitrary JSON-ish strings (a column list, a sort field, a view
 * mode). Driving the migration from `getUsersForUserValue()` here would report
 * success while migrating NOTHING, because no finite (key, value) list can be
 * written down. Enumerating users and asking `IConfig::getUserKeys()` what that
 * user actually stored is exhaustive for open and closed sets alike and — like
 * MigrateAppConfigKeys' use of `getKeys()` — cannot drift when a future release
 * or a nextcloud-vue upgrade adds a preference.
 *
 * `callForSeenUsers()` rather than `callForAllUsers()`: a stored preference is
 * written from the app's own UI, which requires a login, so a user with
 * anything in `oc_preferences` for this app has necessarily been seen. The
 * seen-user walk reads the same table and avoids a full backend enumeration
 * (LDAP included) on every install.
 *
 * WHY A LOST PREFERENCE IS INVISIBLE. Every reader supplies a default, so a
 * miss does not error — the user's saved table layout, sort and filters simply
 * revert to the app defaults and their dismissed notices come back, as though
 * they had never configured anything. There is no log line and no failed
 * request. That is the whole reason this needs a migration rather than a
 * release note.
 *
 * WHAT THIS STEP DOES NOT FIX. Nextcloud's Dashboard app stores its per-user
 * widget layout under its OWN app id (`dashboard`), keyed by widget id, so this
 * step cannot reach it. That is why the seven widget ids in `lib/Dashboard/`
 * are frozen at `procest_*_widget` rather than renamed — see the comments
 * there.
 *
 * SAFETY. Idempotent and non-destructive, matching MigrateAppConfigKeys:
 *   - a value is copied only when the user has nothing stored under the new
 *     app id, so a preference changed after the rename is never clobbered and
 *     a second run is a no-op;
 *   - the old `procest` rows are never deleted, so a rollback still finds them;
 *   - the key NAMES are copied verbatim: no per-user key in this app embeds the
 *     app id, so there is nothing to rewrite;
 *   - BOTH the read and the write sit inside the try. A throwing read would
 *     otherwise escape `run()`, and because this step is registered under
 *     `<install>` — the only hook that fires on the fresh install the rename
 *     actually performs — an escaping throw aborts the install and the app
 *     never enables at all, taking every route with it.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` alongside MigrateAppConfigKeys — see the ordering comment
 * there. No other repair step reads or writes user values, so this one has no
 * ordering constraint of its own beyond running before anything that might.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude No canonical spec covers the procest -> dossiq app-id rename;
 *  pointing this at an existing spec would report conformance to a requirement
 *  that says nothing about it.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy per-user preferences from the procest app id to dossiq.
 *
 * @spec exclude One-off procest -> dossiq app-id rename plumbing.
 */
class MigrateUserPreferences implements IRepairStep {

	/**
	 * The `oc_preferences` namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id — see MigrateAppConfigKeys::OLD_APP_ID.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'procest';

	/**
	 * Number of preferences copied during this run.
	 *
	 * Held as state rather than passed around because the walk happens inside
	 * a closure handed to IUserManager::callForSeenUsers(), which returns
	 * nothing and cannot thread a running total back out.
	 *
	 * @var int
	 */
	private int $migrated = 0;

	/**
	 * Number of preferences already present under the new app id.
	 *
	 * @var int
	 */
	private int $alreadyPresent = 0;

	/**
	 * Number of preferences that could not be copied.
	 *
	 * @var int
	 */
	private int $failed = 0;

	/**
	 * Constructor for MigrateUserPreferences.
	 *
	 * @param IConfig $config The user-value store to read and write.
	 * @param IUserManager $userManager The user enumeration backend.
	 * @param LoggerInterface $logger Logger for preferences that fail to copy.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec exclude One-off procest -> dossiq app-id rename plumbing; no
	 *  capability spec describes the rename, and pointing this at an existing
	 *  one would claim conformance to a requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Copy Dossiq per-user preferences from the procest app id to dossiq';
	}//end getName()

	/**
	 * Copy every stored per-user preference from the old app id to the new one.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude One-off procest -> dossiq app-id rename plumbing: it moves
	 *  oc_preferences rows between namespaces and adds no behaviour of its own.
	 *  The preferences it preserves are specified where they are read.
	 */
	public function run(IOutput $output): void {
		$this->migrated = 0;
		$this->alreadyPresent = 0;
		$this->failed = 0;

		try {
			// The callback returns null rather than void: IUserManager treats a
			// `false` return as "stop iterating", so the contract is
			// Closure(IUser): (bool|null) and null means "keep going".
			$this->userManager->callForSeenUsers(
				function (IUser $user): ?bool {
					$this->migrateUser(userId: $user->getUID());
					return null;
				}
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not enumerate users; per-user preferences were not migrated',
				['exception' => $e->getMessage()]
			);
			$output->warning(
				'MigrateUserPreferences: user enumeration failed; preferences left under the procest app id.'
			);
			return;
		}//end try

		if ($this->migrated === 0 && $this->alreadyPresent === 0 && $this->failed === 0) {
			$output->info(
				'MigrateUserPreferences: no stored procest user preferences on this install; nothing to do.'
			);
			return;
		}

		$output->info(
			'MigrateUserPreferences: migrated ' . $this->migrated . ' preference(s); '
			. $this->alreadyPresent . ' already set under dossiq; ' . $this->failed . ' failed.'
		);

	}//end run()

	/**
	 * Copy one user's stored preferences from the old app id to the new one.
	 *
	 * @param string $userId The Nextcloud user ID.
	 *
	 * @return void
	 */
	private function migrateUser(string $userId): void {
		foreach ($this->oldKeysFor(userId: $userId) as $key) {
			// Read and write both inside the try — see the class docblock.
			try {
				$old = $this->config->getUserValue($userId, self::OLD_APP_ID, $key, '');
				if ($old === '') {
					continue;
				}

				$existing = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
				if ($existing !== '') {
					$this->alreadyPresent++;
					continue;
				}

				$this->config->setUserValue($userId, Application::APP_ID, $key, $old);
				$this->migrated++;
			} catch (Throwable $e) {
				$this->failed++;
				$this->logger->warning(
					'Dossiq: could not migrate one user preference; leaving it under the old app id',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

	}//end migrateUser()

	/**
	 * Every preference key this user has stored under the old app id.
	 *
	 * @param string $userId The Nextcloud user ID.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 */
	private function oldKeysFor(string $userId): array {
		try {
			return $this->config->getUserKeys($userId, self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not enumerate dossiq preference keys for a user; skipping that user',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end oldKeysFor()

}//end class
