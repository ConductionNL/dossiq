<?php

/**
 * Tenant Organisation Resolver test
 *
 * The middleware chain has one resolution point, and this is it. Every test
 * here is written so that removing the thing it names reddens it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantOrganisationResolver;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The `findByUuid` seam the resolver calls on OpenRegister's mapper.
 *
 * Declared here rather than mocked off the real mapper: the real class takes a
 * database handle, and the only thing the resolver asks of it is this method.
 */
interface OrganisationMapperStub {
	/**
	 * Find one Organisation by uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation The organisation.
	 */
	public function findByUuid(string $uuid): Organisation;
}

/**
 * @covers \OCA\Dossiq\Service\TenantOrganisationResolver
 */
class TenantOrganisationResolverTest extends TestCase {
	/**
	 * A resolver over a fake OpenRegister.
	 *
	 * @param array<string, Organisation> $organisations Organisations by uuid.
	 * @param bool                        $openRegister  Whether OpenRegister is installed.
	 *
	 * @return TenantOrganisationResolver The resolver.
	 */
	private function resolverOver(
		array $organisations,
		bool $openRegister = true,
	): TenantOrganisationResolver {
		$mapper = $this->createMock(OrganisationMapperStub::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($organisations): Organisation {
				if (array_key_exists($uuid, $organisations) === false) {
					throw new \RuntimeException('no such organisation');
				}

				return $organisations[$uuid];
			}
		);

		$installed = [];
		if ($openRegister === true) {
			$installed = ['openregister'];
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new TenantOrganisationResolver(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * An Organisation with the given fields.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $slug   The slug.
	 * @param string $status The lifecycle status.
	 * @param string $name   The name.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid, string $slug, string $status, string $name = 'Gemeente'): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setSlug($slug);
		$organisation->setStatus($status);
		$organisation->setName($name);

		return $organisation;
	}

	/**
	 * The Organisation is what resolves.
	 *
	 * @return void
	 */
	public function testTheOrganisationResolves(): void {
		$resolver = $this->resolverOver(
			organisations: ['org-a' => $this->organisation(uuid: 'org-a', slug: 'from-organisation', status: 'active')],
		);

		$tenant = $resolver->resolve(tenantId: 'org-a');

		$this->assertNotNull($tenant);
		$this->assertSame('from-organisation', $tenant['slug']);
		$this->assertSame('org-a', $tenant['uuid']);
		$this->assertSame('org-a', $tenant['id']);
	}

	/**
	 * A tenant id with no Organisation resolves to nothing, and no legacy store is asked (REQ-TOO-003).
	 *
	 * The fallback onto the retired `tenant` admin store is gone: the resolver
	 * takes no `TenantSaasService` at all, so nothing it could read remains.
	 *
	 * @return void
	 */
	public function testATenantIdWithNoOrganisationResolvesToNothing(): void {
		$this->assertNull($this->resolverOver(organisations: [])->resolve(tenantId: 'tenant-a'));

		$parameters = array_map(
			static fn (\ReflectionParameter $p): string => (string) $p->getType(),
			(new \ReflectionMethod(TenantOrganisationResolver::class, '__construct'))->getParameters()
		);
		$this->assertNotContains('OCA\\Dossiq\\Service\\TenantSaasService', $parameters, 'The legacy reader must not be injected any more.');
	}

	/**
	 * The empty tenant id is refused.
	 *
	 * @return void
	 */
	public function testTheEmptyTenantIdIsRefused(): void {
		$this->assertNull($this->resolverOver(organisations: [])->resolve(tenantId: ''));
	}

	/**
	 * The Organisation's own status is what comes through, unmapped.
	 *
	 * `TenantMiddleware` lets exactly `active` past, so what this returns for
	 * `retained` decides whether a tenant whose access has ended can still
	 * make requests. Mapping `retained` to anything friendlier here would open
	 * it, and no response would say so.
	 *
	 * @return void
	 */
	public function testARetainedOrganisationResolvesAsRetainedAndNotAsActive(): void {
		$resolver = $this->resolverOver(
			organisations: ['org-r' => $this->organisation(uuid: 'org-r', slug: 'ended', status: 'retained')],
		);

		$tenant = $resolver->resolve(tenantId: 'org-r');

		$this->assertNotNull($tenant);
		$this->assertSame('retained', $tenant['status']);
		$this->assertNotSame('active', $tenant['status']);
	}

	/**
	 * A suspended Organisation resolves as suspended.
	 *
	 * @return void
	 */
	public function testASuspendedOrganisationResolvesAsSuspended(): void {
		$resolver = $this->resolverOver(
			organisations: ['org-s' => $this->organisation(uuid: 'org-s', slug: 'paused', status: 'suspended')],
		);

		$this->assertSame('suspended', $this->resolverOver(
			organisations: ['org-s' => $this->organisation(uuid: 'org-s', slug: 'paused', status: 'suspended')],
		)->resolve(tenantId: 'org-s')['status']);
		$this->assertNotNull($resolver->resolve(tenantId: 'org-s'));
	}

	/**
	 * Without OpenRegister there is no Organisation, so there is no tenant.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNoTenant(): void {
		$resolver = $this->resolverOver(
			organisations: ['org-a' => $this->organisation(uuid: 'org-a', slug: 'from-organisation', status: 'active')],
			openRegister: false,
		);

		$this->assertNull($resolver->resolve(tenantId: 'org-a'));
	}
}//end class
