<?php

/**
 * Contract of PersonNumberLookup
 *
 * The public surface of PersonNumberLookup that other components call, so they depend
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
use Throwable;

/**
 * Contract of PersonNumberLookup.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonNumberLookupInterface {

	/**
	 * The people with this BSN, or null when the caller may not look citizens up.
	 *
	 * @param IUser  $user The caller.
	 * @param string $bsn  The BSN typed.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @throws Throwable When the register cannot be read.
	 *
	 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
	 */
	public function find(IUser $user, string $bsn): ?array;
}//end interface
