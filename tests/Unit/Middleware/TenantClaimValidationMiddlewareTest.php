<?php

/**
 * TenantClaimValidationMiddleware Unit Tests
 *
 * PINNING TESTS, written BEFORE the tenancy store moves onto OpenRegister's
 * Organisation entity. This middleware is the cross-tenant boundary: it refuses
 * a request whose JWT `tenant_id` claim disagrees with the tenant bound from
 * the request. There were no tests for it at all, and the migration rewrites
 * what it reads — so these exist to make a scoping regression LOUD rather than
 * silent, because the failure mode here is one tenant seeing another's data
 * while every response still looks plausible.
 *
 * They pin CURRENT behaviour, including one case that is arguably wrong and is
 * called out by name rather than quietly blessed — see
 * testEmptyClaimIsAllowedThrough().
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

use OCA\Dossiq\Middleware\TenantClaimMismatchException;
use OCA\Dossiq\Middleware\TenantClaimValidationMiddleware;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantJwtService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Middleware\TenantClaimValidationMiddleware
 *
 * TenantContext is exercised for real rather than mocked: the middleware
 * compares against getTenantId(), which derives the id from the bound row, so a
 * mock would let the test agree with itself about a shape the real class does
 * not produce. phpunit.xml sets beStrictAboutCoverageMetadata + failOnRisky, so
 * that collaborator has to be declared.
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 */
class TenantClaimValidationMiddlewareTest extends TestCase {
	/**
	 * Build the middleware with a controllable request, JWT service and context.
	 *
	 * @param string                    $authHeader The Authorization header value.
	 * @param array<string, mixed>|null $claims     Claims to return, or null to throw.
	 * @param string|null               $boundTenant Tenant to bind, or null for unbound.
	 *
	 * @return TenantClaimValidationMiddleware The middleware.
	 */
	private function newMiddleware(
		string $authHeader,
		?array $claims,
		?string $boundTenant,
	): TenantClaimValidationMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($authHeader);

		$jwt = $this->createMock(TenantJwtService::class);
		if ($claims === null) {
			$jwt->method('validate')->willThrowException(new RuntimeException('bad token'));
		} else {
			$jwt->method('validate')->willReturn($claims);
		}

		$context = new TenantContext();
		if ($boundTenant !== null) {
			// bind() takes the tenant ROW and derives the id from uuid/id — the
			// middleware compares against getTenantId(), so the row shape is
			// part of what these tests pin.
			$context->bind(['uuid' => $boundTenant, 'slug' => $boundTenant], 'tenant_' . $boundTenant);
		}

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new TenantClaimValidationMiddleware(
			request: $request,
			context: $context,
			jwt: $jwt,
			cacheFactory: $cacheFactory,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * THE ONE THAT MATTERS: a token for tenant A must not act on tenant B.
	 *
	 * @return void
	 */
	public function testMismatchedClaimIsRefused(): void {
		$middleware = $this->newMiddleware(
			authHeader: 'Bearer token',
			claims: ['tenant_id' => 'tenant-a', 'sub' => 'alice'],
			boundTenant: 'tenant-b',
		);

		$this->expectException(TenantClaimMismatchException::class);
		$middleware->beforeController(new \stdClass(), 'index');
	}

	/**
	 * A matching claim passes.
	 *
	 * @return void
	 */
	public function testMatchingClaimPasses(): void {
		$middleware = $this->newMiddleware(
			authHeader: 'Bearer token',
			claims: ['tenant_id' => 'tenant-a', 'sub' => 'alice'],
			boundTenant: 'tenant-a',
		);

		$middleware->beforeController(new \stdClass(), 'index');
		$this->addToAssertionCount(count: 1);
	}

	/**
	 * AN EMPTY OR ABSENT `tenant_id` CLAIM IS ALLOWED THROUGH — pinned, not endorsed.
	 *
	 * The guard reads `$jwtTenantId !== '' && $jwtTenantId !== $requestTenantId`,
	 * so a token carrying NO tenant claim satisfies it against ANY bound tenant.
	 * That is a fail-open: the check that exists to stop cross-tenant access is
	 * skipped precisely when the token declines to say which tenant it is for.
	 *
	 * This test asserts the behaviour as it stands so the migration cannot
	 * change it by accident. It is deliberately named for what it is, so nobody
	 * reads a green suite as "the empty-claim case is handled". Whether an
	 * absent claim should be refused is a decision for the tenancy migration,
	 * not something to quietly flip inside a refactor.
	 *
	 * @return void
	 */
	public function testEmptyClaimIsAllowedThrough(): void {
		$middleware = $this->newMiddleware(
			authHeader: 'Bearer token',
			claims: ['sub' => 'alice'],
			boundTenant: 'tenant-b',
		);

		$middleware->beforeController(new \stdClass(), 'index');
		$this->addToAssertionCount(count: 1);
	}

	/**
	 * A non-bearer request is not this middleware's business.
	 *
	 * @return void
	 */
	public function testNonBearerRequestIsIgnored(): void {
		$middleware = $this->newMiddleware(
			authHeader: 'Basic abc',
			claims: ['tenant_id' => 'tenant-a'],
			boundTenant: 'tenant-b',
		);

		$middleware->beforeController(new \stdClass(), 'index');
		$this->addToAssertionCount(count: 1);
	}

	/**
	 * An unverifiable token is left to the auth chain rather than double-handled.
	 *
	 * @return void
	 */
	public function testInvalidTokenIsLeftToTheAuthChain(): void {
		$middleware = $this->newMiddleware(
			authHeader: 'Bearer rubbish',
			claims: null,
			boundTenant: 'tenant-b',
		);

		$middleware->beforeController(new \stdClass(), 'index');
		$this->addToAssertionCount(count: 1);
	}

	/**
	 * With no tenant bound there is nothing to compare against.
	 *
	 * @return void
	 */
	public function testUnboundContextSkipsTheCheck(): void {
		$middleware = $this->newMiddleware(
			authHeader: 'Bearer token',
			claims: ['tenant_id' => 'tenant-a'],
			boundTenant: null,
		);

		$middleware->beforeController(new \stdClass(), 'index');
		$this->addToAssertionCount(count: 1);
	}
}
