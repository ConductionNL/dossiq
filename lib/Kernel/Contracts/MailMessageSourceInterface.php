<?php

/**
 * Contract of MailMessageSource
 *
 * The public surface of MailMessageSource that other components call, so they depend
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

/**
 * Contract of MailMessageSource.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailMessageSourceInterface {

	/**
	 * The Mail accounts a user owns.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array{id: int, name: string, email: string}> The accounts, by name.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function accountsOf(string $userId): array;

	/**
	 * Whether a Mail account belongs to a user.
	 *
	 * A lookup that fails answers no. There is no reading of an unreadable
	 * table that makes an account somebody's.
	 *
	 * @param int    $accountId The Mail account id.
	 * @param string $userId    The user.
	 *
	 * @return bool True only when the account row names this user.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function ownsAccount(int $accountId, string $userId): bool;
}//end interface
