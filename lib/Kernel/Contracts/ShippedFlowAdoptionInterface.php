<?php

/**
 * Contract of ShippedFlowAdoption
 *
 * The public surface of ShippedFlowAdoption that other components call, so they depend
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
 * Contract of ShippedFlowAdoption.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShippedFlowAdoptionInterface {

	/**
	 * Adopt (and optionally enable) every shipped flow, as the named user.
	 *
	 * The whole pass runs inside `ObjectService::runAs($user, ...)`, which is
	 * the seam dossiq's other flow migrations already use: `FlowService::adopt()`
	 * writes the CALLING user's uid and refuses outright when there is no acting
	 * user, and `find()` is organisation-scoped, so neither works from an occ
	 * command without it.
	 *
	 * A flow already owned by somebody else is REPORTED, never taken over —
	 * that refusal is the engine's, and routing around it would re-point whose
	 * identity existing runs resolve as.
	 *
	 * @param IUser $user The administrator volunteering to own the flows.
	 * @param bool $enable Also arm the trigger by enabling each adopted flow.
	 * @param bool $dryRun Report what would happen and write nothing.
	 *
	 * @return array{note: string, rows: array<int, array{name: string, uuid: string, outcome: string, detail: string}>}
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function adoptAll(IUser $user, bool $enable, bool $dryRun): array;

	/**
	 * What dossiq's shipped flows look like right now.
	 *
	 * Read-only and session-free, so a repair step and an occ command can both
	 * ask. `available` is false when OpenRegister exposes no flow store at all,
	 * which is a different fact from "no flows" and must not be reported as one.
	 *
	 * @return array{available: bool, note: string, flows: array<int, array{uuid: string, name: string, enabled: bool, owner: string}>}
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function census(): array;

	/**
	 * The shipped flows that still need an administrator.
	 *
	 * A flow needs attention when it has no owner (it cannot dispatch at all)
	 * or is disabled (its trigger is not armed). Both are reported, because an
	 * adopted-but-disabled flow is just as inert as an ownerless one and an
	 * install that mentions only the first leaves the reader half-informed.
	 *
	 * @param array<int, array{uuid: string, name: string, enabled: bool, owner: string}> $flows The census rows.
	 *
	 * @return array<int, array{uuid: string, name: string, enabled: bool, owner: string}> The rows still outstanding.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function outstanding(array $flows): array;
}//end interface
