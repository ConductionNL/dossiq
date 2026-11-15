<?php

/**
 * Contract of CaseEmailMatchPreferences
 *
 * The public surface of CaseEmailMatchPreferences that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of CaseEmailMatchPreferences.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseEmailMatchPreferencesInterface {

	/**
	 * The status of a user's last run.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{lastRunAt: ?string, linked: int, scanned: int, error: ?string} The status.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function getStatus(string $userId): array;

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
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function getUserSettings(string $userId): array;

	/**
	 * The users who have switched matching on for themselves.
	 *
	 * @return iterable<string> Their user ids.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function optedInUsers(): iterable;

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
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function saveUserSettings(string $userId, bool $enabled, int $account): array;

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
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function writeStatus(string $userId, int $linked, int $scanned, ?string $error): void;
}//end interface
