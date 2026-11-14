<?php

/**
 * Contract of PersonReachability
 *
 * The public surface of PersonReachability that other components call, so they depend
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

use Throwable;

/**
 * Contract of PersonReachability.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonReachabilityInterface {

	/**
	 * How the person can be reached, or null when the user may not read the person.
	 *
	 * @param string $personId The brpPerson uuid.
	 *
	 * @return array{portal: string, lastContactAt: string, lastContactChannel: string}|null
	 *
	 * @throws Throwable When the register cannot be read; the caller answers that, not "not found".
	 *
	 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
	 */
	public function forPerson(string $personId): ?array;
}//end interface
