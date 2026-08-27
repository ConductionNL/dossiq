<?php

/**
 * TenantContextMiddleware Unit Tests
 *
 * PINNING TESTS, written before the tenancy store moves onto OpenRegister's
 * Organisation entity. This middleware decides WHICH TENANT a request acts as,
 * and everything downstream — quota, isolation, the claim check — trusts what
 * it bound. It had no tests.
 *
 * Two behaviours are pinned here that are worth a decision rather than a
 * refactor: see testHeaderAloneBindsTheTenant() and
 * testUserFallbackResolvesNothing().
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
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Middleware\TenantContextMiddleware
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 */
class TenantContextMiddlewareTest extends TestCase {
	/**
	 * Build the middleware over a controllable request and tenant lookup.
	 *
	 * The tenant lookup is keyed on the id it is ASKED for rather than returning
	 * a fixed row: a mock that answers the same row to every id would let this
	 * test pass even if the middleware ignored the header entirely. That mutant
	 * survived the first draft of these tests, which is why the lookup is
	 * modelled instead of stubbed.
	 *
	 * @param string        $header   The X-Tenant-Id header value.
	 * @param array<string> $known    Tenant ids the lookup can resolve.
	 * @param bool          $signedIn Whether a user session exists.
	 *
	 * @return array{0: TenantContextMiddleware, 1: TenantContext} Middleware and the live context.
	 */
	private function newMiddleware(
		string $header,
		array $known,
		bool $signedIn = true,
	): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($header);

		$userSession = $this->createMock(IUserSession::class);
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		$saas = $this->createMock(TenantSaasService::class);
		$saas->method('getById')->willReturnCallback(
			static function (string $tenantId) use ($known): ?array {
				if (in_array($tenantId, $known, true) === false) {
					return null;
				}

				return ['uuid' => $tenantId, 'slug' => $tenantId];
			}
		);
		$saas->method('listActive')->willReturn([]);

		$provisioning = $this->createMock(TenantProvisioningService::class);
		$provisioning->method('buildSchemaName')->willReturn('tenant_schema');

		$context = new TenantContext();

		$middleware = new TenantContextMiddleware(
			request: $request,
			userSession: $userSession,
			tenantSaasService: $saas,
			provisioning: $provisioning,
			context: $context,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$middleware, $context];
	}

	/**
	 * THE X-Tenant-Id HEADER ALONE BINDS THE TENANT — pinned, not endorsed.
	 *
	 * `resolveTenantIdFromRequest()` returns the header verbatim, with no check
	 * that the caller may act for that tenant. Nothing in THIS middleware
	 * verifies it.
	 *
	 * `TenantClaimValidationMiddleware` is what catches a forged value — but it
	 * returns early unless the request carries a `Bearer ` Authorization header.
	 * A session-authenticated request with a forged X-Tenant-Id and no bearer
	 * token therefore passes both: one binds on trust, the other declines to
	 * look. The two middlewares are each individually reasonable and the gap is
	 * between them, which is why neither file reads as wrong on its own.
	 *
	 * Pinned as-is so the migration cannot change it by accident, and named for
	 * what it is so a green suite is not read as "the header is validated".
	 * Whether the header should be verified against the session user's
	 * memberships is a decision for the migration, not a refactor: tightening it
	 * may break service-to-service callers that rely on it today.
	 *
	 * @return void
	 */
	public function testHeaderAloneBindsTheTenant(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: 'tenant-anything',
			known: ['tenant-anything'],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertTrue($context->isBound());
		$this->assertSame('tenant-anything', $context->getTenantId());
	}

	/**
	 * An unresolvable tenant binds nothing rather than binding a default.
	 *
	 * Fail-closed in the useful direction: an unknown id leaves the request
	 * unbound, so the downstream scoping has nothing to scope TO rather than
	 * silently scoping to somebody.
	 *
	 * @return void
	 */
	public function testUnknownTenantLeavesTheRequestUnbound(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: 'tenant-ghost',
			known: [],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertFalse($context->isBound());
	}

	/**
	 * With no header, nothing binds — the user fallback resolves nothing.
	 *
	 * The fallback branch reads the active-tenant list and then discards it
	 * (`unset($rows); return null;`), with a comment saying the per-user filter
	 * does not exist yet. So a signed-in user with no header is unbound, and the
	 * lookup it performs is pure cost. Pinned so the dead branch is visible: it
	 * either grows a real filter or it goes.
	 *
	 * @return void
	 */
	public function testUserFallbackResolvesNothing(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: '',
			known: ['tenant-a'],
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertFalse($context->isBound());
	}

	/**
	 * An anonymous request with no header binds nothing.
	 *
	 * @return void
	 */
	public function testAnonymousRequestBindsNothing(): void {
		[$middleware, $context] = $this->newMiddleware(
			header: '',
			known: [],
			signedIn: false,
		);

		$middleware->beforeController(new \stdClass(), 'index');

		$this->assertFalse($context->isBound());
	}
}
