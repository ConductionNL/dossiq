<?php

/**
 * Contract of CitizenLookupGuard
 *
 * The public surface of CitizenLookupGuard that other components call, so they depend
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
 * Contract of CitizenLookupGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenLookupGuardInterface {

	/**
	 * Whether this caller may read a contact moment's sensitive fields.
	 *
	 * Membership only. A Nextcloud administrator is NOT waved through here,
	 * unlike the endpoint decision above: the four fields are personal data
	 * about a citizen, and being able to administer the instance is not a
	 * reason to read the transcript of their call.
	 *
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the caller holds the sensitive group.
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function maySeeSensitiveFields(IUser $user): bool;
}//end interface
