<?php

/**
 * DeelzaakController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for `parent()` and `counts()` — the two sub-case
 * endpoints with no executed proof of their wire behaviour. Both are
 * `@NoAdminRequired`, so any authenticated user reaches them, and what separates
 * them is the authorization they apply. These tests pin:
 *
 *  - `parent()` refuses an anonymous caller 401 and a caller without read
 *    access to the named CHILD case 403 — in both cases without asking the
 *    service for the parent, because the parent object IS the answer;
 *  - `parent()` guards on the caseId the CALLER named, not on the parent it
 *    resolves to (the controller documents this as deliberate; a guard on the
 *    resolved parent would be circular);
 *  - a child with no parent is 404 `not_found`, distinct from the 403 above —
 *    collapsing the two would turn the endpoint into a hierarchy oracle;
 *  - `counts()` refuses an anonymous caller 401, requires a non-empty `ids`
 *    parameter (400), and accepts it both as a comma-separated string and as an
 *    array, trimming and dropping blanks — a trailing comma must not become a
 *    lookup for the empty uuid;
 *  - and one TRIPWIRE (see its docblock): `counts()` applies NO per-case guard,
 *    unlike every one of its siblings in this controller.
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

use OCA\Procest\Controller\DeelzaakController;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\DeelzaakService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for DeelzaakController::parent() and ::counts().
 *
 * @covers \OCA\Procest\Controller\DeelzaakController
 */
class DeelzaakControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The sub-case service mock.
	 *
	 * @var DeelzaakService|MockObject
	 */
	private DeelzaakService $deelzaakService;

	/**
	 * The user session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The per-case authorization guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var DeelzaakController
	 */
	private DeelzaakController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->deelzaakService = $this->createMock(DeelzaakService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new DeelzaakController(
			request: $this->request,
			deelzaakService: $this->deelzaakService,
			userSession: $this->userSession,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Put an authenticated user in the session.
	 *
	 * @param string $uid The user id the session reports.
	 *
	 * @return IUser|MockObject The signed-in user.
	 */
	private function signIn(string $uid = 'handler-1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Serve the given request parameters, defaulting like the real request.
	 *
	 * @param array<string, mixed> $overrides The parameter values to serve.
	 *
	 * @return void
	 */
	private function withRequestParams(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				return ($overrides[$key] ?? $default);
			}
		);
	}//end withRequestParams()

	/**
	 * Both endpoints refuse an anonymous caller with 401 and read nothing.
	 *
	 * @return void
	 */
	public function testBothEndpointsRefuseAnAnonymousCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->deelzaakService->expects($this->never())->method('getParentCase');
		$this->deelzaakService->expects($this->never())->method('getSubCaseCounts');

		$parent = $this->controller->parent(caseId: 'case-1');
		$counts = $this->controller->counts();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $parent->getStatus());
		$this->assertSame(['message' => 'unauthenticated'], $parent->getData());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $counts->getStatus());
		$this->assertSame(['message' => 'unauthenticated'], $counts->getData());
	}//end testBothEndpointsRefuseAnAnonymousCallerWith401()

	/**
	 * `parent()` refuses 403 when the caller has no read access to the CHILD
	 * case it named, and never resolves the parent — the parent object would
	 * itself be the leak.
	 *
	 * @return void
	 */
	public function testParentRefusesACallerWithoutReadAccessToTheNamedChildCase(): void {
		$this->signIn(uid: 'handler-1');
		$this->deelzaakService->expects($this->never())->method('getParentCase');

		$seen = [];
		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->willReturnCallback(
				static function (string $caseId, IUser $user) use (&$seen): bool {
					$seen = ['caseId' => $caseId, 'uid' => $user->getUID()];
					return false;
				}
			);

		$response = $this->controller->parent(caseId: 'child-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'forbidden'], $response->getData());
		$this->assertSame(
			['caseId' => 'child-9', 'uid' => 'handler-1'],
			$seen,
			'the guard must be asked about the CHILD case the caller named, for the calling user'
		);
	}//end testParentRefusesACallerWithoutReadAccessToTheNamedChildCase()

	/**
	 * A cleared caller gets the parent case resolved from the CHILD uuid, and a
	 * child with no parent is a 404 rather than an empty 200 body a client
	 * cannot distinguish from a parent with no fields.
	 *
	 * @return void
	 */
	public function testParentResolvesTheParentFromTheChildUuidAndAnswers404WhenThereIsNone(): void {
		$this->signIn(uid: 'handler-1');
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$asked = [];
		$this->deelzaakService->expects($this->exactly(2))
			->method('getParentCase')
			->willReturnCallback(
				static function (string $childCaseUuid) use (&$asked): ?array {
					$asked[] = $childCaseUuid;
					if ($childCaseUuid === 'orphan-1') {
						return null;
					}

					return ['id' => 'parent-1', 'identificatie' => 'ZAAK-2026-0001'];
				}
			);

		$found = $this->controller->parent(caseId: 'child-9');
		$missing = $this->controller->parent(caseId: 'orphan-1');

		$this->assertSame(Http::STATUS_OK, $found->getStatus());
		$this->assertSame(['id' => 'parent-1', 'identificatie' => 'ZAAK-2026-0001'], $found->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'not_found'], $missing->getData());
		$this->assertSame(['child-9', 'orphan-1'], $asked, 'the CHILD uuid is what gets resolved');
	}//end testParentResolvesTheParentFromTheChildUuidAndAnswers404WhenThereIsNone()

	/**
	 * `counts()` demands a non-empty `ids` parameter: a missing one, and one
	 * that reduces to nothing after trimming, are both 400 — never a batch
	 * lookup for the empty uuid.
	 *
	 * @return void
	 */
	public function testCountsRefusesAnIdsParameterThatReducesToNothing(): void {
		$this->signIn();
		$this->withRequestParams(['ids' => ' , , ']);
		$this->deelzaakService->expects($this->never())->method('getSubCaseCounts');

		$response = $this->controller->counts();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'ids parameter is required'], $response->getData());
	}//end testCountsRefusesAnIdsParameterThatReducesToNothing()

	/**
	 * A comma-separated `ids` string is split, trimmed and stripped of blanks
	 * before it reaches the service, and the counts are returned under `counts`.
	 *
	 * @return void
	 */
	public function testCountsSplitsTrimsAndCompactsACommaSeparatedIdsString(): void {
		$this->signIn();
		$this->withRequestParams(['ids' => ' case-1 , case-2 ,, case-3,']);

		$seen = [];
		$this->deelzaakService->expects($this->once())
			->method('getSubCaseCounts')
			->willReturnCallback(
				static function (array $parentUuids) use (&$seen): array {
					$seen = $parentUuids;
					return ['case-1' => 2, 'case-2' => 0, 'case-3' => 1];
				}
			);

		$response = $this->controller->counts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['case-1', 'case-2', 'case-3'], $seen);
		$this->assertSame(['counts' => ['case-1' => 2, 'case-2' => 0, 'case-3' => 1]], $response->getData());
	}//end testCountsSplitsTrimsAndCompactsACommaSeparatedIdsString()

	/**
	 * TRIPWIRE — pins CURRENT behaviour, which is NOT asserted to be correct.
	 *
	 * `counts()` applies no per-case authorization at all: it checks only that
	 * SOMEONE is signed in, then answers with the sub-case count of every uuid
	 * the caller cares to name. Every one of its siblings on this controller —
	 * `list()`, `parent()`, `validate()`, `unlink()` — calls
	 * `CaseAccessGuard::hasCaseReadAccess()` (or the mutation equivalent) first,
	 * and `validate()` carries an explicit comment about not becoming an
	 * "existence oracle over every case on the instance". `counts()` is exactly
	 * that oracle at a lower resolution: a count of 0 for an unknown uuid and a
	 * count of N for a real one are distinguishable, so any authenticated user
	 * can confirm a case exists and learn how many sub-cases hang off it.
	 *
	 * The test asserts the guard is NEVER consulted. If it goes RED, a guard was
	 * ADDED — that is the fix, not a regression: update this test to assert the
	 * new 403 branch and delete the tripwire. Do not weaken the controller to
	 * make it pass again.
	 *
	 * @return void
	 */
	public function testTripwireCountsAnswersForArbitraryCaseUuidsWithoutAnyPerCaseGuard(): void {
		$this->signIn(uid: 'unrelated-user');
		$this->withRequestParams(['ids' => 'someone-elses-case,another-tenants-case']);

		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');
		$this->deelzaakService->method('getSubCaseCounts')->willReturn(
			['someone-elses-case' => 4, 'another-tenants-case' => 0]
		);

		$response = $this->controller->counts();

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'current behaviour: an unrelated user is served, no per-case guard exists'
		);
		$this->assertSame(
			['counts' => ['someone-elses-case' => 4, 'another-tenants-case' => 0]],
			$response->getData()
		);
	}//end testTripwireCountsAnswersForArbitraryCaseUuidsWithoutAnyPerCaseGuard()
}//end class
