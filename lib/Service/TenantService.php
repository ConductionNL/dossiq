<?php

/**
 * Dossiq Tenant Service
 *
 * What is left of dossiq's tenant service once its tenant API moved to
 * OpenRegister (Q5, Ruben 2026-10-08): the platform admin check that
 * `MandateValidationMiddleware` makes before it refuses an organisation that
 * is not active, and the creation of a tenant's audit anchor (Q6).
 *
 * The rest went with the tenant controller. Reading the active organisation is
 * `GET /api/organisations/active`, listing a user's organisations is
 * `GET /api/organisations`, provisioning is
 * `PUT /api/organisations/{uuid}/activate`, and usage is
 * `GET /api/organisations/{uuid}/usage` with `GET /api/organisations/{uuid}`,
 * all on OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeInterface;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform admin check and the audit anchor for the tenant boundary.
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */
class TenantService {
	/**
	 * The register that holds the anchors.
	 *
	 * FROZEN: the OpenRegister register SLUG, not this app's id.
	 */
	private const REGISTER = 'dossiq';

	/**
	 * The schema an anchor is an object of.
	 */
	private const SCHEMA_TENANT = 'tenant';

	/**
	 * Constructor for the TenantService.
	 *
	 * @param IGroupManager              $groupManager  The Nextcloud group manager.
	 * @param TenantOrganisationResolver $organisations Reads the Organisation an anchor is made from.
	 * @param IAppManager                $appManager    OpenRegister availability check.
	 * @param ContainerInterface         $container     Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface            $logger        Logger.
	 */
	public function __construct(
		private IGroupManager $groupManager,
		private TenantOrganisationResolver $organisations,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether a user is a platform administrator.
	 *
	 * @param string $userId The Nextcloud user ID.
	 *
	 * @return bool True when the user is in the NC admin group.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function isPlatformAdmin(string $userId): bool {
		return $this->groupManager->isAdmin($userId);
	}//end isPlatformAdmin()

	/**
	 * Make sure the Organisation has its tenant audit anchor, creating it once.
	 *
	 * Every tenant audit entry is a row on OpenRegister's audit trail of a
	 * tenant object (decision Q4). A tenant created in OpenRegister after the
	 * migration has no such object, so dossiq creates one when its onboarding
	 * starts (decision Q6): the Organisation's uuid, slug and name, and the
	 * moment the Organisation was created. An anchor that exists is left
	 * exactly as it is. Nothing here updates or deletes one.
	 *
	 * The read and the write skip RBAC and multitenancy on purpose. The anchor
	 * is a system record: it must be found whoever's request writes the audit
	 * entry, and it must not take the organisation of the admin who happened to
	 * start the onboarding. The only caller is the admin-only onboarding route.
	 *
	 * @param string $organisationUuid The Organisation's uuid, which is the tenant uuid.
	 *
	 * @return bool True when the anchor exists afterwards.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function ensureAuditAnchor(string $organisationUuid): bool {
		$uuid = trim($organisationUuid);
		$objectService = $this->getObjectService();
		if ($uuid === '' || $objectService === null) {
			return false;
		}

		if ($this->anchorExists(objectService: $objectService, uuid: $uuid) === true) {
			return true;
		}

		$organisation = $this->organisations->findOrganisation(uuid: $uuid);
		if ($organisation === null) {
			$this->logger->warning('Dossiq: no Organisation to anchor the tenant audit trail on', ['organisation' => $uuid]);
			return false;
		}

		try {
			$objectService->saveObject(
				object: $this->anchorFor(organisation: $organisation),
				register: self::REGISTER,
				schema: self::SCHEMA_TENANT,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the tenant audit anchor could not be written',
				['organisation' => $uuid, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return $this->anchorExists(objectService: $objectService, uuid: $uuid);
	}//end ensureAuditAnchor()

	/**
	 * The anchor's fields, read off the Organisation.
	 *
	 * @param object $organisation OpenRegister's Organisation entity.
	 *
	 * @return array<string, string> The tenant object to write.
	 */
	private function anchorFor(object $organisation): array {
		$slug = trim((string) ($organisation->getSlug() ?? ''));
		$name = trim((string) ($organisation->getName() ?? ''));
		if ($slug === '') {
			$slug = (string) $organisation->getUuid();
		}

		if ($name === '') {
			$name = $slug;
		}

		$anchor = ['slug' => $slug, 'displayName' => $name];
		$created = $organisation->getCreated();
		if ($created instanceof DateTimeInterface) {
			$anchor['createdAt'] = $created->format(DATE_ATOM);
		}

		return $anchor;
	}//end anchorFor()

	/**
	 * Whether a tenant object with this uuid is stored.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $uuid          The tenant uuid.
	 *
	 * @return bool True when it is.
	 */
	private function anchorExists(object $objectService, string $uuid): bool {
		try {
			$found = $objectService->find(
				$uuid,
				register: self::REGISTER,
				schema: self::SCHEMA_TENANT,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// DoesNotExistException, and any other lookup failure, reads as absent.
			return false;
		}

		return $found !== null;
	}//end anchorExists()

	/**
	 * OpenRegister's ObjectService, or null when OpenRegister is unavailable.
	 *
	 * @return object|null The service.
	 */
	private function getObjectService(): ?object {
		$installed = (array) $this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not resolve ObjectService for the tenant audit anchor', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
