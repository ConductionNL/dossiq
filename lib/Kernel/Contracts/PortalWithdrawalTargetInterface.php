<?php

/**
 * Contract of PortalWithdrawalTarget
 *
 * The public surface of PortalWithdrawalTarget that other components call, so they depend
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
 * Contract of PortalWithdrawalTarget.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalWithdrawalTargetInterface {

	/**
	 * The target is not one of the type's statuses. Parameter: the target id.
	 */
	public const NOT_OWN = 'not-own';

	/**
	 * What is wrong with this withdrawal, as a reason and the names it carries.
	 *
	 * @param array<string, mixed>             $withdrawal The `portalWithdrawal` block being saved.
	 * @param array<string, string>            $statuses   The type's statuses: id to title.
	 * @param array<int, array<string, mixed>> $moves      The active template's transitions.
	 *
	 * @return array{reason: string, parameters: array<int, string>}|null The refusal, or null when the withdrawal can be written.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function refusal(array $withdrawal, array $statuses, array $moves): ?array;
}//end interface
