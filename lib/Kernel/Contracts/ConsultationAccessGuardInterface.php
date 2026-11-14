<?php

/**
 * Contract of ConsultationAccessGuard
 *
 * The public surface of ConsultationAccessGuard that other components call, so they depend
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

use OCA\Dossiq\Service\Consultation\ConsultationAccess;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of ConsultationAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConsultationAccessGuardInterface {

	/**
	 * Authenticate the caller, load the consultation and authorize access.
	 *
	 * A user is authorized when they are the aanvrager (original requestor),
	 * the assignee (individual handler), or an administrator.
	 *
	 * @param string $consultationId The consultation UUID.
	 *
	 * @return ConsultationAccess The denial response, or the resolved consultation.
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-04
	 */
	public function authorize(string $consultationId): ConsultationAccess;

	/**
	 * The signed-in user's UID, or an empty string when there is no session.
	 *
	 * @return string The current UID.
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-04
	 */
	public function currentUid(): string;

	/**
	 * Reject a create payload whose dependsOn list would form a cycle.
	 *
	 * @param array<string, mixed> $data The decoded create payload.
	 *
	 * @return JSONResponse|null A 400 response on a cycle, null otherwise.
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-04
	 */
	public function dependencyCycleError(array $data): ?JSONResponse;

	/**
	 * Which advice requests the caller may see in a list: those on a case the
	 * caller may read (the case pages' own check). The background service
	 * account, which runs the scheduled consultation workflows, sees them all.
	 * Without a caller nothing is readable.
	 *
	 * @return callable(array<string, mixed>): bool The test, asked of each request before a list is built.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-a-consultation-list-shows-only-requests-on-cases-the-caller-may-read
	 */
	public function readableFilter(): callable;

	/**
	 * Parse the request body as JSON and return it as an array.
	 *
	 * @return array<string, mixed> The decoded body, or an empty array.
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-04
	 */
	public function requestBody(): array;

	/**
	 * Refuse a caller who may not read figures across every case: allowed are
	 * administrators, the coordinators group and the background service account.
	 *
	 * @return JSONResponse|null Null when allowed, a 401 or 403 response otherwise.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-the-bottleneck-report-is-for-coordinators
	 */
	public function requireCoordinator(): ?JSONResponse;

	/**
	 * Reject an unauthenticated caller.
	 *
	 * @return JSONResponse|null Null when a user is signed in, a 401 response otherwise.
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-04
	 */
	public function requireUser(): ?JSONResponse;
}//end interface
