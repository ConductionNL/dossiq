<?php

/**
 * Contract of CaseRecordGrantsBackfill
 *
 * The public surface of CaseRecordGrantsBackfill that other components call, so they depend
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
 * Contract of CaseRecordGrantsBackfill.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRecordGrantsBackfillInterface {

	/**
	 * Reconcile every case of every case type that declares reviewer access, as the background service account.
	 *
	 * The upgrade's walk. Safe to run again: a record that already carries the
	 * right grants is read and left alone, and the special right is written
	 * only where it is missing.
	 *
	 * @return array{
	 *     status: string, actor: string, cases: int, granted: int, revoked: int, refused: int,
	 *     failed: list<string>, unfinished: list<string>
	 * }
	 * `status` is `reconciled` (every case walked, nothing refused), `incomplete` (a case in `failed`
	 * or a case type in `unfinished` was not fully reconciled), `unavailable` (no per-object sharing
	 * in this OpenRegister; nothing written), `no-service-account` (no usable background service
	 * account; nothing written) or `no-grant-right` (OpenRegister could not give the service group
	 * the special right `grant_manage`; nothing written). `actor` is the account the grants were
	 * written as.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
	 */
	public function run(): array;

	/**
	 * Reconcile every declaring case as the signed-in caller: the administrator route.
	 *
	 * The route checks that the caller is an administrator; OpenRegister
	 * admits an administrator to every record's grants, so no special right is
	 * needed or written here.
	 *
	 * @return array<string, mixed> The same answer as run(), with the caller as `actor`.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
	 */
	public function runAsCaller(): array;
}//end interface
