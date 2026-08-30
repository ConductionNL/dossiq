<?php

/**
 * TenantContextMiddleware Unit Tests
 *
 * These began as PINNING tests written before the tenancy store moved, and
 * recorded that the `X-Tenant-Id` header alone bound the tenant. That is the
 * behaviour this change removes, so the tests now assert its inverse — which
 * is what a pinning test is for: it made the change visible instead of silent.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Middleware\TenantContextMiddleware;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantProvisioningService;
use OCA\Dossiq\Service\TenantSaasService;
use OCA\Dossiq\Service\TenantSessionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Middleware\TenantContextMiddleware
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 */
class TenantContextMiddlewareTest extends TestCase {
	/**
	 * Build the middleware over a controllable header, session and lookup.
	 *
	 * The tenant lookup is keyed on the id it is ASKED for rather than
	 * returning a fixed row: a mock that answers the same row to every id would
	 * let these tests pass even if the middleware resolved the wrong tenant.
	 * That mutant survived the first draft, which is why the lookup is modelled
	 * rather than stubbed.
	 *
	 * @param string        $header        The X-Tenant-Id header value.
	 * @param string|null   $sessionTenant The tenant the session resolves to.
	 * @param array<string> $known         Tenant ids the lookup can resolve.
	 *
	 * @return array{0: TenantContextMiddleware, 1: TenantContext} Middleware and the live context.
	 */
	private function newMiddleware(
		string $header,
		?string $sessionTenant,
		array $known,
	): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($header);

		$tenantSession = $this->createMock(TenantSessionService::class);
		$tenantSession->method('activeTenantId')->willReturn($sessionTenant);

		$saas = $this->createMock(TenantSaasService::class);
		$saas->method('getById')->willReturnCallback(
			static function (string $tenantId) use ($known): ?array {
				if (in_array($tenantId, $known, true) === false) {
					return null;
				}

				return ['uuid' => $tenantId, 'slug' => $tenantId];
			}
		);

		$provisioning = $this->createMock(TenantProvisioningService::class);
		$provisioning->method('buildSchemaName')->willReturn('tenant_schema');

		$context = new TenantContext();

		$middleware = new TenantContextMiddleware(
			request: $request,
			tenantSession: $tenantSession,
			tenantSaasService: $saas,
			provisioning: $provisioning,
			context: $context,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$middleware, $context];
	}

	/**
	 * THE FIX: the X-Tenant-Id header no longer binds anything.
	 *
	 * Previously this header was returned verbatim with nothing checking the
	 * caller could act for that tenant, and the middleware that would have
	 * caught a forged value only inspects requests carrying a Bearer token — so
	 * a session-authenticated user could name any tenant and be believed.
	 *
	 * The header is supplied by the caller, and the caller is exactly who must
	 * not choose. Here it names a tenant that genuinely exists and the session
	 * resolves to nothing: if the header still had any force, this would bind.
	 *
	 * @return void
	 */
	public function testTheHeaderNoLongerBindsATenant(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: 'tenant-anything',
			sessionTenant: null,
			known: ['tenant-anything'],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertFalse($context->isBound());
	}

	/**
	 * A header naming a DIFFERENT tenant cannot override the session's.
	 *
	 * The companion to the test above, and the one that catches a partial fix:
	 * an implementation that consulted the session but let a present header win
	 * would still pass a test where the session resolves to nothing.
	 *
	 * @return void
	 */
	public function testTheHeaderCannotOverrideTheSessionTenant(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: 'tenant-b',
			sessionTenant: 'tenant-a',
			known: ['tenant-a', 'tenant-b'],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertTrue($context->isBound());
		$this->assertSame('tenant-a', $context->getTenantId());
	}

	/**
	 * The session's tenant is what binds.
	 *
	 * @return void
	 */
	public function testTheSessionTenantBinds(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: '',
			sessionTenant: 'tenant-a',
			known: ['tenant-a'],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertTrue($context->isBound());
		$this->assertSame('tenant-a', $context->getTenantId());
	}

	/**
	 * A session tenant that no longer resolves binds nothing.
	 *
	 * Fail-closed in the useful direction: the request has nothing to scope TO
	 * rather than silently scoping to somebody.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSessionTenantBindsNothing(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: '',
			sessionTenant: 'tenant-ghost',
			known: [],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertFalse($context->isBound());
	}

	/**
	 * No session tenant, no binding.
	 *
	 * @return void
	 */
	public function testNoSessionTenantBindsNothing(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: '',
			sessionTenant: null,
			known: ['tenant-a'],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertFalse($context->isBound());
	}
}
