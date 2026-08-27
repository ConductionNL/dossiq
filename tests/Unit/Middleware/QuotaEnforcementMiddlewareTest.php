<?php

/**
 * QuotaEnforcementMiddleware Unit Tests
 *
 * PINNING TESTS, written before the tenancy store moves onto OpenRegister's
 * Organisation entity. Quotas are per-tenant, so this middleware reads the
 * bound tenant on every request and the migration changes where that comes
 * from. It had no tests.
 *
 * What makes a quota regression quiet: over-counting throttles a tenant that
 * should be fine, and under-counting lets one tenant spend another's allowance
 * — neither throws, and both look like ordinary traffic in a log.
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
 *
 * @covers \OCA\Dossiq\Middleware\QuotaEnforcementMiddleware
 *
 * TenantContext is exercised for real rather than mocked: the middleware passes
 * getTenantId() straight to the quota service, so the row-to-id derivation is
 * part of what these tests pin. phpunit.xml sets beStrictAboutCoverageMetadata
 * + failOnRisky, so the collaborator has to be declared.
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Middleware\QuotaEnforcementMiddleware;
use OCA\Dossiq\Middleware\QuotaExceededException;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantQuotaService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Middleware\QuotaEnforcementMiddleware
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 */
class QuotaEnforcementMiddlewareTest extends TestCase {
	/**
	 * Build the middleware over a controllable request and quota decision.
	 *
	 * @param string                    $method      HTTP verb.
	 * @param string                    $uri         Request URI.
	 * @param array<string, mixed>|null $decision    Quota decision, or null when none is expected.
	 * @param string|null               $boundTenant Tenant to bind, or null for unbound.
	 *
	 * @return array{0: QuotaEnforcementMiddleware, 1: TenantQuotaService} Middleware and the quota mock.
	 */
	private function newMiddleware(
		string $method,
		string $uri,
		?array $decision,
		?string $boundTenant,
	): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn($uri);

		$quota = $this->createMock(TenantQuotaService::class);
		if ($decision !== null) {
			$quota->method('consume')->willReturn($decision);
		}

		$context = new TenantContext();
		if ($boundTenant !== null) {
			$context->bind(['uuid' => $boundTenant, 'slug' => $boundTenant], 'tenant_' . $boundTenant);
		}

		$middleware = new QuotaEnforcementMiddleware(
			request: $request,
			context: $context,
			quota: $quota,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$middleware, $quota];
	}

	/**
	 * A blocking decision must stop the request, not merely log it.
	 *
	 * @return void
	 */
	public function testBlockDecisionRefusesTheRequest(): void {
		[$middleware] = $this->newMiddleware(
			method: 'POST',
			uri: '/api/cases',
			decision: ['decision' => TenantQuotaService::DECISION_BLOCK, 'soft' => false],
			boundTenant: 'tenant-a',
		);

		$this->expectException(QuotaExceededException::class);
		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * A throttle decision is observed but does NOT refuse — pinned, not endorsed.
	 *
	 * Throttle logs a warning and lets the request through. Whether that is the
	 * intended contract is a question for the migration; what matters here is
	 * that a refactor cannot silently turn it into a block or a no-op.
	 *
	 * @return void
	 */
	public function testThrottleDecisionAllowsTheRequestThrough(): void {
		[$middleware] = $this->newMiddleware(
			method: 'POST',
			uri: '/api/cases',
			decision: ['decision' => TenantQuotaService::DECISION_THROTTLE, 'soft' => false],
			boundTenant: 'tenant-a',
		);

		$middleware->beforeController(new \stdClass(), 'create');
		$this->addToAssertionCount(count: 1);
	}

	/**
	 * The quota is consumed against the BOUND tenant, not some other one.
	 *
	 * This is the assertion the migration has to keep true: under-counting lets
	 * one tenant spend another's allowance, and nothing about the response would
	 * say so.
	 *
	 * @return void
	 */
	public function testQuotaIsConsumedAgainstTheBoundTenant(): void {
		[$middleware, $quota] = $this->newMiddleware(
			method: 'POST',
			uri: '/api/cases',
			decision: null,
			boundTenant: 'tenant-a',
		);

		$quota->expects($this->once())
			->method('consume')
			->with('tenant-a', 'cases_per_month', 1)
			->willReturn(['decision' => 'allow', 'soft' => false]);

		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * With no tenant bound there is no allowance to spend.
	 *
	 * @return void
	 */
	public function testUnboundContextConsumesNothing(): void {
		[$middleware, $quota] = $this->newMiddleware(
			method: 'POST',
			uri: '/api/cases',
			decision: null,
			boundTenant: null,
		);

		$quota->expects($this->never())->method('consume');

		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * A path outside the quota vocabulary consumes nothing.
	 *
	 * @return void
	 */
	public function testNonApiPathConsumesNothing(): void {
		[$middleware, $quota] = $this->newMiddleware(
			method: 'GET',
			uri: '/apps/dossiq/cases',
			decision: null,
			boundTenant: 'tenant-a',
		);

		$quota->expects($this->never())->method('consume');

		$middleware->beforeController(new \stdClass(), 'index');
	}

	/**
	 * A general API call falls to the hourly bucket, not the monthly case one.
	 *
	 * The two buckets are not interchangeable: charging an ordinary read against
	 * cases_per_month would exhaust a tenant's monthly case allowance with GETs.
	 *
	 * @return void
	 */
	public function testGeneralApiCallUsesTheHourlyBucket(): void {
		[$middleware, $quota] = $this->newMiddleware(
			method: 'GET',
			uri: '/api/tasks',
			decision: null,
			boundTenant: 'tenant-a',
		);

		$quota->expects($this->once())
			->method('consume')
			->with('tenant-a', 'api_calls_per_hour', 1)
			->willReturn(['decision' => 'allow', 'soft' => false]);

		$middleware->beforeController(new \stdClass(), 'index');
	}
}
