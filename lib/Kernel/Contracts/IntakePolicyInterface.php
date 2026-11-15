<?php

/**
 * Contract of IntakePolicy
 *
 * The public surface of IntakePolicy that other components call, so they depend
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
 * Contract of IntakePolicy.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakePolicyInterface {

	/**
	 * Whether a user may read the intake log and release a quarantine.
	 *
	 * 🔴 AN UNNAMED ROLE MEANS ADMINISTRATORS ONLY, NOT EVERYBODY. The intake
	 * log holds the original of every message the mailbox received, which is
	 * personal data about people who never consented to it being readable by
	 * the whole instance. An instance that has not configured a role has not
	 * said "anyone may read it".
	 *
	 * @param string $userId The user.
	 *
	 * @return boolean True when they may.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function mayRunIntake(string $userId): bool;
}//end interface
