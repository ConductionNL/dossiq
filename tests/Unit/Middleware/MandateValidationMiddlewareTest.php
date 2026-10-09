<?php

/**
 * MandateValidationMiddleware Unit Tests
 *
 * The bound tenant is OpenRegister's active organisation, read through the
 * real `TenantContext`, `TenantSessionService` and `TenantOrganisationResolver`
 * with only the OpenRegister seams doubled. The refusal of an organisation
 * that is not active moved here from the deleted `TenantMiddleware` (Q3).
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
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Middleware\MandateDeniedException;
use OCA\Dossiq\Middleware\MandateValidationMiddleware;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantService;
use OCA\Dossiq\Tests\Support\MakesActiveOrganisationContext;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Middleware\MandateValidationMiddleware
 * @covers \OCA\Dossiq\Middleware\MandateDeniedException
 *
 * @uses \OCA\Dossiq\Service\TenantContext
 * @uses \OCA\Dossiq\Service\TenantSessionService
 * @uses \OCA\Dossiq\Service\TenantOrganisationResolver
 */
class MandateValidationMiddlewareTest extends TestCase {
	use MakesActiveOrganisationContext;

	/**
	 * A middleware for the verb map, with no organisation behind it.
	 *
	 * @return MandateValidationMiddleware The middleware.
	 */
	private function newMiddleware(): MandateValidationMiddleware {
		return new MandateValidationMiddleware(
			request: $this->createMock(IRequest::class),
			userSession: $this->createMock(IUserSession::class),
			context: $this->activeOrganisationContext(active: null, stored: [], memberships: []),
			authService: $this->createMock(TenantAuthenticationService::class),
			tenantService: $this->createMock(TenantService::class),
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
	 * Build the middleware for a signed-in user over the real tenant chain.
	 *
	 * PINNING, added 2026-09-11, rebuilt for the active organisation: turning
	 * a denied decision into an allowed request, or asking the matrix about a
	 * different tenant than the bound one, must turn the suite red.
	 *
	 * @param TenantContext $context     The real context.
	 * @param string        $verb        The HTTP verb.
	 * @param bool          $platformAdmin Whether the user is a platform admin.
	 *
	 * @return array{0: MandateValidationMiddleware, 1: TenantAuthenticationService} Middleware and the auth mock.
	 */
	private function newGatedMiddleware(TenantContext $context, string $verb = 'POST', bool $platformAdmin = false): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($verb);
		$request->method('getRequestUri')->willReturn('/api/cases');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$tenantService = $this->createMock(TenantService::class);
		$tenantService->method('isPlatformAdmin')->willReturnCallback(
			static fn (string $userId): bool => $platformAdmin === true && $userId === 'alice'
		);

		$auth = $this->createMock(TenantAuthenticationService::class);

		$middleware = new MandateValidationMiddleware(
			request: $request,
			userSession: $userSession,
			context: $context,
			authService: $auth,
			tenantService: $tenantService,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$middleware, $auth];
	}

	/**
	 * A context whose active organisation is $uuid, a member of it, stored with $status.
	 *
	 * @param string $uuid   The organisation.
	 * @param string $status Its stored status.
	 *
	 * @return TenantContext The context.
	 */
	private function memberOf(string $uuid, string $status = 'active'): TenantContext {
		return $this->activeOrganisationContext(
			active: $uuid,
			stored: [$uuid => $this->organisationRow(uuid: $uuid, status: $status)],
			memberships: [$uuid],
		);
	}

	/**
	 * A denied decision stops the request.
	 *
	 * @return void
	 */
	public function testADeniedDecisionRefusesTheRequest(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(context: $this->memberOf(uuid: 'tenant-a'));
		$auth->method('validateMandateMatrix')->willReturn(['allowed' => false, 'reason' => 'Role viewer is not authorised']);

		$this->expectException(MandateDeniedException::class);
		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * The matrix is asked about the bound tenant, for the signed-in user.
	 *
	 * @return void
	 */
	public function testTheMatrixIsAskedAboutTheBoundTenant(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(context: $this->memberOf(uuid: 'tenant-a'));
		$auth->expects($this->once())
			->method('validateMandateMatrix')
			->with('tenant-a', 'alice', 'create')
			->willReturn(['allowed' => true, 'reason' => 'Authorised by mandate matrix']);

		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * The mandate check follows the organisation set active in OpenRegister (REQ-TAO-002).
	 *
	 * The user belongs to A and B, with B active. A matrix read for A would
	 * answer with A's mandates, formatted correctly, and nothing would say so.
	 *
	 * @return void
	 */
	public function testTheMandateCheckRunsForOpenRegistersActiveOrganisation(): void {
		$context = $this->activeOrganisationContext(
			active: 'org-b',
			stored: ['org-a' => $this->organisationRow(uuid: 'org-a'), 'org-b' => $this->organisationRow(uuid: 'org-b')],
			memberships: ['org-a', 'org-b'],
		);
		[$middleware, $auth] = $this->newGatedMiddleware(context: $context);
		$auth->expects($this->once())
			->method('validateMandateMatrix')
			->with('org-b', 'alice', 'create')
			->willReturn(['allowed' => true, 'reason' => 'Authorised by mandate matrix']);

		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * No active organisation: the request is not mandate-checked.
	 *
	 * PINNED AS-IS: the mandate matrix applies only to a request with a
	 * tenant. Named for what it is, so a green suite cannot be read as "every
	 * write is mandate-checked".
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationLeavesTheRequestUnchecked(): void {
		$context = $this->activeOrganisationContext(active: null, stored: [], memberships: ['org-a']);
		[$middleware, $auth] = $this->newGatedMiddleware(context: $context);
		$auth->expects($this->never())->method('validateMandateMatrix');

		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * A suspended organisation is refused with 403 on a dossiq route (REQ-TAO-003).
	 *
	 * Refused before the action check, so a read is refused too, and the
	 * answer has the shape `TenantMiddleware::afterException()` gave.
	 *
	 * @return void
	 */
	public function testASuspendedOrganisationIsRefusedOnADossiqRoute(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(context: $this->memberOf(uuid: 'org-a', status: 'suspended'), verb: 'GET');
		$auth->expects($this->never())->method('validateMandateMatrix');

		try {
			$middleware->beforeController(new \stdClass(), 'index');
			$this->fail('a suspended organisation must be refused');
		} catch (MandateDeniedException $refusal) {
			$response = $middleware->afterException(new \stdClass(), 'index', $refusal);
		}

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'Organisation is suspended', 'status' => 'suspended'],
			$response->getData()
		);
	}

	/**
	 * A retained organisation is refused the same way (REQ-TAO-003).
	 *
	 * @return void
	 */
	public function testARetainedOrganisationIsRefusedOnADossiqRoute(): void {
		[$middleware] = $this->newGatedMiddleware(context: $this->memberOf(uuid: 'org-a', status: 'retained'));

		$this->expectException(MandateDeniedException::class);
		$this->expectExceptionCode(403);
		$this->expectExceptionMessage('Organisation is retained');
		$middleware->beforeController(new \stdClass(), 'create');
	}

	/**
	 * A platform admin whose active organisation is suspended is let through (REQ-TAO-003).
	 *
	 * @return void
	 */
	public function testAPlatformAdminIsNotRefused(): void {
		[$middleware, $auth] = $this->newGatedMiddleware(
			context: $this->memberOf(uuid: 'org-a', status: 'suspended'),
			platformAdmin: true,
		);
		$auth->method('validateMandateMatrix')->willReturn(['allowed' => true, 'reason' => 'Authorised by mandate matrix']);

		$middleware->beforeController(new \stdClass(), 'create');
		$this->addToAssertionCount(1);
	}

	/**
	 * A user with no organisation is let through, as on a single-tenant install (REQ-TAO-003).
	 *
	 * @return void
	 */
	public function testAUserWithNoOrganisationIsLetThrough(): void {
		$context = $this->activeOrganisationContext(active: null, stored: [], memberships: []);
		[$middleware, $auth] = $this->newGatedMiddleware(context: $context, verb: 'GET');
		$auth->expects($this->never())->method('validateMandateMatrix');

		$middleware->beforeController(new \stdClass(), 'index');
		$this->assertFalse($context->isBound());
	}
}
