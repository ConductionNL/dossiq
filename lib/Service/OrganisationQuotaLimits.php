<?php

/**
 * Organisation Quota Limits
 *
 * The two quota limits that live on the Organisation, and the unit conversion
 * they need.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the quota limits that the Organisation owns.
 *
 * Decision 2b. `storage_gb` maps to `Organisation.storageQuota` and
 * `api_calls_per_hour` to `Organisation.requestQuota`. For those two the row
 * keeps what the entity has no home for, `currentUsage`, `resetAt`,
 * `softLimitWarningPercent` and `enforcement`, and stops carrying `limit`.
 * One limit, in one place, with no second copy to drift.
 *
 * `cases_per_month` and `active_users` have no column and stay rows exactly as
 * they are, limit included. `bandwidthQuota` has no dossiq counterpart and
 * stays OpenRegister's alone.
 *
 * This lives beside `TenantQuotaService` rather than inside it because the
 * service was already at the complexity ceiling, and because the mapping is a
 * fact about the two data models rather than about quota arithmetic.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */
class OrganisationQuotaLimits {
	/**
	 * Quota type to the Organisation column that carries its limit.
	 *
	 * @var array<string, string>
	 */
	public const COLUMNS = [
		'storage_gb' => 'storageQuota',
		'api_calls_per_hour' => 'requestQuota',
	];

	/**
	 * Bytes in a gigabyte.
	 *
	 * `storage_gb` is counted in gigabytes and `storageQuota` is stored in
	 * bytes, so the two are converted rather than compared. A limit written in
	 * GB and read back as bytes would read as roughly a billion times more
	 * headroom than the tenant has, and nothing would say so.
	 */
	private const BYTES_PER_GB = 1073741824;

	/**
	 * Constructor.
	 *
	 * @param IAppManager                $appManager    App manager, for the OpenRegister availability check.
	 * @param ContainerInterface         $container     DI container, resolves the OrganisationMapper.
	 * @param TenantOrganisationResolver $organisations Resolves the Organisation by uuid.
	 * @param LoggerInterface            $logger        Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly TenantOrganisationResolver $organisations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this quota type's limit lives on the Organisation.
	 *
	 * @param string $quotaType The quota dimension.
	 *
	 * @return boolean Whether the Organisation owns the limit.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function owns(string $quotaType): bool {
		return array_key_exists($quotaType, self::COLUMNS);
	}//end owns()

	/**
	 * Read the limit the Organisation carries, in the quota's own units.
	 *
	 * Null means unlimited, and an unresolvable Organisation gives null on
	 * purpose. That is the same answer this path gave before the limits moved:
	 * refusing traffic because a lookup failed would take a tenant offline
	 * over an unavailable service, and OpenRegister's own TenantQuotaMiddleware
	 * is what enforces requestQuota.
	 *
	 * @param string $tenantId  Tenant uuid, which is the Organisation uuid.
	 * @param string $quotaType The quota dimension.
	 *
	 * @return integer|null The limit, or null for unlimited.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function read(string $tenantId, string $quotaType): ?int {
		$column = (self::COLUMNS[$quotaType] ?? null);
		if ($column === null) {
			return null;
		}

		$organisation = $this->organisations->findOrganisation(uuid: $tenantId);
		if ($organisation === null) {
			return null;
		}

		$stored = $organisation->{'get' . ucfirst($column)}();
		if ($stored === null) {
			return null;
		}

		return $this->fromStoredUnits(quotaType: $quotaType, stored: (int)$stored);
	}//end read()

	/**
	 * Write a limit onto the Organisation.
	 *
	 * @param string       $tenantId  Tenant uuid, which is the Organisation uuid.
	 * @param string       $quotaType The quota dimension.
	 * @param integer|null $limit     The limit in the quota's own units, or null for unlimited.
	 *
	 * @return boolean Whether the limit was written.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function write(string $tenantId, string $quotaType, ?int $limit): bool {
		$column = (self::COLUMNS[$quotaType] ?? null);
		$mapper = $this->getOrganisationMapper();
		$organisation = $this->organisations->findOrganisation(uuid: $tenantId);
		if ($column === null || $mapper === null || $organisation === null) {
			$this->logger->warning(
				'Dossiq: quota limit not written onto the organisation',
				['tenantId' => $tenantId, 'quotaType' => $quotaType],
			);
			return false;
		}

		$stored = null;
		if ($limit !== null) {
			$stored = $this->toStoredUnits(quotaType: $quotaType, limit: $limit);
		}

		$organisation->{'set' . ucfirst($column)}($stored);

		try {
			$mapper->update($organisation);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to write a quota limit onto the organisation',
				['tenantId' => $tenantId, 'quotaType' => $quotaType, 'exception' => $e->getMessage()],
			);
			return false;
		}
	}//end write()

	/**
	 * Convert a stored column value into the quota's own units.
	 *
	 * @param string  $quotaType The quota dimension.
	 * @param integer $stored    The column value.
	 *
	 * @return integer The limit in the quota's own units.
	 */
	private function fromStoredUnits(string $quotaType, int $stored): int {
		if ($quotaType === 'storage_gb') {
			return intdiv($stored, self::BYTES_PER_GB);
		}

		return $stored;
	}//end fromStoredUnits()

	/**
	 * Convert a limit in the quota's own units into the stored column value.
	 *
	 * @param string  $quotaType The quota dimension.
	 * @param integer $limit     The limit in the quota's own units.
	 *
	 * @return integer The column value.
	 */
	private function toStoredUnits(string $quotaType, int $limit): int {
		if ($quotaType === 'storage_gb') {
			return ($limit * self::BYTES_PER_GB);
		}

		return $limit;
	}//end toStoredUnits()

	/**
	 * Resolve OpenRegister's OrganisationMapper when the app is installed.
	 *
	 * @return object|null The mapper, or null when unavailable.
	 */
	private function getOrganisationMapper(): ?object {
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not resolve OrganisationMapper for a quota limit',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end getOrganisationMapper()
}//end class
