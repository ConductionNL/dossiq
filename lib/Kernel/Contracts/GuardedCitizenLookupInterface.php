<?php

/**
 * Contract of GuardedCitizenLookup
 *
 * The public surface of GuardedCitizenLookup that other components call, so they depend
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
 * Contract of GuardedCitizenLookup.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuardedCitizenLookupInterface {

	/**
	 * Whether this caller may look a citizen up at all.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return bool True when the lookup may go ahead.
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function isAllowed(IUser $user): bool;

	/**
	 * Record a lookup that was answered, and the fields it revealed.
	 *
	 * @param IUser  $user     The caller.
	 * @param string $burgerId The citizen reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function recordAnswer(IUser $user, string $burgerId): void;

	/**
	 * Record a lookup that was refused.
	 *
	 * @param string $uid      The account that was refused.
	 * @param string $burgerId The citizen reference it looked with.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function recordRefusal(string $uid, string $burgerId): void;

	/**
	 * The payload with every field this caller may not see taken out.
	 *
	 * @param IUser                $user    The caller.
	 * @param array<string, mixed> $payload What was read.
	 *
	 * @return array<string, mixed> What the caller may see.
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function redactForCaller(IUser $user, array $payload): array;
}//end interface
