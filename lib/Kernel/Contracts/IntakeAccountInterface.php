<?php

/**
 * Contract of IntakeAccount
 *
 * The public surface of IntakeAccount that other components call, so they depend
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
 * Contract of IntakeAccount.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeAccountInterface {

	/**
	 * Whether an administrator has pointed intake at an account.
	 *
	 * @return boolean True when an account is picked.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isConfigured(): bool;

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
	public function matches(int $accountId, string $mailbox): bool;

	/**
	 * The account an administrator picked.
	 *
	 * @return integer The account id, or 0 when none is picked.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function accountId(): int;

	/**
	 * The folder intake reads.
	 *
	 * @return string The folder name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function folder(): string;
}//end interface
