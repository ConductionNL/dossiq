<?php

/**
 * Dossiq Retire IMAP Credentials Repair Step
 *
 * Deletes the mailbox credentials dossiq used to store, now that Nextcloud Mail
 * owns the account (design D-2).
 *
 * 🔴 THE UPGRADE DELETES THE PASSWORD RATHER THAN MIGRATING IT. That is the
 * whole point of decision D12: the credential LEAVES dossiq, it does not move
 * inside it. A password nobody uses is still a password in a database backup,
 * in an `occ config:list` dump, and in whatever the last export before the
 * upgrade captured.
 *
 * WHICH KEYS GO, AND WHY THESE. `email_imap_password`, `email_imap_username`
 * and `email_imap_host` are the three the change names. `email_imap_port` and
 * `email_imap_encryption` go with them because they are the same connection and
 * mean nothing without a host: leaving them behind would leave an admin
 * settings surface offering half a form that configures nothing.
 * `email_imap_folder` STAYS, because which folder intake reads is still dossiq's
 * question and an instance that named one keeps reading the same one.
 *
 * IT IS IDEMPOTENT AND IT NEVER THROWS. Repair steps run on every upgrade, and
 * a step registered under `<install>` that throws aborts the install and takes
 * every route in the app with it. One unreadable config value is not worth that.
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
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
 * Removes the stored IMAP connection and its password.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class RetireImapCredentials implements IRepairStep {

	/**
	 * The keys this step deletes.
	 *
	 * @var string[]
	 */
	public const RETIRED_KEYS = [
		'email_imap_password',
		'email_imap_username',
		'email_imap_host',
		'email_imap_port',
		'email_imap_encryption',
	];

	/**
	 * The app id this app stored its configuration under before the rename.
	 *
	 * Deliberately the OLD id. A credential left behind in the `procest`
	 * namespace is exactly as readable in a database backup as one under
	 * `dossiq`, and {@see MigrateAppConfigKeys} copies every key forward, so
	 * this step has to reach both namespaces or it deletes a copy and leaves
	 * the original.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'procest';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig Instance configuration.
	 * @param LoggerInterface $logger    Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name shown while the step runs.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function getName(): string {
		return 'Remove the stored mailbox password now that Nextcloud Mail holds the account';
	}//end getName()

	/**
	 * Delete the retired keys from both app-config namespaces.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function run(IOutput $output): void {
		$removed = 0;
		foreach ([Application::APP_ID, self::OLD_APP_ID] as $appId) {
			$removed += $this->removeFrom(appId: $appId);
		}

		if ($removed === 0) {
			return;
		}

		$output->info('Removed ' . $removed . ' stored mailbox connection values.');
		$this->logger->info(
			'Dossiq: removed {count} stored mailbox connection values; Nextcloud Mail holds the account now',
			['count' => $removed, 'app' => Application::APP_ID]
		);
	}//end run()

	/**
	 * Delete the retired keys from one app-config namespace.
	 *
	 * @param string $appId The namespace.
	 *
	 * @return integer How many values were removed.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function removeFrom(string $appId): int {
		$removed = 0;
		foreach (self::RETIRED_KEYS as $key) {
			try {
				if ($this->appConfig->hasKey($appId, $key) === false) {
					continue;
				}

				$this->appConfig->deleteKey($appId, $key);
				$removed++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not remove the stored value for {key}',
					['key' => $key, 'namespace' => $appId, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return $removed;
	}//end removeFrom()
}//end class
