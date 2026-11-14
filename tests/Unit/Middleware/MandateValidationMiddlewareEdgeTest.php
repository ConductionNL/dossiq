<?php

/**
 * MandateValidationMiddleware edge tests: the audit rows a decision and a
 * lifecycle refusal write, the controllers exempt from the lifecycle check,
 * and how afterException() answers each kind of exception.
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
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Controller\SettingsController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Middleware\MandateDeniedException;
use OCA\Dossiq\Middleware\MandateValidationMiddleware;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantService;
use OCA\Dossiq\Tests\Support\MakesActiveOrganisationContext;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Middleware\MandateValidationMiddleware
 *
 * @uses \OCA\Dossiq\Middleware\MandateDeniedException
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\TenantContext
 * @uses \OCA\Dossiq\Service\TenantSessionService
 * @uses \OCA\Dossiq\Service\TenantOrganisationResolver
 * @uses \OCA\Dossiq\Service\OrganisationLookup
 */
class MandateValidationMiddlewareEdgeTest extends TestCase {
	use MakesActiveOrganisationContext;

	/**
	 * The matrix double.
	 *
	 * @var TenantAuthenticationService&MockObject
	 */
	private TenantAuthenticationService&MockObject $auth;

	/**
	 * The audit writer double.
	 *
	 * @var TenantAuditTrailService&MockObject
	 */
	private TenantAuditTrailService&MockObject $audit;

	/**
	 * Build the middleware for alice, a member of org-a stored with $status.
	 *
	 * @param string $verb   The HTTP verb.
	 * @param string $uri    The request URI.
	 * @param string $status The organisation's stored status.
	 *
	 * @return MandateValidationMiddleware The middleware.
	 */
	private function middleware(string $verb = 'POST', string $uri = '/api/cases', string $status = 'active'): MandateValidationMiddleware {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getMethod')->willReturn($verb);
		$request->method('getRequestUri')->willReturn($uri);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->auth  = $this->createMock(originalClassName: TenantAuthenticationService::class);
		$this->audit = $this->createMock(originalClassName: TenantAuditTrailService::class);

		return new MandateValidationMiddleware(
			request: $request,
			userSession: $session,
			context: $this->context(status: $status),
			authService: $this->auth,
			tenantService: $this->createMock(originalClassName: TenantService::class),
			auditTrail: $this->audit,
		);
	}//end middleware()

	/**
	 * A context bound to org-a, stored with $status.
	 *
	 * @param string $status The stored status.
	 *
	 * @return TenantContext The context.
	 */
	private function context(string $status): TenantContext {
		return $this->activeOrganisationContext(
			active: 'org-a',
			stored: ['org-a' => $this->organisationRow(uuid: 'org-a', status: $status)],
			memberships: ['org-a'],
		);
	}//end context()

	/**
	 * An allowed decision lets the request through and writes one audit row
	 * naming the action, the outcome, the caller, the URI with the reason and
	 * the tenant.
	 *
	 * @return void
	 */
	public function testAnAllowedDecisionWritesItsAuditRow(): void {
		$middleware = $this->middleware(verb: 'patch', uri: '/api/cases/7');
		$this->auth->expects($this->once())
			->method('validateMandateMatrix')
			->with('org-a', 'alice', 'edit')
			->willReturn(['allowed' => true, 'reason' => 'Authorised by mandate matrix']);
		$this->audit->expects($this->once())
			->method('emit')
			->with(
				[
					'action' => 'mandate.edit.allowed',
					'actor' => 'alice',
					'resource' => '/api/cases/7 (Authorised by mandate matrix)',
					'tenantId' => 'org-a',
				]
			)
			->willReturn([]);

		$middleware->beforeController(new \stdClass(), 'update');
	}//end testAnAllowedDecisionWritesItsAuditRow()

	/**
	 * A denied decision writes its audit row before refusing, and the refusal
	 * carries the matrix's reason and 403.
	 *
	 * @return void
	 */
	public function testADeniedDecisionIsAuditedThenRefusedWithItsReason(): void {
		$middleware = $this->middleware(verb: 'DELETE', uri: '/api/cases/7');
		$this->auth->method('validateMandateMatrix')->willReturn(['allowed' => false, 'reason' => 'Role viewer may not delete']);
		$this->audit->expects($this->once())
			->method('emit')
			->with(
				[
					'action' => 'mandate.delete.denied',
					'actor' => 'alice',
					'resource' => '/api/cases/7 (Role viewer may not delete)',
					'tenantId' => 'org-a',
				]
			)
			->willReturn([]);

		$this->expectException(exception: MandateDeniedException::class);
		$this->expectExceptionMessage(message: 'Role viewer may not delete');
		$this->expectExceptionCode(code: 403);
		$middleware->beforeController(new \stdClass(), 'destroy');
	}//end testADeniedDecisionIsAuditedThenRefusedWithItsReason()

	/**
	 * A status path asks the matrix about `status_update`, whatever the verb.
	 *
	 * @return void
	 */
	public function testAStatusPathAsksAboutStatusUpdate(): void {
		$middleware = $this->middleware(verb: 'PUT', uri: '/api/cases/7/status');
		$this->auth->expects($this->once())
			->method('validateMandateMatrix')
			->with('org-a', 'alice', 'status_update')
			->willReturn(['allowed' => true, 'reason' => 'ok']);
		$this->audit->expects($this->once())
			->method('emit')
			->with($this->callback(callback: static fn (array $row): bool => $row['action'] === 'mandate.status_update.allowed'))
			->willReturn([]);

		$middleware->beforeController(new \stdClass(), 'status');
	}//end testAStatusPathAsksAboutStatusUpdate()

	/**
	 * A read on an active organisation asks the matrix nothing and writes no row.
	 *
	 * @return void
	 */
	public function testAReadOnAnActiveOrganisationIsNeitherCheckedNorAudited(): void {
		$middleware = $this->middleware(verb: 'GET', uri: '/api/cases');
		$this->auth->expects($this->never())->method('validateMandateMatrix');
		$this->audit->expects($this->never())->method('emit');

		$middleware->beforeController(new \stdClass(), 'index');
	}//end testAReadOnAnActiveOrganisationIsNeitherCheckedNorAudited()

	/**
	 * Refusing a suspended organisation writes its own audit row, and the
	 * matrix is never asked.
	 *
	 * @return void
	 */
	public function testALifecycleRefusalIsAudited(): void {
		$middleware = $this->middleware(verb: 'GET', uri: '/api/cases', status: 'suspended');
		$this->auth->expects($this->never())->method('validateMandateMatrix');
		$this->audit->expects($this->once())
			->method('emit')
			->with(
				[
					'action' => 'organisation.refused.suspended',
					'actor' => 'alice',
					'resource' => '/api/cases',
					'tenantId' => 'org-a',
				]
			)
			->willReturn([]);

		$this->expectException(exception: MandateDeniedException::class);
		$middleware->beforeController(new \stdClass(), 'index');
	}//end testALifecycleRefusalIsAudited()

	/**
	 * The settings controller stays reachable for a suspended organisation:
	 * no lifecycle refusal, and its writes still go through the matrix.
	 *
	 * @return void
	 */
	public function testAnExemptControllerSkipsTheLifecycleCheckButNotTheMatrix(): void {
		$middleware = $this->middleware(verb: 'POST', uri: '/api/settings', status: 'suspended');
		$this->auth->expects($this->once())
			->method('validateMandateMatrix')
			->with('org-a', 'alice', 'create')
			->willReturn(['allowed' => true, 'reason' => 'ok']);
		$this->audit->expects($this->once())
			->method('emit')
			->with($this->callback(callback: static fn (array $row): bool => $row['action'] === 'mandate.create.allowed'))
			->willReturn([]);

		$controller = (new ReflectionClass(objectOrClass: SettingsController::class))->newInstanceWithoutConstructor();
		$middleware->beforeController($controller, 'create');
	}//end testAnExemptControllerSkipsTheLifecycleCheckButNotTheMatrix()

	/**
	 * A mandate denial without a lifecycle status answers 403 without a
	 * `status` member.
	 *
	 * @return void
	 */
	public function testADenialAnswersWithoutAStatusMember(): void {
		$response = $this->middleware()->afterException(
			new \stdClass(),
			'create',
			new MandateDeniedException(message: 'Role viewer may not create', code: 403)
		);

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['success' => false, 'error' => 'Role viewer may not create'], actual: $response->getData());
	}//end testADenialAnswersWithoutAStatusMember()

	/**
	 * A mandate check that could not run answers with the refusal's own
	 * status, sentence, rule and code, not with a 403 denial.
	 *
	 * @return void
	 */
	public function testACheckThatCouldNotRunAnswersWithTheRefusalsStatus(): void {
		$refusal = RefusedException::indeterminate(
			rule: 'mandate-matrix-unavailable',
			sentence: 'The mandate matrix could not be read.'
		);

		$response = $this->middleware()->afterException(new \stdClass(), 'create', $refusal);

		$this->assertSame(expected: 503, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'success' => false,
				'message' => 'The mandate matrix could not be read.',
				'error' => 'mandate-matrix-unavailable',
				'code' => 'mandate_matrix_unavailable',
			],
			actual: $response->getData()
		);
	}//end testACheckThatCouldNotRunAnswersWithTheRefusalsStatus()

	/**
	 * Any other exception is not this middleware's to answer: it is rethrown as is.
	 *
	 * @return void
	 */
	public function testAnUnrelatedExceptionIsRethrown(): void {
		$failure = new RuntimeException('database gone');

		try {
			$this->middleware()->afterException(new \stdClass(), 'create', $failure);
			$this->fail(message: 'an unrelated exception must be rethrown');
		} catch (RuntimeException $thrown) {
			$this->assertSame(expected: $failure, actual: $thrown);
		}
	}//end testAnUnrelatedExceptionIsRethrown()
}//end class
