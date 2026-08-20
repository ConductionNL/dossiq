<?php

/**
 * TenantController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/tenants/current` (gate-25). This is the only
 * `@NoAdminRequired` endpoint on a controller whose two siblings are
 * admin-gated, and it is the endpoint every page load consults to learn which
 * tenant's data it may show. These tests pin:
 *
 *  - 401 without a session, before any tenant lookup runs;
 *  - the tenant is resolved from the SESSION UID and nothing else — there is
 *    no request parameter that can steer the lookup at another tenant, and
 *    that absence is what the `->with()` on the session UID proves;
 *  - "no tenant assigned" is a 200 with `tenant: null`, NOT a 404. The
 *    frontend treats a 404 as an error banner, so collapsing the two would
 *    break every unassigned account.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\TenantController;
use OCA\Procest\Service\TenantService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for TenantController::current().
 *
 * @covers \OCA\Procest\Controller\TenantController
 */
class TenantControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The tenant service.
	 *
	 * @var TenantService|MockObject
	 */
	private TenantService $tenantService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var TenantController
	 */
	private TenantController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->tenantService = $this->createMock(TenantService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new TenantController(
			request: $this->request,
			tenantService: $this->tenantService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put a signed-in user with the given UID on the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An unauthenticated caller gets 401 and no tenant is resolved.
	 *
	 * @return void
	 */
	public function testCurrentRefusesAnUnauthenticatedCallerBeforeResolvingATenant(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->tenantService->expects($this->never())->method('getTenantForUser');

		$response = $this->controller->current();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authenticated'], $response->getData());
	}//end testCurrentRefusesAnUnauthenticatedCallerBeforeResolvingATenant()

	/**
	 * The lookup runs on the session UID, and the tenant is echoed back.
	 *
	 * @return void
	 */
	public function testCurrentResolvesTheTenantFromTheSessionUidAndNotFromInput(): void {
		$this->signIn('bob');
		$tenant = ['id' => 'tenant-7', 'naam' => 'Gemeente Voorbeeld'];

		$this->tenantService->expects($this->once())
			->method('getTenantForUser')
			->with('bob')
			->willReturn($tenant);

		$response = $this->controller->current();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'tenant' => $tenant], $response->getData());
	}//end testCurrentResolvesTheTenantFromTheSessionUidAndNotFromInput()

	/**
	 * An account with no tenant is a successful 200 carrying a null tenant,
	 * not a 404.
	 *
	 * @return void
	 */
	public function testCurrentReportsAnUnassignedAccountAsASuccessfulNullTenant(): void {
		$this->signIn('carol');
		$this->tenantService->method('getTenantForUser')->willReturn(null);

		$response = $this->controller->current();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'tenant' => null, 'message' => 'No tenant assigned'],
			$response->getData()
		);
	}//end testCurrentReportsAnUnassignedAccountAsASuccessfulNullTenant()
}//end class
