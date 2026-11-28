<?php

/**
 * Contract of FilingFollowUps
 *
 * The public surface of FilingFollowUps that other components call, so they depend
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
 * Contract of FilingFollowUps.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FilingFollowUpsInterface {

	/**
	 * Put the sender on the case as a party, unless they already are one.
	 *
	 * @param string $caseId  The case.
	 * @param string $address The sender's address.
	 * @param string $name    The sender's name, '' when the message gave none.
	 *
	 * @return string `added`, `already`, or `no_role_type` when the case type has no party role to give.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function addSenderAsParty(string $caseId, string $address, string $name): string;

	/**
	 * Tell the case's handler a message was linked to it, unless the handler linked it.
	 *
	 * @param string $caseId   The case.
	 * @param string $callerId Who linked the message.
	 * @param string $sender   The sender, by name or address.
	 * @param string $subject  The message's subject.
	 *
	 * @return string `notified`, or `none` when there is no other handler to tell.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function notifyHandler(string $caseId, string $callerId, string $sender, string $subject): string;
}//end interface
