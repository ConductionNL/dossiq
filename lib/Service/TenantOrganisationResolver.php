<?php

/**
 * Tenant Organisation Resolver
 *
 * The one place a tenant id becomes a tenant. Everything downstream of the
 * middleware chain reads what this returns.
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
 * Resolves a tenant id to an OpenRegister Organisation.
 *
 * WHY THIS EXISTS. The mandate middleware does not resolve a tenant at all:
 * `MandateValidationMiddleware` reads it out of `TenantContext`. The chain
 * has exactly one resolution point, `resolveActive()`, which `TenantContext`
 * reaches through `TenantSessionService`. It reads OpenRegister's active
 * organisation (Q3, Ruben 2026-10-08). `resolve()` is the lookup by id that
 * used to read dossiq's own `tenant` schema through `TenantSaasService::getById()`.
 *
 * The Organisation is preferred and the legacy `tenant` row is the fallback,
 * in that order, for the length of the reversible half. An instance that has
 * not run the migration yet keeps working exactly as before; one that has runs
 * on the Organisation. The fallback is what step 5 removes, together with the
 * `tenant` schema it reads.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */
class TenantOrganisationResolver {
	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager App manager, for the OpenRegister availability check.
	 * @param ContainerInterface $container  DI container, resolves OpenRegister's OrganisationMapper.
	 * @param TenantSaasService  $tenantSaas The legacy `tenant` schema reader, used only as a fallback.
	 * @param LoggerInterface    $logger     Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly TenantSaasService $tenantSaas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a tenant id to a tenant-shaped array.
	 *
	 * @param string $tenantId The tenant uuid, which is also the Organisation uuid.
	 *
	 * @return array<string, mixed>|null The tenant, or null when nothing resolves.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
	 */
	public function resolve(string $tenantId): ?array {
		if ($tenantId === '') {
			return null;
		}

		$organisation = $this->findOrganisation(uuid: $tenantId);
		if ($organisation !== null) {
			return $this->project(organisation: $organisation);
		}

		// Legacy fallback. Removed in step 5, with the schema it reads.
		return $this->tenantSaas->getById(tenantId: $tenantId);
	}//end resolve()

	/**
	 * The signed-in user's active OpenRegister organisation, tenant-shaped.
	 *
	 * Which organisation is active is OpenRegister's answer,
	 * `OrganisationService::getActiveOrganisation()` (Q3, Ruben 2026-10-08).
	 * Its slug and status come from the stored row, read through the mapper,
	 * not from that answer. OpenRegister serves the active organisation from a
	 * session cache that keeps neither, and an Organisation rebuilt from it
	 * reads `status` as its default, `active`. A suspended organisation would
	 * then pass as active for as long as the cache lives.
	 *
	 * Null when OpenRegister is absent, answers null, throws, or the stored
	 * row cannot be read. No legacy fallback: the active organisation is
	 * OpenRegister's by definition.
	 *
	 * @return array<string, mixed>|null The tenant, or null.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function resolveActive(): ?array {
		$active = $this->readActiveOrganisation();
		if ($active === null) {
			return null;
		}

		$uuid = (string)($active->getUuid() ?? '');
		if ($uuid === '') {
			return null;
		}

		$organisation = $this->findOrganisation(uuid: $uuid);
		if ($organisation === null) {
			$this->logger->warning(
				'Dossiq: the active organisation has no readable stored row; no tenant',
				['organisation' => $uuid],
			);
			return null;
		}

		return $this->project(organisation: $organisation);
	}//end resolveActive()

	/**
	 * Ask OpenRegister for the signed-in user's active organisation.
	 *
	 * @return object|null The Organisation entity, or null when there is none or OpenRegister cannot answer.
	 */
	private function readActiveOrganisation(): ?object {
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\OrganisationService')->getActiveOrganisation();
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not read the active organisation from OpenRegister; no tenant',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end readActiveOrganisation()

	/**
	 * Read an Organisation as the tenant-shaped array the context carries.
	 *
	 * `TenantContext` reads `uuid` or `id`, `slug` and `status`. Both `uuid` and `id` are set to the same value so a caller
	 * reading either sees the Organisation, never half of one.
	 *
	 * The status is the Organisation's own, unmapped, and that is deliberate.
	 * `active` is the only status `MandateValidationMiddleware` lets through, so `retained`
	 * blocks like `suspended` does, and an onboarding tenant is `active` with
	 * dossiq's onboarding progress kept beside it rather than in the lifecycle.
	 *
	 * @param object $organisation The OpenRegister Organisation entity.
	 *
	 * @return array<string, mixed> The tenant-shaped array.
	 */
	private function project(object $organisation): array {
		$uuid = (string)$organisation->getUuid();

		return [
			'uuid' => $uuid,
			'id' => $uuid,
			'slug' => (string)($organisation->getSlug() ?? ''),
			'status' => (string)($organisation->getStatus() ?? ''),
			'displayName' => (string)($organisation->getName() ?? ''),
			'storageQuota' => $organisation->getStorageQuota(),
			'requestQuota' => $organisation->getRequestQuota(),
		];
	}//end project()

	/**
	 * Find an Organisation by uuid, or null when there is none.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object|null The Organisation entity, or null.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
	 */
	public function findOrganisation(string $uuid): ?object {
		$mapper = $this->getOrganisationMapper();
		if ($mapper === null) {
			return null;
		}

		try {
			return $mapper->findByUuid($uuid);
		} catch (Throwable $e) {
			// DoesNotExistException, and any other lookup failure, reads as absent.
			return null;
		}
	}//end findOrganisation()

	/**
	 * Resolve OpenRegister's OrganisationMapper when the app is installed.
	 *
	 * @return object|null The mapper, or null when OpenRegister is unavailable.
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
				'Dossiq: could not resolve OrganisationMapper for tenant resolution',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end getOrganisationMapper()
}//end class
