<?php

/**
 * MandateValidationMiddleware Unit Tests
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Middleware\MandateDeniedException;
use OCA\Dossiq\Middleware\MandateValidationMiddleware;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Middleware\MandateValidationMiddleware
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 */
class MandateValidationMiddlewareTest extends TestCase {
	private function newMiddleware(): MandateValidationMiddleware {
		return new MandateValidationMiddleware(
			request: $this->createMock(IRequest::class),
			userSession: $this->createMock(IUserSession::class),
			context: new TenantContext(),
			authService: $this->createMock(TenantAuthenticationService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	public function testResolveActionMapsPostToCreate(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('create', $mw->resolveAction('POST', '/api/cases'));
	}

	public function testResolveActionMapsPatchToEdit(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('edit', $mw->resolveAction('PATCH', '/api/cases/abc'));
	}

	public function testResolveActionMapsDeleteToDelete(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('delete', $mw->resolveAction('DELETE', '/api/cases/abc'));
	}

	public function testResolveActionDetectsStatusUpdateFromUrl(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('status_update', $mw->resolveAction('POST', '/api/case/abc/transition'));
		$this->assertSame('status_update', $mw->resolveAction('PATCH', '/api/cases/abc/status'));
	}

	public function testResolveActionReturnsNullForGet(): void {
		$mw = $this->newMiddleware();
		$this->assertNull($mw->resolveAction('GET', '/api/cases/abc'));
	}

	/**
	 * Build the middleware for a signed-in user, optionally bound to a tenant.
	 *
	 * PINNING, added 2026-09-11. Until then only the verb map above was tested:
	 * turning a denied decision into an allowed request, or asking the matrix
	 * about a different tenant than the bound one, left the suite green. The
	 * tenancy move changes where the bound tenant comes from, so both are
	 * pinned before it does.
	 *
	 * @param string|null $boundTenant Tenant to bind, or null for unbound.
	 *
	 * @return array{0: MandateValidationMiddleware, 1: TenantAuthenticationService} Middleware and the auth mock.
	 */
	private function newGatedMiddleware(?string $boundTenant): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getRequestUri')->willReturn('/api/cases');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$context = new TenantContext();
		if ($boundTenant !== null) {
			$context->bind(['uuid' => $boundTenant, 'slug' => $boundTenant], 'tenant_' . $boundTenant);
		}

		$auth = $this->createMock(TenantAuthenticationService::class);

		$middleware = new MandateValidationMiddleware(
			request: $request,
			userSession: $userSession,
			context: $context,
			authService: $auth,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$middleware, $auth];
	}

	/**
	 * A denied decision stops the request.
	 *
	 * @return void
	 */
	public function testADeniedDecisionRefusesTheRequest(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(boundTenant: 'tenant-a');
		$auth->method('validateMandateMatrix')->willReturn(['allowed' => false, 'reason' => 'Role viewer is not authorised']);

		$this->expectException(MandateDeniedException::class);
		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * The matrix is asked about the BOUND tenant, for the signed-in user.
	 *
	 * This is the assertion the tenancy move has to keep true: a matrix read
	 * for another tenant answers with that tenant's mandates, formatted
	 * correctly, and nothing in the response says so.
	 *
	 * @return void
	 */
	public function testTheMatrixIsAskedAboutTheBoundTenant(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(boundTenant: 'tenant-a');
		$auth->expects($this->once())
			->method('validateMandateMatrix')
			->with('tenant-a', 'alice', 'create')
			->willReturn(['allowed' => true, 'reason' => 'Authorised by mandate matrix']);

		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * PINNED AS-IS: an unbound request is not gated at all.
	 *
	 * With no tenant bound the middleware returns before it asks anything, so
	 * the mandate matrix applies only to requests some earlier middleware
	 * bound. Named for what it is so a green suite cannot be read as "every
	 * write is mandate-checked". Until 2026-09-11 that was every request on a
	 * real install, because the membership lookup dropped every row
	 * OpenRegister returned; TenantScopedLookupsTest holds it to the fix.
	 *
	 * @return void
	 */
	public function testAnUnboundRequestIsNotGated(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(boundTenant: null);
		$auth->expects($this->never())->method('validateMandateMatrix');

		$middleware->beforeController(new \stdClass(), 'create');
	}
}
