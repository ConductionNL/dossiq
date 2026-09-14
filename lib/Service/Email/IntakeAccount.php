<?php

/**
 * Dossiq Intake Account
 *
 * Which Nextcloud Mail account and which of its folders dossiq reads: the
 * tenant's declared intake account, picked by an administrator.
 *
 * 🔴 THIS REPLACES A STORED PASSWORD. dossiq used to hold an IMAP host, a
 * username and a password in appconfig and open `imap_open()` with them. The
 * account moves to Nextcloud Mail, which already owns the credential and the
 * OAuth 2.0 flow, so what is left here is an id and a folder name: nothing that
 * is a secret in a database backup (design D-2).
 *
 * IT IS ONE ACCOUNT, DELIBERATELY. Nextcloud Mail synchronises every account of
 * every user, and dossiq must read exactly the functional mailbox it was
 * pointed at. Anything wider turns a colleague's inbox into a case intake.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The mail account and folder intake reads.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class IntakeAccount {

	/**
	 * The app-config key holding the picked account id.
	 */
	public const ACCOUNT_KEY = 'email_mail_account_id';

	/**
	 * The app-config key holding the folder intake reads.
	 *
	 * Kept under its old name so that an instance that already named a folder
	 * keeps reading the same one. The `imap` in it is now inaccurate, and a
	 * rename would be a migration paid for nothing.
	 */
	public const FOLDER_KEY = 'email_imap_folder';

	/**
	 * The folder intake reads when the instance names none.
	 */
	public const DEFAULT_FOLDER = 'INBOX';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Instance configuration.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The account an administrator picked.
	 *
	 * @return integer The account id, or 0 when none is picked.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function accountId(): int {
		return (int)$this->appConfig->getValueString(Application::APP_ID, self::ACCOUNT_KEY, '0');
	}//end accountId()

	/**
	 * The folder intake reads.
	 *
	 * @return string The folder name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function folder(): string {
		$folder = trim(
			$this->appConfig->getValueString(Application::APP_ID, self::FOLDER_KEY, self::DEFAULT_FOLDER)
		);
		if ($folder === '') {
			return self::DEFAULT_FOLDER;
		}

		return $folder;
	}//end folder()

	/**
	 * Whether an administrator has pointed intake at an account.
	 *
	 * @return boolean True when an account is picked.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isConfigured(): bool {
		return ($this->accountId() > 0);
	}//end isConfigured()

	/**
	 * Whether an account and folder are the ones intake reads.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 *
	 * @return boolean True when both match.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function matches(int $accountId, string $mailbox): bool {
		if ($accountId <= 0 || $accountId !== $this->accountId()) {
			return false;
		}

		return (strcasecmp(trim($mailbox), $this->folder()) === 0);
	}//end matches()
}//end class
