<?php

/**
 * AdvisoryBodyController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/advisory-bodies/search` (gate-25). The
 * endpoint is `@NoAdminRequired`, so Nextcloud's middleware only guarantees
 * *some* session exists; the controller's own `getUser() === null` branch is
 * what turns "no session" into a 401 instead of an unauthenticated directory
 * dump. These tests pin:
 *
 *  - the refusal happens BEFORE AdvisoryBodyService is consulted;
 *  - the `q` query parameter is forwarded verbatim as the service's `query`
 *    argument — searching on the wrong parameter name silently returns the
 *    unfiltered directory, which looks like a working search;
 *  - the payload is nested under `results`, the shape the frontend reads.
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

use OCA\Dossiq\Controller\AdvisoryBodyController;
use OCA\Dossiq\Service\AdvisoryBodyService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for AdvisoryBodyController.
 *
 * @covers \OCA\Dossiq\Controller\AdvisoryBodyController
 */
class AdvisoryBodyControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The advisory body directory service.
	 *
	 * @var AdvisoryBodyService|MockObject
	 */
	private AdvisoryBodyService $advisoryBodyService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var AdvisoryBodyController
	 */
	private AdvisoryBodyController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->advisoryBodyService = $this->createMock(AdvisoryBodyService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new AdvisoryBodyController(
			appName: 'dossiq',
			request: $this->request,
			advisoryBodyService: $this->advisoryBodyService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Without a session the endpoint answers 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testSearchRefusesAnUnauthenticatedCallerBeforeQueryingTheDirectory(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->advisoryBodyService->expects($this->never())->method('searchBySpecialization');

		$response = $this->controller->searchAdvisoryBodies();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testSearchRefusesAnUnauthenticatedCallerBeforeQueryingTheDirectory()

	/**
	 * The `q` query parameter is what the search is run on.
	 *
	 * A search wired to the wrong parameter name returns the whole directory
	 * and still renders — so the parameter name is asserted, not just that a
	 * search happened.
	 *
	 * @return void
	 */
	public function testSearchForwardsTheQQueryParameterAsTheSpecializationQuery(): void {
		$this->signIn();
		$bodies = [['id' => 'ab-1', 'naam' => 'Welstandscommissie']];

		$this->request->expects($this->once())
			->method('getParam')
			->with('q')
			->willReturn('welstand');

		$this->advisoryBodyService->expects($this->once())
			->method('searchBySpecialization')
			->with('welstand')
			->willReturn($bodies);

		$response = $this->controller->searchAdvisoryBodies();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $bodies], $response->getData());
	}//end testSearchForwardsTheQQueryParameterAsTheSpecializationQuery()

	/**
	 * An absent `q` is coerced to the empty string, never passed as null.
	 *
	 * AdvisoryBodyService::searchBySpecialization() declares `string $query`,
	 * so forwarding a null would be a TypeError (HTTP 500) on the bare
	 * `/api/advisory-bodies/search` call the UI makes on first paint.
	 *
	 * @return void
	 */
	public function testSearchCoercesAnAbsentQueryToAnEmptyStringRatherThanNull(): void {
		$this->signIn();

		$this->request->method('getParam')->willReturn(null);

		$this->advisoryBodyService->expects($this->once())
			->method('searchBySpecialization')
			->with($this->identicalTo(''))
			->willReturn([]);

		$response = $this->controller->searchAdvisoryBodies();

		$this->assertSame(['results' => []], $response->getData());
	}//end testSearchCoercesAnAbsentQueryToAnEmptyStringRatherThanNull()
}//end class
