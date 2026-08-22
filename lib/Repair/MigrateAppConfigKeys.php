<?php

/**
 * Dossiq Migrate App Config Keys Repair Step
 *
 * Repair step that carries this app's stored `IAppConfig` values across the
 * `procest` -> `dossiq` app-id rename.
 *
 * Nextcloud namespaces `IAppConfig` by app id at the storage layer
 * (`oc_appconfig.appid`), so renaming `<id>` does not rename the rows — it makes
 * every previously stored value unreachable, because the app now asks for them
 * under a different app id. There is no in-place app-id upgrade in Nextcloud:
 * the new id is simply a different app. This step therefore copies each value
 * from the old namespace to the new one.
 *
 * WHAT IS ACTUALLY AT STAKE. Every reader here supplies a default, so a lost
 * value does not error — it reverts, silently, with no log line to notice:
 *   - `register` holds the id of the imported OpenRegister register. Lose it
 *     and `SettingsService::getConfigValue('register')` returns `''`, which
 *     `LoadDefaultZgwMappings` and `ZgwMappingController` both read as "no
 *     register configured" and return early. The ZGW mapping surface goes
 *     quiet rather than broken.
 *   - `dwangsom_callback_secret` is the HMAC key
 *     `DwangsomPaymentCallbackController` validates the external payment
 *     provider's `X-Procest-Signature` against. Lose it and every inbound
 *     callback starts failing signature validation.
 *   - the `email_imap_*` block is the shared-mailbox connection, `ai_*` the AI
 *     feature flags and credentials (including `ai_dpia_acknowledged`, a
 *     compliance acknowledgement an admin made once), and
 *     `setup_completed_version` / `setup_seed_done` are the first-time-setup
 *     markers — losing those last two re-runs the setup wizard at an admin who
 *     already completed it.
 *
 * WHY EVERY KEY RATHER THAN A FIXED LIST. `SettingsService` writes the admin
 * settings, but that is not the whole stored set — the register import records
 * the imported register/schema ids, `MigrateArchivalToOpenRegister` writes its
 * own completion marker, and past releases have written keys this app no longer
 * reads. Enumerating `IAppConfig::getKeys()` is exhaustive by construction and
 * cannot drift out of date the way a hardcoded list does.
 *
 * THE KEY NAMES ARE COPIED VERBATIM. No app config key in this app embeds the
 * app id — they are all bare names like `register`, `ai_enabled`,
 * `email_imap_host`, `setup_seed_done` — so there is no key-name prefix to
 * rewrite. (Had there been one, rewriting the NAME as well as moving the row
 * would be mandatory: a value copied under a name nothing reads is the same
 * silent loss this step exists to prevent.)
 *
 * ORDERING. Registered BEFORE `InitializeSettings` in both blocks of
 * `appinfo/info.xml`, and that order is load-bearing: `InitializeSettings` and
 * the register-import steps below it write configuration keys themselves, so
 * running them first would leave those keys already present under `dossiq` and
 * this step would skip them as "already present", stranding the old values.
 *
 * NOTHING HERE TOUCHES THE REGISTER SLUG. The OpenRegister register slug stays
 * `procest` across this rename (see the note in `appinfo/info.xml`); this step
 * moves the app's own configuration namespace only.
 *
 * SAFETY. Idempotent and non-destructive:
 *   - a key is copied only when the old value is non-empty AND the new
 *     namespace does not already hold a value, so an admin edit made after the
 *     rename is never clobbered and a second run is a no-op;
 *   - the old `procest` rows are never deleted, so a rollback to the previous
 *     app id still finds its configuration intact;
 *   - values round-trip as raw strings. `IAppConfig` stores every value as a
 *     string and the typed accessors only coerce on read, so a string
 *     round-trip cannot lose or corrupt a value written by a typed setter;
 *   - BOTH the reads and the write sit inside the try, not just the write. A
 *     throwing read would otherwise escape `run()`, and because this step is
 *     registered under `<install>` — the only hook that fires on the fresh
 *     install the rename actually performs — an escaping throw aborts the
 *     install and the app never enables at all. Every route in the app dies
 *     with it. One unreadable config value is not worth that.
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
 *  that says nothing about it. The settings it preserves are specified where
 *  they are read.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy every stored IAppConfig value from the procest namespace to dossiq.
 *
 * @spec exclude One-off procest -> dossiq app-id rename plumbing.
 */
class MigrateAppConfigKeys implements IRepairStep {

	/**
	 * The app-config namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `procest`.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'procest';

	/**
	 * Config keys Nextcloud owns for every app. These MUST NOT be copied.
	 *
	 * `AppManager::enableApp()` writes `enabled` through the deprecated
	 * `IAppConfig::setValue()`, which stores type MIXED. Copying it here with
	 * `setValueString()` stores type STRING, and the next `app:enable` then
	 * fails with an `AppConfigTypeConflictException` — permanently, because the
	 * conflict is hit before the app can run anything that would repair it.
	 * `installed_version` and `types` are Nextcloud's own bookkeeping for the
	 * app, and copying the old app's values would misreport the new one.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	/**
	 * Constructor for MigrateAppConfigKeys.
	 *
	 * @param IAppConfig      $appConfig The app config store to read and write.
	 * @param LoggerInterface $logger    Logger for keys that fail to copy.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
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
		return 'Copy Dossiq app configuration from the procest namespace to dossiq';

	}//end getName()

	/**
	 * Run the repair step to migrate the stored app configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude One-off procest -> dossiq app-id rename plumbing: it moves
	 *  oc_appconfig rows between namespaces and adds no behaviour of its own.
	 *  The settings it preserves are specified where they are read.
	 */
	public function run(IOutput $output): void {
		$keys = $this->oldKeys();
		if ($keys === []) {
			$output->info(
				'MigrateAppConfigKeys: no stored procest configuration on this install; nothing to do.'
			);
			return;
		}

		$migrated = 0;
		$alreadyPresent = 0;
		$emptySource = 0;
		$skippedReserved = 0;
		$failed = 0;

		foreach ($keys as $key) {
			if (in_array($key, self::RESERVED_KEYS, strict: true) === true) {
				$skippedReserved++;
				continue;
			}

			// The READS live inside the try alongside the write, deliberately.
			// A throwing getValueString() outside it would escape run() and
			// abort the install — see the class docblock.
			try {
				$old = $this->appConfig->getValueString(self::OLD_APP_ID, $key, '');
				if ($old === '') {
					$emptySource++;
					continue;
				}

				$existing = $this->appConfig->getValueString(Application::APP_ID, $key, '');
				if ($existing !== '') {
					$alreadyPresent++;
					continue;
				}

				$this->appConfig->setValueString(Application::APP_ID, $key, $old);
				$migrated++;
			} catch (Throwable $e) {
				$failed++;
				$this->logger->warning(
					'Dossiq: could not migrate one app config key; leaving it under the old namespace',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			'MigrateAppConfigKeys: ' . $migrated . ' key(s) migrated, ' . $alreadyPresent
			. ' already present, ' . $emptySource . ' had no value to migrate, '
			. $skippedReserved . ' skipped as Nextcloud-reserved, ' . $failed . ' failed.'
		);

	}//end run()

	/**
	 * Every key currently stored under the old app-config namespace.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 */
	private function oldKeys(): array {
		try {
			return $this->appConfig->getKeys(self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not enumerate procest app config keys; skipping the migration',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end oldKeys()

}//end class
