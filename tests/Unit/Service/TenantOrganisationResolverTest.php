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
use OCA\Dossiq\Service\TenantSaasService;
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
	 * Every tenant id the legacy `tenant` schema was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $legacyReads = [];

	/**
	 * A resolver over a fake OpenRegister and a fake legacy store.
	 *
	 * @param array<string, Organisation>      $organisations Organisations by uuid.
	 * @param array<string, array<string,mixed>> $legacy      Legacy tenant rows by uuid.
	 * @param bool                             $openRegister Whether OpenRegister is installed.
	 *
	 * @return TenantOrganisationResolver The resolver.
	 */
	private function resolverOver(
		array $organisations,
		array $legacy = [],
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

		$saas = $this->createMock(TenantSaasService::class);
		$saas->method('getById')->willReturnCallback(
			function (string $tenantId) use ($legacy): ?array {
				$this->legacyReads[] = $tenantId;

				return ($legacy[$tenantId] ?? null);
			}
		);

		return new TenantOrganisationResolver(
			appManager: $appManager,
			container: $container,
			tenantSaas: $saas,
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
	 * The Organisation is what resolves, and the legacy row is not read at all.
	 *
	 * This is the whole point of the step, so it is asserted twice over. The
	 * returned row must be the Organisation's, AND the legacy `tenant` schema
	 * must not have been asked. Without the second assertion a resolver that
	 * read both and merged them would pass.
	 *
	 * Mutation: move the legacy read above the Organisation lookup in
	 * `resolve()` and this reddens on the slug.
	 *
	 * @return void
	 */
	public function testTheOrganisationResolvesAndTheLegacyTenantIsNotRead(): void {
		$resolver = $this->resolverOver(
			organisations: ['org-a' => $this->organisation(uuid: 'org-a', slug: 'from-organisation', status: 'active')],
			legacy: ['org-a' => ['uuid' => 'org-a', 'slug' => 'from-legacy-tenant', 'status' => 'active']],
		);

		$tenant = $resolver->resolve(tenantId: 'org-a');

		$this->assertNotNull($tenant);
		$this->assertSame('from-organisation', $tenant['slug']);
		$this->assertSame('org-a', $tenant['uuid']);
		$this->assertSame('org-a', $tenant['id']);
		$this->assertSame([], $this->legacyReads, 'the legacy tenant schema must not be read when an Organisation resolves');
	}

	/**
	 * An instance that has not migrated yet still resolves its tenant.
	 *
	 * The fallback is what makes this half of the step reversible. Step 5
	 * removes it together with the `tenant` schema it reads.
	 *
	 * @return void
	 */
	public function testATenantWithNoOrganisationStillResolvesFromTheLegacyRow(): void {
		$resolver = $this->resolverOver(
			organisations: [],
			legacy: ['tenant-a' => ['uuid' => 'tenant-a', 'slug' => 'not-yet-migrated', 'status' => 'active']],
		);

		$tenant = $resolver->resolve(tenantId: 'tenant-a');

		$this->assertNotNull($tenant);
		$this->assertSame('not-yet-migrated', $tenant['slug']);
		$this->assertSame(['tenant-a'], $this->legacyReads);
	}

	/**
	 * A tenant id nothing answers to resolves to nothing.
	 *
	 * @return void
	 */
	public function testATenantIdNothingAnswersToResolvesToNull(): void {
		$this->assertNull($this->resolverOver(organisations: [], legacy: [])->resolve(tenantId: 'ghost'));
	}

	/**
	 * The empty tenant id is refused before either store is asked.
	 *
	 * @return void
	 */
	public function testTheEmptyTenantIdIsRefusedWithoutAskingAnything(): void {
		$this->assertNull($this->resolverOver(organisations: [], legacy: [])->resolve(tenantId: ''));
		$this->assertSame([], $this->legacyReads);
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
	 * Without OpenRegister there is no Organisation to resolve, and the legacy
	 * row answers instead rather than the request failing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheLegacyRowAnswers(): void {
		$resolver = $this->resolverOver(
			organisations: ['org-a' => $this->organisation(uuid: 'org-a', slug: 'from-organisation', status: 'active')],
			legacy: ['org-a' => ['uuid' => 'org-a', 'slug' => 'from-legacy-tenant', 'status' => 'active']],
			openRegister: false,
		);

		$tenant = $resolver->resolve(tenantId: 'org-a');

		$this->assertNotNull($tenant);
		$this->assertSame('from-legacy-tenant', $tenant['slug']);
	}
}//end class
