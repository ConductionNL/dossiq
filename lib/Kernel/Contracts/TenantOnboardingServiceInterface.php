<?php

/**
 * Contract of TenantOnboardingService
 *
 * The public surface of TenantOnboardingService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of TenantOnboardingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantOnboardingServiceInterface {

	/**
	 * Complete go-live when it validates.
	 *
	 * Writes no tenant status (task 6.8). The Organisation is `active` from the
	 * start (decision 2f) and OpenRegister's TenantLifecycleService governs its
	 * status; the onboarding steps are dossiq's own state. What go-live still
	 * does here is emit the first billing line.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{activated: bool, missing?: array<int, string>}
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function activate(string $tenantId): array;

	/**
	 * Fork the default 7-step template into the tenant's onboarding list.
	 *
	 * The tenant's audit anchor is made first (decision Q6), and without it no
	 * step is written: a tenant whose audit entries have nowhere to land must
	 * not look onboarded. Each step is then one task in OpenRegister's task
	 * engine (remove-casetask 7.1). A step that is already there is not opened
	 * twice, so initialising again only fills in what is missing.
	 *
	 * @param string $tenantId Tenant UUID, which is the Organisation's uuid.
	 *
	 * @return array<int, array<string, mixed>> The steps opened by this call.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function createOnboarding(string $tenantId): array;

	/**
	 * Get the per-step progress and overall completion fraction.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{steps: array<int, array<string, mixed>>, completed: int, total: int, fraction: float}
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function getProgress(string $tenantId): array;

	/**
	 * Mark a step as completed, through the engine's completion verb.
	 *
	 * @param string $tenantId    Tenant UUID.
	 * @param string $step        Step name.
	 * @param string $completedBy NC user ID who completed it.
	 *
	 * @return array<string,mixed>|null The step after completion, or null when there is no such step or the engine refused.
	 *
	 * @throws InvalidArgumentException On invalid step.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function markStepComplete(string $tenantId, string $step, string $completedBy): ?array;
}//end interface
