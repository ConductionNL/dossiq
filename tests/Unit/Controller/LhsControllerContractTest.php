<?php

/**
 * LhsController Wire-Contract Tests
 *
 * Contract coverage for the two LHS (Landelijke Handhavingsstrategie) engine
 * actions (gate-25). Both are `@NoAdminRequired` and both write an enforcement
 * record: `recommend` PERSISTS an `lhsRecommendation` — a sanction proposal
 * against a named case — and `override` rewrites one.
 *
 * The contract pinned here:
 *
 *  - no session answers 401 and the engine is never entered;
 *  - `recommend` reads the English wire keys `severity` / `behaviour` (the
 *    Dutch words survive only in the error text), and refuses an incomplete
 *    quadruple with 400 before touching the case guard;
 *  - `recommend` is guarded as a MUTATION on the named case even though it
 *    reads like a query — it persists a sanction record, so a read guard here
 *    would be wrong;
 *  - a blank `inspection` is normalised to null rather than persisted as an
 *    empty relation, and `lhsVersion` is coerced to an int;
 *  - the engine's own refusals map to 422, an unexpected failure to a masked
 *    500 — two different meanings that must not collapse into one;
 *  - and the one that matters most on `override`: the caller's DECLARED
 *    `userRole` is only honoured when the Nextcloud admin check agrees. A
 *    client that simply posts `userRole=manager` must still be treated as an
 *    inspector, otherwise anyone can escalate an enforcement measure
 *    (`Verzwaring vereist managerrol` → 403).
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

use OCA\Dossiq\Controller\LhsController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\LhsLookupService;
use OCA\Dossiq\Service\Vth\LhsRecommendationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for LhsController.
 *
 * @covers \OCA\Dossiq\Controller\LhsController
 */
class LhsControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The LHS engine mock.
	 *
	 * @var LhsRecommendationService|MockObject
	 */
	private LhsRecommendationService $lhsService;

	/**
	 * The LHS lookup service mock.
	 *
	 * @var LhsLookupService|MockObject
	 */
	private LhsLookupService $lhsLookupService;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The IGroupManager mock (manager-role detection).
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The per-case authorization guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var LhsController
	 */
	private LhsController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->lhsService = $this->createMock(LhsRecommendationService::class);
		$this->lhsLookupService = $this->createMock(LhsLookupService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new LhsController(
			appName: 'dossiq',
			request: $this->request,
			lhsService: $this->lhsService,
			lhsLookupService: $this->lhsLookupService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return IUser|MockObject The user placed on the session.
	 */
	private function signIn(string $uid = 'inspector1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Answer request parameters from the supplied map.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	/**
	 * A complete, valid recommend payload.
	 *
	 * @return array<string, mixed> The parameter map.
	 */
	private function validRecommendPayload(): array {
		return [
			'caseId' => 'zaak-1',
			'severity' => 'aanzienlijk',
			'behaviour' => 'onverschillig',
			'actorType' => 'bedrijf',
		];
	}//end validRecommendPayload()

	/**
	 * `recommend` refuses an anonymous caller with 401 and persists nothing.
	 *
	 * @return void
	 */
	public function testRecommendRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->lhsService->expects($this->never())->method('recommend');

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}//end testRecommendRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An incomplete quadruple is a 400, checked before the case guard — a guard
	 * asked about an empty caseId cannot decide anything.
	 *
	 * @return void
	 */
	public function testRecommendRejectsAnIncompleteQuadrupleWith400BeforeGuarding(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'zaak-1', 'severity' => 'aanzienlijk']);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');
		$this->lhsService->expects($this->never())->method('recommend');

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'caseId, ernst, gedrag en actorType zijn verplicht'],
			$response->getData()
		);
	}//end testRecommendRejectsAnIncompleteQuadrupleWith400BeforeGuarding()

	/**
	 * The wire keys are the ENGLISH `severity` / `behaviour`. A payload using
	 * only the Dutch words from the error message is incomplete and answers 400
	 * — this pins which key names a client must actually send.
	 *
	 * @return void
	 */
	public function testRecommendReadsTheEnglishSeverityAndBehaviourWireKeys(): void {
		$this->signIn();
		$this->withParams([
			'caseId' => 'zaak-1',
			'ernst' => 'aanzienlijk',
			'gedrag' => 'onverschillig',
			'actorType' => 'bedrijf',
		]);
		$this->lhsService->expects($this->never())->method('recommend');

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRecommendReadsTheEnglishSeverityAndBehaviourWireKeys()

	/**
	 * `recommend` is guarded as a MUTATION on the named case: it persists an
	 * enforcement-sanction record, so a caller without mutation access is
	 * refused 403 and the engine never runs.
	 *
	 * @return void
	 */
	public function testRecommendDemandsCaseMutationAccessAndRefusesWith403(): void {
		$user = $this->signIn(uid: 'mallory');
		$this->withParams($this->validRecommendPayload());

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseMutationAccess')
			->with('zaak-1', $user)
			->willReturn(false);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->lhsService->expects($this->never())->method('recommend');

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testRecommendDemandsCaseMutationAccessAndRefusesWith403()

	/**
	 * An authorized recommend forwards the quadruple, coerces `lhsVersion` to an
	 * int and normalises a blank `inspection` to null rather than persisting an
	 * empty relation.
	 *
	 * @return void
	 */
	public function testRecommendForwardsTheQuadrupleAndNormalisesOptionalArguments(): void {
		$this->signIn();
		$this->withParams(
			array_merge(
				$this->validRecommendPayload(),
				['lhsVersion' => '3', 'inspection' => '']
			)
		);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);

		$captured = [];
		$this->lhsService->expects($this->once())
			->method('recommend')
			->willReturnCallback(
				static function (
					string $caseId,
					string $severity,
					string $behaviour,
					string $actorType,
					?int $lhsVersion = null,
					?string $inspection = null,
				) use (&$captured): array {
					$captured = [
						'caseId' => $caseId,
						'severity' => $severity,
						'behaviour' => $behaviour,
						'actorType' => $actorType,
						'lhsVersion' => $lhsVersion,
						'inspection' => $inspection,
					];

					return ['id' => 'rec-1', 'intervention' => 'waarschuwing'];
				}
			);

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'rec-1', 'intervention' => 'waarschuwing'], $response->getData());
		$this->assertSame('zaak-1', $captured['caseId']);
		$this->assertSame('aanzienlijk', $captured['severity']);
		$this->assertSame('onverschillig', $captured['behaviour']);
		$this->assertSame(3, $captured['lhsVersion'], 'lhsVersion must reach the engine as an int');
		$this->assertNull($captured['inspection'], 'a blank inspection must not be persisted as a relation');
	}//end testRecommendForwardsTheQuadrupleAndNormalisesOptionalArguments()

	/**
	 * An engine refusal (e.g. no matrix cell for the quadruple) is a 422 — the
	 * request was well-formed but cannot be satisfied.
	 *
	 * @return void
	 */
	public function testRecommendReportsAnEngineRefusalAs422(): void {
		$this->signIn();
		$this->withParams($this->validRecommendPayload());
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->lhsService->method('recommend')
			->willThrowException(new \RuntimeException('Geen matrixcel voor deze combinatie'));

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Geen matrixcel voor deze combinatie'], $response->getData());
	}//end testRecommendReportsAnEngineRefusalAs422()

	/**
	 * An unexpected failure is logged and masked as a 500 — distinct from the
	 * 422 above, and without leaking the internal message.
	 *
	 * @return void
	 */
	public function testRecommendMasksAnUnexpectedFailureAs500AndLogsIt(): void {
		$this->signIn();
		$this->withParams($this->validRecommendPayload());
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->lhsService->method('recommend')
			->willThrowException(new \LogicException('null pointer in matrix loader'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'LHS-aanbeveling mislukt'], $response->getData());
	}//end testRecommendMasksAnUnexpectedFailureAs500AndLogsIt()

	/**
	 * `override` refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testOverrideRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->lhsService->expects($this->never())->method('override');

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}//end testOverrideRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An override without a justification is a 400 — an unmotivated deviation
	 * from the national strategy is exactly what the field exists to prevent.
	 *
	 * @return void
	 */
	public function testOverrideRejectsAMissingJustificationWith400(): void {
		$this->signIn();
		$this->withParams([
			'recommendation' => ['id' => 'rec-1'],
			'intervention' => 'last onder dwangsom',
		]);
		$this->lhsService->expects($this->never())->method('override');

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'recommendation, intervention en justification zijn verplicht'],
			$response->getData()
		);
	}//end testOverrideRejectsAMissingJustificationWith400()

	/**
	 * A `recommendation` that is not an object is a 400 — the engine expects the
	 * original row, not an id.
	 *
	 * @return void
	 */
	public function testOverrideRejectsANonObjectRecommendationWith400(): void {
		$this->signIn();
		$this->withParams([
			'recommendation' => 'rec-1',
			'intervention' => 'last onder dwangsom',
			'justification' => 'Gemotiveerde afwijking van de interventieladder.',
		]);
		$this->lhsService->expects($this->never())->method('override');

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testOverrideRejectsANonObjectRecommendationWith400()

	/**
	 * A caller who merely CLAIMS `userRole=manager` is still forwarded to the
	 * engine as an inspector when the Nextcloud admin check disagrees.
	 *
	 * Trusting the posted role would let any inspector escalate an enforcement
	 * measure by editing one form field.
	 *
	 * @return void
	 */
	public function testOverrideDoesNotTrustAClaimedManagerRole(): void {
		$this->signIn(uid: 'inspector1');
		$this->withParams([
			'recommendation' => ['id' => 'rec-1'],
			'intervention' => 'last onder dwangsom',
			'justification' => 'Gemotiveerde afwijking van de interventieladder.',
			'userRole' => 'manager',
		]);
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('inspector1')
			->willReturn(false);

		$capturedRole = null;
		$this->lhsService->expects($this->once())
			->method('override')
			->willReturnCallback(
				static function (
					array $recommendation,
					string $intervention,
					string $justification,
					string $userRole,
				) use (&$capturedRole): array {
					$capturedRole = $userRole;

					return ['id' => 'rec-1'];
				}
			);

		$this->controller->override();

		$this->assertSame(
			'inspector',
			$capturedRole,
			'a claimed manager role must not be honoured without the admin check'
		);
	}//end testOverrideDoesNotTrustAClaimedManagerRole()

	/**
	 * A real admin who declares the manager role IS forwarded as a manager —
	 * proving the check above is a decision, not a hard-coded "inspector".
	 *
	 * @return void
	 */
	public function testOverrideHonoursADeclaredManagerRoleForAnAdmin(): void {
		$this->signIn(uid: 'coordinator');
		$this->withParams([
			'recommendation' => ['id' => 'rec-1'],
			'intervention' => 'last onder dwangsom',
			'justification' => 'Gemotiveerde afwijking van de interventieladder.',
			'userRole' => 'manager',
		]);
		$this->groupManager->method('isAdmin')->with('coordinator')->willReturn(true);

		$capturedRole = null;
		$this->lhsService->expects($this->once())
			->method('override')
			->willReturnCallback(
				static function (
					array $recommendation,
					string $intervention,
					string $justification,
					string $userRole,
				) use (&$capturedRole): array {
					$capturedRole = $userRole;

					return ['id' => 'rec-1', 'intervention' => $intervention];
				}
			);

		$response = $this->controller->override();

		$this->assertSame('manager', $capturedRole);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('last onder dwangsom', $response->getData()['intervention']);
	}//end testOverrideHonoursADeclaredManagerRoleForAnAdmin()

	/**
	 * The engine's role refusal is mapped to 403, NOT the 422 every other
	 * RuntimeException gets — an authorization refusal must be distinguishable
	 * on the wire from a validation refusal.
	 *
	 * @return void
	 */
	public function testOverrideMapsTheManagerRoleRefusalTo403(): void {
		$this->signIn();
		$this->withParams([
			'recommendation' => ['id' => 'rec-1'],
			'intervention' => 'last onder dwangsom',
			'justification' => 'Gemotiveerde afwijking van de interventieladder.',
		]);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->lhsService->method('override')
			->willThrowException(new \RuntimeException('Verzwaring vereist managerrol'));

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Verzwaring vereist managerrol'], $response->getData());
	}//end testOverrideMapsTheManagerRoleRefusalTo403()

	/**
	 * Every other engine refusal stays a 422.
	 *
	 * @return void
	 */
	public function testOverrideMapsAnyOtherEngineRefusalTo422(): void {
		$this->signIn();
		$this->withParams([
			'recommendation' => ['id' => 'rec-1'],
			'intervention' => 'last onder dwangsom',
			'justification' => 'te kort',
		]);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->lhsService->method('override')
			->willThrowException(new \RuntimeException('Motivatie moet minimaal 20 tekens bevatten'));

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Motivatie moet minimaal 20 tekens bevatten'], $response->getData());
	}//end testOverrideMapsAnyOtherEngineRefusalTo422()
}//end class
