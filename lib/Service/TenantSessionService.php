<?php

/**
 * Tenant Session Service
 *
 * Answers the tenant the current request acts as: OpenRegister's active
 * organisation, when the user's `tenantUser` memberships list it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tenant the signed-in user is acting as.
 *
 * WHY OPENREGISTER AND NOT DOSSIQ'S OWN SESSION KEY. OpenRegister already
 * keeps the user's active organisation, checks access before it changes it,
 * and routes the change as `POST /api/organisations/{uuid}/set-active`.
 * dossiq kept a second choice in its own session key, so the two could
 * disagree. Ruben decided on 2026-10-08 (Q3) that OpenRegister's answer is the
 * only one. The caller still never chooses: not by an `X-Tenant-Id` header, not
 * by a token claim.
 *
 * The `tenantUser` membership is still checked on every read. OpenRegister
 * lists OpenRegister membership; dossiq's role and mandate matrix live on the
 * `tenantUser` row, so an organisation without one is no dossiq tenant.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */
class TenantSessionService {
	/**
	 * Constructor.
	 *
	 * @param IUserSession                $users         The user session.
	 * @param TenantAuthenticationService $auth          Membership lookups.
	 * @param TenantOrganisationResolver  $organisations Reads OpenRegister's active organisation.
	 * @param LoggerInterface             $logger        The logger.
	 */
	public function __construct(
		private readonly IUserSession $users,
		private readonly TenantAuthenticationService $auth,
		private readonly TenantOrganisationResolver $organisations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uid of the signed-in user, or '' when anonymous.
	 *
	 * @return string The uid.
	 */
	private function uid(): string {
		$user = $this->users->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end uid()

	/**
	 * The tenant this request acts as, or null.
	 *
	 * Null when nobody is signed in, when OpenRegister answers no active
	 * organisation or cannot be read, and when the user has no `tenantUser`
	 * row for it. A membership lookup that fails reads as no tenant: binding
	 * nothing is the safe reading of an unknown answer.
	 *
	 * @return array<string, mixed>|null The tenant-shaped organisation, or null.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function activeTenant(): ?array {
		$uid = $this->uid();
		if ($uid === '') {
			return null;
		}

		$organisation = $this->organisations->resolveActive();
		$tenantId = (string)($organisation['uuid'] ?? '');
		if ($organisation === null || $tenantId === '') {
			return null;
		}

		try {
			$member = $this->auth->isMemberOf(tenantId: $tenantId, userId: $uid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: membership lookup failed while resolving the session tenant',
				['uid' => $uid, 'tenantId' => $tenantId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($member === false) {
			$this->logger->info(
				'Dossiq: the active organisation has no tenantUser row for this user; no tenant',
				['uid' => $uid, 'tenantId' => $tenantId]
			);

			return null;
		}

		return $organisation;
	}//end activeTenant()

	/**
	 * The uuid of the tenant this request acts as, or null.
	 *
	 * @return string|null The tenant id, or null when none is resolved.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function activeTenantId(): ?string {
		$tenant = $this->activeTenant();
		if ($tenant === null) {
			return null;
		}

		return (string)$tenant['uuid'];
	}//end activeTenantId()
}//end class
