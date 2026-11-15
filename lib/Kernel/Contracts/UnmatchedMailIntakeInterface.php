<?php

/**
 * Contract of UnmatchedMailIntake
 *
 * The public surface of UnmatchedMailIntake that other components call, so they depend
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
 * Contract of UnmatchedMailIntake.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface UnmatchedMailIntakeInterface {

	/**
	 * File an unmatched mail as a new case.
	 *
	 * @param array<string, mixed> $message The normalised message row.
	 *
	 * @return string|null The new case's id, or `null` when nothing was created.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function caseFor(array $message): ?string;
}//end interface
