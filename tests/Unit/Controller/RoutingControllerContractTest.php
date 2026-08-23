<?php

/**
 * RoutingController Wire-Contract Tests
 *
 * Contract coverage for `POST /api/cases/{id}/reroute` (gate-25). Rerouting
 * recomputes the assignee of EVERY open step on a case, so it is the one
 * routing operation restricted to server admins. Notably the method carries
 * NO Nextcloud auth attribute at all — only a prose `@auth` docblock — which
 * means the admin rule is enforced NOWHERE except in the method body. These
 * tests pin that body:
 *
 *  - 401 without a session;
 *  - 403 for an authenticated NON-admin, with the admin check asserted to run
 *    against the SESSION uid — a check run against a request-supplied id would
 *    let any user claim admin;
 *  - 503 (not 500, and not an empty success) when OpenRegister is absent, so
 *    a client can tell "nothing to reroute" from "the object store is down";
 *  - a case with no workflow template yields an EMPTY affectedSteps list with
 *    the case id echoed — an honest "nothing was reassigned" rather than an
 *    error;
 *  - an unexpected failure is a 500 with a generic message that does not leak
 *    the internal exception text.
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

use OCA\Dossiq\Controller\RoutingController;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService surface used by RoutingController::reroute().
 *
 * OpenRegister's ObjectService is resolved through SettingsService as an
 * untyped `mixed`, so the test supplies a double with the one method the
 * controller calls, using the real named-parameter signature. The name is
 * prefixed with the controller name because sibling contract suites declare
 * their own doubles in this namespace.
 */
interface RoutingControllerContractObjectService {
	/**
	 * Find a single object.
	 *
	 * @param int|string $id The object id.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return mixed The object.
	 */
	public function find(int|string $id, string $register = '', string $schema = ''): mixed;
}//end interface

/**
 * Wire-contract tests for RoutingController::reroute().
 *
 * @covers \OCA\Dossiq\Controller\RoutingController
 */
class RoutingControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The role resolver.
	 *
	 * @var RoleResolverService|MockObject
	 */
	private RoleResolverService $resolver;

	/**
	 * The settings bridge to OpenRegister.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var RoutingController
	 */
	private RoutingController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->resolver = $this->createMock(RoleResolverService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new RoutingController(
			appName: 'dossiq',
			request: $this->request,
			resolver: $this->resolver,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
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
	 * A session-less caller is refused 401 and no object store is touched.
	 *
	 * @return void
	 */
	public function testRerouteRefusesASessionLessCallerBeforeTouchingTheObjectStore(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getObjectService');

		$response = $this->controller->reroute(id: 'zaak-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}//end testRerouteRefusesASessionLessCallerBeforeTouchingTheObjectStore()

	/**
	 * A non-admin is refused 403, and the admin check is run against the
	 * SESSION uid rather than anything the caller supplied.
	 *
	 * @return void
	 */
	public function testRerouteRefusesANonAdminAndChecksAdminAgainstTheSessionUid(): void {
		$this->signIn('gewone-behandelaar');

		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('gewone-behandelaar')
			->willReturn(false);

		$this->settingsService->expects($this->never())->method('getObjectService');

		$response = $this->controller->reroute(id: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Admin-rechten op de zaak vereist'], $response->getData());
	}//end testRerouteRefusesANonAdminAndChecksAdminAgainstTheSessionUid()

	/**
	 * With OpenRegister absent the endpoint answers 503, not an empty success.
	 *
	 * @return void
	 */
	public function testRerouteReturns503WhenOpenRegisterIsUnavailableRatherThanAnEmptySuccess(): void {
		$this->signIn('beheerder');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->reroute(id: 'zaak-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'OpenRegister is niet beschikbaar'], $response->getData());
	}//end testRerouteReturns503WhenOpenRegisterIsUnavailableRatherThanAnEmptySuccess()

	/**
	 * A case without a workflow template reports an honest empty result: the
	 * case id echoed and no affected steps — no rule is normalised or
	 * resolved, because there are no steps to walk.
	 *
	 * @return void
	 */
	public function testRerouteReportsNoAffectedStepsForACaseWithoutAWorkflowTemplate(): void {
		$this->signIn('beheerder');
		$this->groupManager->method('isAdmin')->willReturn(true);

		$objectService = $this->createMock(RoutingControllerContractObjectService::class);
		$objectService->expects($this->once())
			->method('find')
			->with('zaak-1', 'dossiq', 'zaak')
			->willReturn(['id' => 'zaak-1', 'workflowTemplate' => '']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'zaak',
					'workflow_template_schema' => 'workflowtemplate',
					default => '',
				};
			}
		);

		$this->resolver->expects($this->never())->method('normaliseRule');
		$this->resolver->expects($this->never())->method('resolve');

		$response = $this->controller->reroute(id: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['caseId' => 'zaak-1', 'affectedSteps' => []], $response->getData());
	}//end testRerouteReportsNoAffectedStepsForACaseWithoutAWorkflowTemplate()

	/**
	 * An unexpected failure is a generic 500 that does not leak the internal
	 * exception text to the caller.
	 *
	 * @return void
	 */
	public function testRerouteReturnsAGeneric500WithoutLeakingTheInternalFailure(): void {
		$this->signIn('beheerder');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->settingsService->method('getObjectService')
			->willThrowException(new \RuntimeException('PDOException: SQLSTATE[HY000] at 10.0.0.5:3306'));

		$response = $this->controller->reroute(id: 'zaak-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Herberekening mislukt'], $response->getData());
		$this->assertStringNotContainsString('10.0.0.5', json_encode($response->getData()));
	}//end testRerouteReturnsAGeneric500WithoutLeakingTheInternalFailure()
}//end class
