<?php

/**
 * A user's own email-to-case matching settings, cursor and last-run status.
 *
 * Kept in Nextcloud's per-user preferences, not in app config keyed by
 * `<prefix>.<uid>` as pipelinq keeps them. App-config keys are capped at 64
 * characters and a user id may be 64 characters on its own, so that shape
 * throws for exactly the long LDAP and SSO ids a municipality uses. User
 * preferences have no such limit, are removed with the user, and let the job
 * ask for opted-in users directly instead of walking every account.
 *
 * The store holds counts and codes, never message content (design, privacy).
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-user matching preferences.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseEmailMatchPreferences {

	/**
	 * Whether this user's mail is matched.
	 *
	 * @var string
	 */
	public const PREF_ENABLED = 'case_matching_enabled';

	/**
	 * The Mail account id whose messages are matched.
	 *
	 * @var string
	 */
	public const PREF_ACCOUNT = 'case_matching_account';

	/**
	 * The last Mail message id processed.
	 *
	 * @var string
	 */
	public const PREF_CURSOR = 'case_matching_cursor';

	/**
	 * JSON status of the last run.
	 *
	 * @var string
	 */
	public const PREF_STATUS = 'case_matching_status';

	/**
	 * The cursor value that means "never started".
	 *
	 * Distinct from 0, which is a real starting point: an account that held no
	 * mail when matching was switched on.
	 *
	 * @var int
	 */
	public const CURSOR_UNSET = -1;

	/**
	 * Constructor.
	 *
	 * @param IUserConfig       $userConfig Per-user preferences.
	 * @param MailMessageSource $messages   Account ownership and the high-water mark.
	 * @param LoggerInterface   $logger     Logger.
	 */
	public function __construct(
		private readonly IUserConfig $userConfig,
		private readonly MailMessageSource $messages,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The users who have switched matching on for themselves.
	 *
	 * @return iterable<string> Their user ids.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function optedInUsers(): iterable {
		return $this->userConfig->searchUsersByValueBool(Application::APP_ID, self::PREF_ENABLED, true);
	}//end optedInUsers()

	/**
	 * A user's matching settings.
	 *
	 * An unreadable preference reads as off: there is no failure that should
	 * start scanning somebody's mailbox.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{enabled: bool, account: int} The settings.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function getUserSettings(string $userId): array {
		try {
			return [
				'enabled' => $this->userConfig->getValueBool($userId, Application::APP_ID, self::PREF_ENABLED, false),
				'account' => $this->userConfig->getValueInt($userId, Application::APP_ID, self::PREF_ACCOUNT, 0),
			];
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: reading email case matching settings failed: ' . $e->getMessage());
			return ['enabled' => false, 'account' => 0];
		}
	}//end getUserSettings()

	/**
	 * Save a user's matching settings.
	 *
	 * The account must be the user's own. Switching matching on, or pointing it
	 * at another account, restarts the cursor at that account's current newest
	 * message, so nothing already in the mailbox is linked retroactively.
	 *
	 * @param string $userId  The user.
	 * @param bool   $enabled Whether to match this user's mail.
	 * @param int    $account The Mail account to match, 0 for none.
	 *
	 * @return array{enabled: bool, account: int} The settings now stored.
	 *
	 * @throws InvalidArgumentException When the account is not the user's.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The stored preference is itself an on/off switch.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function saveUserSettings(string $userId, bool $enabled, int $account): array {
		if ($account > 0 && $this->messages->ownsAccount(accountId: $account, userId: $userId) === false) {
			throw new InvalidArgumentException('That Mail account is not yours.');
		}

		$account = max(0, $account);
		$before = $this->getUserSettings(userId: $userId);
		$restart = ($enabled === true
			&& $account > 0
			&& ($before['enabled'] === false || $before['account'] !== $account));

		$this->userConfig->setValueBool($userId, Application::APP_ID, self::PREF_ENABLED, $enabled);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::PREF_ACCOUNT, $account);
		if ($restart === true) {
			$this->writeCursor(userId: $userId, cursor: $this->messages->maxMessageId(accountId: $account));
		}

		return ['enabled' => $enabled, 'account' => $account];
	}//end saveUserSettings()

	/**
	 * The user's cursor, or CURSOR_UNSET when it was never started.
	 *
	 * @param string $userId The user.
	 *
	 * @return int The cursor.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function readCursor(string $userId): int {
		try {
			return $this->userConfig->getValueInt($userId, Application::APP_ID, self::PREF_CURSOR, self::CURSOR_UNSET);
		} catch (Throwable $e) {
			return self::CURSOR_UNSET;
		}
	}//end readCursor()

	/**
	 * Store the user's cursor.
	 *
	 * @param string $userId The user.
	 * @param int    $cursor The last processed message id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function writeCursor(string $userId, int $cursor): void {
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::PREF_CURSOR, max(0, $cursor));
	}//end writeCursor()

	/**
	 * The status of a user's last run.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{lastRunAt: ?string, linked: int, scanned: int, error: ?string} The status.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function getStatus(string $userId): array {
		$empty = ['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null];
		try {
			$json = $this->userConfig->getValueString($userId, Application::APP_ID, self::PREF_STATUS, '');
		} catch (Throwable $e) {
			return $empty;
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return $empty;
		}

		$lastRunAt = null;
		if (isset($decoded['lastRunAt']) === true) {
			$lastRunAt = (string)$decoded['lastRunAt'];
		}

		$error = null;
		if (isset($decoded['error']) === true) {
			$error = (string)$decoded['error'];
		}

		return [
			'lastRunAt' => $lastRunAt,
			'linked' => (int)($decoded['linked'] ?? 0),
			'scanned' => (int)($decoded['scanned'] ?? 0),
			'error' => $error,
		];
	}//end getStatus()

	/**
	 * Record the outcome of a user's run.
	 *
	 * The error is a short code the settings screen translates, never message
	 * content: the matcher stores nothing about a mail but counts.
	 *
	 * @param string      $userId  The user.
	 * @param int         $linked  New links the run made.
	 * @param int         $scanned Messages the run read.
	 * @param string|null $error   Why the run refused, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function writeStatus(string $userId, int $linked, int $scanned, ?string $error): void {
		$payload = json_encode(
			[
				'lastRunAt' => gmdate('c'),
				'linked' => $linked,
				'scanned' => $scanned,
				'error' => $error,
			]
		);

		try {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::PREF_STATUS, (string)$payload);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: recording the email case matching status failed: ' . $e->getMessage());
		}
	}//end writeStatus()
}//end class
