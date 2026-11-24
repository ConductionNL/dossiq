<?php

/**
 * Dossiq Organisation from a legacy tenant.
 *
 * The OpenRegister Organisation a legacy `tenant` row becomes: its uuid, slug,
 * name and routing group kept, its status mapped onto the Organisation
 * lifecycle, its retention dated and its storage quota carried.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\TenantMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\TenantMigration;

use DateTime;
use OCA\OpenRegister\Db\Organisation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the unsaved Organisation for one legacy tenant row.
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */
final class OrganisationFromLegacyTenant {

	/**
	 * Lifecycle status meaning "access ended, data kept" (OR `STATUS_RETAINED`).
	 */
	private const STATUS_RETAINED = 'retained';

	/**
	 * NC group-id prefix used for tenant routing (mirrors TenantService).
	 */
	private const TENANT_GROUP_PREFIX = 'tenant_';

	/**
	 * Map of legacy procest tenant status → OR Organisation lifecycle status.
	 *
	 * TWO OF THESE FOUR ROWS WERE WRONG, and both were decided against on
	 * 2026-09-11 (tenancy-onto-openregister-organisation, 2e and 2f).
	 *
	 * `terminated` mapped to `archived`. `archived` is
	 * `TenantLifecycleService::PURGEABLE_STATUS`: it is the one status
	 * `TenantPurgeJob` selects on, and everything it then deletes is
	 * permanent. dossiq's termination is deliberately NOT destructive — the
	 * irreversible whole-tenant delete was removed from this app on purpose —
	 * so projecting it onto the one status that exists to be deleted from
	 * inverted the decision it was migrating. It does not purge TODAY only
	 * because the purge also requires `deprovisionedAt`, which this service
	 * never sets; that is an omission, not a rule, and anything that later
	 * stamps that field turns every terminated tenant into a scheduled
	 * delete. `retained` is the rule: OpenRegister added it precisely so a
	 * tenancy can end without the data ending, `retain()` stamps `retainedAt`
	 * and leaves `deprovisionedAt` alone, and the purge's own re-check refuses
	 * any row not in `archived`.
	 *
	 * `onboarding` mapped to `provisioning`. An Organisation in
	 * `provisioning` is not a state a tenant can transact from —
	 * OpenRegister's `TenantQuotaMiddleware` answers 403 to every request the
	 * tenant admin makes on OR routes unless they are an instance admin — and
	 * dossiq's frontend reads and writes through exactly those routes. So the
	 * tenant admin could not perform the onboarding they were in the middle
	 * of. The Organisation goes to `active` and dossiq keeps its own
	 * onboarding state beside it: "may this organisation act?" is the
	 * platform's question, "has this customer finished setting up?" is
	 * dossiq's, and they are not the same question.
	 */
	private const STATUS_MAP = [
		// Decision 2f. NOT `provisioning`, which the June map chose. While a
		// user's active Organisation is `provisioning`, openregister's
		// TenantQuotaMiddleware answers 403 to every request that user makes on
		// openregister's routes unless they are an instance admin, and dossiq's
		// frontend reads and writes through those routes. A dossiq tenant in
		// `onboarding` is one whose tenant admin is still working through the
		// onboarding steps, so it stays `active` on the Organisation with
		// dossiq's own onboarding progress kept beside it in tenantOnboardingTask.
		'onboarding' => 'active',
		'active' => 'active',
		'suspended' => 'suspended',
		// Decision 2e, resolved upstream rather than chosen between two bad
		// options. `retained` is openregister's terminal state for an
		// organisation with a retention duty: access ends, nothing is deleted,
		// and TenantPurgeJob can only ever delete a row in PURGEABLE_STATUS,
		// which is `archived` alone. The June map sent `terminated` to
		// `archived`, which is both terminal AND the one purgeable state, and
		// unreachable from a live termination without passing `deprovisioning`.
		'terminated' => 'retained',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Says when a termination date could not be read.
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build an OR Organisation entity from a legacy tenant row.
	 *
	 * @param LegacyTenant $tenant The tenant; its uuid is preserved on the Organisation.
	 *
	 * @return object The unsaved Organisation entity.
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 */
	public function build(LegacyTenant $tenant): object {
		$organisation = new Organisation();

		// Preserve the tenant UUID so stored `_tenantId` references keep resolving.
		if ($tenant->uuid !== '') {
			$organisation->setUuid($tenant->uuid);
		}

		$organisation->setSlug($tenant->slug);
		$organisation->setName((string)($tenant->row['displayName'] ?? ($tenant->row['name'] ?? $tenant->slug)));
		$this->applyLifecycle(organisation: $organisation, row: $tenant->row);

		// The NC group used by procest for tenant routing.
		$groupId = (string)($tenant->row['groupId'] ?? (self::TENANT_GROUP_PREFIX . $tenant->slug));
		$organisation->setGroups([$groupId]);

		$this->applyStorageQuota(organisation: $organisation, row: $tenant->row);

		return $organisation;
	}//end build()

	/**
	 * Set the storage quota of a new Organisation, when the legacy row has one.
	 *
	 * @param object               $organisation The unsaved Organisation.
	 * @param array<string, mixed> $row          Legacy tenant object.
	 *
	 * @return void
	 */
	private function applyStorageQuota(object $organisation, array $row): void {
		$storageQuota = $this->resolveStorageQuotaBytes(row: $row);
		if ($storageQuota !== null) {
			$organisation->setStorageQuota($storageQuota);
		}
	}//end applyStorageQuota()

	/**
	 * Set the lifecycle status, active flag and retention start of a new Organisation.
	 *
	 * @param object               $organisation The unsaved Organisation.
	 * @param array<string, mixed> $row          Legacy tenant object.
	 *
	 * @return void
	 */
	private function applyLifecycle(object $organisation, array $row): void {
		$status = $this->resolveStatus(row: $row);
		$organisation->setStatus($status);

		// A retained organisation dates its retention from `retainedAt`, the
		// way `TenantLifecycleService::retain()` does. `deprovisionedAt` stays
		// null and is never set here: it is what the purge measures its window
		// from, so writing it would schedule the delete this mapping exists to
		// avoid.
		if ($status === self::STATUS_RETAINED) {
			$organisation->setRetainedAt(new DateTime());
		}

		$organisation->setActive($organisation->getStatus() === 'active');

		// A terminated tenant becomes `retained`, and the moment the retention
		// began is stamped on `retainedAt`, which is what openregister's own
		// retain() writes. `deprovisionedAt` is deliberately left untouched:
		// TenantPurgeJob measures its window from that column, so writing it
		// would schedule a deletion dossiq's termination does not have.
		if ($organisation->getStatus() === self::STATUS_RETAINED) {
			$organisation->setRetainedAt($this->resolveRetainedAt(row: $row));
		}
	}//end applyLifecycle()

	/**
	 * Resolve the OR lifecycle status from the legacy tenant row.
	 *
	 * Prefers the tenant's own `status` (mapped to OR's vocabulary); falls back
	 * to the legacy `isActive` boolean when no status is present.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return string An OR Organisation status.
	 */
	private function resolveStatus(array $row): string {
		$legacyStatus = (string)($row['status'] ?? '');
		if ($legacyStatus !== '' && isset(self::STATUS_MAP[$legacyStatus]) === true) {
			return self::STATUS_MAP[$legacyStatus];
		}

		$isActive = ($row['isActive'] ?? null);
		if ($isActive === false) {
			return 'suspended';
		}

		return 'active';
	}//end resolveStatus()

	/**
	 * Resolve when retention began for a terminated tenant.
	 *
	 * The tenant's own `terminatedAt` when it has one, so a retention period
	 * that started years ago is not reset to today by the migration. Now
	 * otherwise, because a retained organisation with no start date has no
	 * computable end of retention.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return DateTime When retention began.
	 */
	private function resolveRetainedAt(array $row): DateTime {
		$terminatedAt = trim((string)($row['terminatedAt'] ?? ''));
		if ($terminatedAt !== '') {
			try {
				return new DateTime($terminatedAt);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: tenant migration could not read terminatedAt, stamping retention from now',
					['terminatedAt' => $terminatedAt],
				);
			}
		}

		return new DateTime();
	}//end resolveRetainedAt()

	/**
	 * Resolve a storage quota in bytes from the legacy `maxStorageMb` field.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return int|null Quota in bytes, or null when not set.
	 */
	private function resolveStorageQuotaBytes(array $row): ?int {
		$maxStorageMb = ($row['maxStorageMb'] ?? null);
		if (is_numeric($maxStorageMb) === true && (int)$maxStorageMb > 0) {
			return ((int)$maxStorageMb * 1024 * 1024);
		}

		return null;
	}//end resolveStorageQuotaBytes()
}//end class
