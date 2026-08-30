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
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\TenantController;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantService;
use OCA\Dossiq\Service\TenantSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for TenantController::current().
 *
 * @covers \OCA\Dossiq\Controller\TenantController
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
	/**
	 * The session tenant service.
	 *
	 * @var TenantSessionService&MockObject
	 */
	private TenantSessionService&MockObject $tenantSession;

	/**
	 * The membership lookup.
	 *
	 * @var TenantAuthenticationService&MockObject
	 */
	private TenantAuthenticationService&MockObject $tenantAuthentication;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->tenantService = $this->createMock(TenantService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->tenantSession = $this->createMock(TenantSessionService::class);
		$this->tenantAuthentication = $this->createMock(TenantAuthenticationService::class);

		$this->controller = new TenantController(
			request: $this->request,
			tenantService: $this->tenantService,
			userSession: $this->userSession,
			tenantSession: $this->tenantSession,
			tenantAuthentication: $this->tenantAuthentication,
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

	/**
	 * An unauthenticated caller cannot switch tenant.
	 *
	 * @return void
	 */
	public function testSwitchRefusesAnUnauthenticatedCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->tenantSession->expects($this->never())->method('switchTo');

		$response = $this->controller->switchTenant('tenant-a');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testSwitchRefusesAnUnauthenticatedCaller()

	/**
	 * A refused switch is a 403 that does not say whether the tenant exists.
	 *
	 * Distinguishing "no such tenant" from "not yours" would let an outsider
	 * enumerate the tenant list one guess at a time, which is a disclosure the
	 * endpoint has no reason to make.
	 *
	 * @return void
	 */
	public function testARefusedSwitchDoesNotRevealWhetherTheTenantExists(): void {
		$this->signIn('alice');
		$this->tenantSession->method('switchTo')->willReturn(false);

		$response = $this->controller->switchTenant('tenant-b');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringNotContainsStringIgnoringCase('exist', (string)$response->getData()['error']);
		$this->assertStringNotContainsStringIgnoringCase('found', (string)$response->getData()['error']);
	}//end testARefusedSwitchDoesNotRevealWhetherTheTenantExists()

	/**
	 * A permitted switch reports the tenant that is now active.
	 *
	 * The active tenant is read back from the service rather than echoed from
	 * the request: echoing would report success for a switch that did not
	 * actually take, which is the one failure a caller cannot detect.
	 *
	 * @return void
	 */
	public function testAPermittedSwitchReportsTheNewActiveTenant(): void {
		$this->signIn('alice');
		$this->tenantSession->method('switchTo')->with('tenant-b')->willReturn(true);
		$this->tenantSession->method('activeTenantId')->willReturn('tenant-b');

		$response = $this->controller->switchTenant('tenant-b');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('tenant-b', $response->getData()['active']);
	}//end testAPermittedSwitchReportsTheNewActiveTenant()

	/**
	 * A failed membership lookup is an error, not an empty list.
	 *
	 * Rendering the failure as `tenants: []` would tell the user they belong to
	 * nothing when the truth is that we could not find out — and they would
	 * have no way to tell the two apart.
	 *
	 * @return void
	 */
	public function testAFailedMembershipLookupIsNotReportedAsNoMemberships(): void {
		$this->signIn('alice');
		$this->tenantAuthentication->method('listTenantsForUser')
			->willThrowException(new \RuntimeException('backend down'));

		$response = $this->controller->memberships();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertArrayNotHasKey('tenants', $response->getData());
	}//end testAFailedMembershipLookupIsNotReportedAsNoMemberships()

	/**
	 * Memberships are resolved for the SESSION uid, never from input.
	 *
	 * @return void
	 */
	public function testMembershipsResolveFromTheSessionUid(): void {
		$this->signIn('alice');
		$this->tenantAuthentication->expects($this->once())
			->method('listTenantsForUser')
			->with('alice')
			->willReturn(['tenant-a', 'tenant-b']);
		$this->tenantSession->method('activeTenantId')->willReturn(null);

		$response = $this->controller->memberships();

		$this->assertSame(['tenant-a', 'tenant-b'], $response->getData()['tenants']);
		$this->assertNull($response->getData()['active']);
	}//end testMembershipsResolveFromTheSessionUid()

}//end class
