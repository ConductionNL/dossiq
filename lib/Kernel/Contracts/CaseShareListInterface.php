<?php

/**
 * Contract of CaseShareList
 *
 * The public surface of CaseShareList that other components call, so they depend
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
 * Contract of CaseShareList.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseShareListInterface {

	/**
	 * The shares of a case, for this reader.
	 *
	 * A share is revocable by the reader when it is not revoked or expired
	 * and the reader created it, because OpenRegister revokes a link only for
	 * the colleague who minted it.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $uid    The reader.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function rows(string $caseId, string $uid): array;
}//end interface
