<?php

/**
 * Contract of DocumentAccessExceptionRule
 *
 * The public surface of DocumentAccessExceptionRule that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of DocumentAccessExceptionRule.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentAccessExceptionRuleInterface {

	/**
	 * Whether a user may change one of the cases a document is on.
	 *
	 * The cases are read regardless of the caller (only their ids leave the store), so a handler is recognised even
	 * when the document itself is hidden from the read that asks.
	 *
	 * @param IUser  $user       The caller.
	 * @param string $documentId The informatieobject id.
	 *
	 * @return bool True for the handler of one of its cases and for administrators.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
	 */
	public function handlesACaseOf(IUser $user, string $documentId): bool;
}//end interface
