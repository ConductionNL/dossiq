<?php

/**
 * Builds the real tenant chain over a doubled OpenRegister.
 *
 * `TenantContext` reads `TenantSessionService`, which reads
 * `TenantOrganisationResolver` and the `tenantUser` memberships. Every class
 * in that chain is real here. Only the OpenRegister seams are doubled:
 * `OrganisationService::getActiveOrganisation()`, `OrganisationMapper::findByUuid()`
 * and the membership lookup. So a test through `MandateValidationMiddleware`
 * proves the wiring, not a mock of it.
 *
 * The active organisation and the stored row are separate on purpose.
 * OpenRegister serves the active organisation from a session cache that keeps
 * no status, so an Organisation rebuilt from it reads `active`. The stored row
 * is the truth, and a test can give the two different statuses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantOrganisationResolver;
use OCA\Dossiq\Service\TenantSaasService;
use OCA\Dossiq\Service\TenantSessionService;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

trait MakesActiveOrganisationContext {
	/**
	 * An Organisation as OpenRegister holds it.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $status The lifecycle status.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisationRow(string $uuid, string $status = 'active'): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setSlug('slug-'.$uuid);
		$organisation->setStatus($status);
		$organisation->setName('Gemeente '.$uuid);

		return $organisation;
	}//end organisationRow()

	/**
	 * The real session service over a doubled OpenRegister.
	 *
	 * @param string|null                  $active       Uuid OpenRegister answers as active, or null for none.
	 * @param array<string, Organisation>  $stored       Stored Organisation rows by uuid.
	 * @param array<int, string>|null      $memberships  The user's tenantUser memberships, or null when the lookup throws.
	 * @param string|null                  $uid          The signed-in uid, or null when anonymous.
	 * @param bool                         $openRegister Whether OpenRegister is installed.
	 * @param bool                         $activeThrows Whether getActiveOrganisation() throws.
	 *
	 * @return TenantSessionService The service.
	 */
	private function activeOrganisationSession(
		?string $active,
		array $stored,
		?array $memberships,
		?string $uid = 'alice',
		bool $openRegister = true,
		bool $activeThrows = false,
	): TenantSessionService {
		$organisationService = $this->createMock(ActiveOrganisationServiceStub::class);
		if ($activeThrows === true) {
			$organisationService->method('getActiveOrganisation')->willThrowException(new RuntimeException('organisation store down'));
		} else {
			// As OpenRegister's session cache rebuilds it: a uuid and a name, and
			// the entity's default status, never the stored one.
			$cached = null;
			if ($active !== null) {
				$cached = new Organisation();
				$cached->setUuid($active);
			}

			$organisationService->method('getActiveOrganisation')->willReturn($cached);
		}

		$mapper = $this->createMock(OrganisationByUuidStub::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($stored): Organisation {
				if (array_key_exists($uuid, $stored) === false) {
					throw new RuntimeException('no such organisation');
				}

				return $stored[$uuid];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($organisationService, $mapper): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\OrganisationService' => $organisationService,
					'OCA\\OpenRegister\\Db\\OrganisationMapper' => $mapper,
					default => throw new RuntimeException('unknown service '.$id),
				};
			}
		);

		$installed = [];
		if ($openRegister === true) {
			$installed = ['openregister'];
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed);

		$legacy = $this->createMock(TenantSaasService::class);
		$legacy->method('getById')->willReturn(null);

		$resolver = new TenantOrganisationResolver(
			appManager: $appManager,
			container: $container,
			tenantSaas: $legacy,
			logger: $this->createMock(LoggerInterface::class),
		);

		$users = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$users->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users->method('getUser')->willReturn($user);
		}

		$auth = $this->createMock(TenantAuthenticationService::class);
		if ($memberships === null) {
			$auth->method('isMemberOf')->willThrowException(new RuntimeException('membership store down'));
		} else {
			$auth->method('isMemberOf')->willReturnCallback(
				static fn (string $tenantId, string $userId): bool => in_array($tenantId, $memberships, true)
			);
		}

		return new TenantSessionService(
			users: $users,
			auth: $auth,
			organisations: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end activeOrganisationSession()

	/**
	 * The real tenant context over the same chain.
	 *
	 * @param string|null                 $active      Uuid OpenRegister answers as active, or null.
	 * @param array<string, Organisation> $stored      Stored Organisation rows by uuid.
	 * @param array<int, string>|null     $memberships The user's tenantUser memberships, or null to throw.
	 * @param string|null                 $uid         The signed-in uid, or null.
	 *
	 * @return TenantContext The context.
	 */
	private function activeOrganisationContext(
		?string $active,
		array $stored,
		?array $memberships,
		?string $uid = 'alice',
	): TenantContext {
		return new TenantContext(
			session: $this->activeOrganisationSession(
				active: $active,
				stored: $stored,
				memberships: $memberships,
				uid: $uid,
			)
		);
	}//end activeOrganisationContext()
}//end trait
