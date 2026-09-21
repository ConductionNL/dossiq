<?php

/**
 * SociaalDomeinPlanController wire-contract tests.
 *
 * Contract coverage (gate-25) for the five plan endpoints that had none:
 * `saveGoal`, `closeGoal`, `saveIntervention`, `recordReview` and `grounds`.
 * Four of them write, all five are `#[NoAdminRequired]`, and the guard they
 * rely on lives in the service rather than the controller. That is exactly why
 * the wire needs pinning here: the controller's whole job is to assemble a
 * payload from named request parameters and to translate a refusal, and both
 * halves fail silently when they go wrong.
 *
 * The contract pinned here:
 *
 *  - THE PARAMETER NAMES ARE THE CONTRACT. `saveGoal` reads `title`, `metWhen`
 *    and `state`; `closeGoal` reads `state` and `observation`; `recordReview`
 *    reads `changes` and `nextReviewDate`. A controller that read a
 *    differently-spelled key would store an empty string and answer 200, which
 *    is the failure this whole gate exists for. Each test asserts the value
 *    that reached the service, not just the status code;
 *  - `planId` and `goalId` come from the ROUTE and overwrite anything the body
 *    says. A body-supplied `plan` that won would let a caller write onto
 *    another family's plan through an endpoint scoped to their own;
 *  - a refusal keeps ITS OWN status. `RefusedException` carries 403, 409, 422
 *    or 503 and the response uses that, not a flattened 400. A client that
 *    retries on 503 and gives up on 409 depends on the difference;
 *  - `recordReview` stamps the SESSION user as the reviewer, never a body
 *    parameter. A review anybody can attribute to anybody is not a review;
 *  - `grounds` answers the server's own list, so the dialog cannot offer a
 *    ground the service will refuse.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SociaalDomeinPlanController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\CasePlanReview;
use OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for SociaalDomeinPlanController.
 *
 * @covers \OCA\Dossiq\Controller\SociaalDomeinPlanController
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\SociaalDomein\CasePlanGoals
 * @uses \OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions
 * @uses \OCA\Dossiq\Service\SociaalDomein\CasePlanReview
 * @uses \OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Controller\Support\TranslatesRefusals
 */
class SociaalDomeinPlanControllerContractTest extends TestCase {

	/** The plan every test writes onto. */
	private const PLAN_ID = 'plan-2b19';

	/** The goal every close test names. */
	private const GOAL_ID = 'goal-55af';

	/**
	 * What the last service call received.
	 *
	 * @var array<string, mixed>
	 */
	private array $seen = [];

	/** The goals service double. */
	private CasePlanGoals $goals;

	/** The interventions service double. */
	private CasePlanInterventions $interventions;

	/** The review service double. */
	private CasePlanReview $review;

	/**
	 * Build the controller over doubles that record what they were given.
	 *
	 * @param array<string, mixed>  $params  Request parameters.
	 * @param RefusedException|null $refusal A refusal every write throws, or null.
	 * @param string|null           $uid     The session uid, or null.
	 *
	 * @return SociaalDomeinPlanController The controller.
	 */
	private function controller(
		array $params = [],
		?RefusedException $refusal = null,
		?string $uid = 'consulent',
	): SociaalDomeinPlanController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->goals = $this->createMock(CasePlanGoals::class);
		$this->goals->method('save')->willReturnCallback(
			function (array $goal) use ($refusal): array {
				$this->seen = $goal;
				if ($refusal !== null) {
					throw $refusal;
				}
				return ($goal + ['stored' => true]);
			}
		);
		$this->goals->method('close')->willReturnCallback(
			function (string $goalId, string $state, string $observation) use ($refusal): array {
				$this->seen = ['goalId' => $goalId, 'state' => $state, 'observation' => $observation];
				if ($refusal !== null) {
					throw $refusal;
				}
				return $this->seen;
			}
		);

		$this->interventions = $this->createMock(CasePlanInterventions::class);
		$this->interventions->method('save')->willReturnCallback(
			function (array $intervention) use ($refusal): array {
				$this->seen = $intervention;
				if ($refusal !== null) {
					throw $refusal;
				}
				return ($intervention + ['stored' => true]);
			}
		);

		$this->review = $this->createMock(CasePlanReview::class);
		$this->review->method('record')->willReturnCallback(
			function (string $planId, string $reviewedBy, array $changes, string $nextReviewDate) use ($refusal): array {
				$this->seen = [
					'planId' => $planId,
					'reviewedBy' => $reviewedBy,
					'changes' => $changes,
					'nextReviewDate' => $nextReviewDate,
				];
				if ($refusal !== null) {
					throw $refusal;
				}
				return $this->seen;
			}
		);

		return new SociaalDomeinPlanController(
			appName: 'dossiq',
			request: $request,
			store: $this->createMock(SociaalDomeinStore::class),
			goals: $this->goals,
			interventions: $this->interventions,
			review: $this->review,
			existence: $this->createMock(CrossDomainExistence::class),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * `saveGoal` reads the parameter names the wire actually sends.
	 *
	 * @return void
	 */
	public function testSaveGoalReadsTheNamedParametersAndTheRoutePlan(): void {
		$response = $this->controller(
			params: [
				'title' => 'Stable housing by the summer',
				'metWhen' => 'A tenancy agreement is signed',
				'state' => 'open',
				// A body that tries to write onto another plan.
				'plan' => 'plan-somebody-elses',
			]
		)->saveGoal(planId: self::PLAN_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Stable housing by the summer', $this->seen['title']);
		self::assertSame('A tenancy agreement is signed', $this->seen['metWhen']);
		// THE ROUTE WINS. A body-supplied plan that overrode this would let a
		// caller write a goal onto a family's plan they were not routed to.
		self::assertSame(self::PLAN_ID, $this->seen['plan']);
	}//end testSaveGoalReadsTheNamedParametersAndTheRoutePlan()

	/**
	 * A goal with no `state` defaults to open rather than to nothing.
	 *
	 * @return void
	 */
	public function testAGoalWithNoStateIsOpenRatherThanEmpty(): void {
		$this->controller(params: ['title' => 'Something'])->saveGoal(planId: self::PLAN_ID);

		self::assertSame('open', $this->seen['state']);
	}//end testAGoalWithNoStateIsOpenRatherThanEmpty()

	/**
	 * `closeGoal` passes the route goal and both named parameters through.
	 *
	 * @return void
	 */
	public function testCloseGoalPassesTheObservationThroughRatherThanDroppingIt(): void {
		$response = $this->controller(
			params: ['state' => 'met', 'observation' => 'The tenancy started on 1 June.']
		)->closeGoal(goalId: self::GOAL_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(self::GOAL_ID, $this->seen['goalId']);
		self::assertSame('met', $this->seen['state']);
		// The observation is what a later reader has instead of the person who
		// closed it. Dropping it answers 200 and loses the only evidence.
		self::assertSame('The tenancy started on 1 June.', $this->seen['observation']);
	}//end testCloseGoalPassesTheObservationThroughRatherThanDroppingIt()

	/**
	 * A refused close keeps the refusal's own status, not a flattened 400.
	 *
	 * @return void
	 */
	public function testARefusedCloseKeepsTheRefusalsOwnStatusAndRule(): void {
		$response = $this->controller(
			params: ['state' => 'met'],
			refusal: new RefusedException(
				rule: 'goal-not-met',
				sentence: 'This goal says it is met when a tenancy is signed, and none is recorded.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			),
		)->closeGoal(goalId: self::GOAL_ID);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('goal-not-met', $response->getData()['error']);
		self::assertStringContainsString('tenancy', $response->getData()['message']);
	}//end testARefusedCloseKeepsTheRefusalsOwnStatusAndRule()

	/**
	 * A service that could not answer is 503, which a client may retry.
	 *
	 * @return void
	 */
	public function testAnUnanswerableWriteIsFiveOhThreeAndNotTheSameAsARefusal(): void {
		$response = $this->controller(
			params: ['title' => 'Something'],
			refusal: new RefusedException(
				rule: 'consent-service-unreachable',
				sentence: 'The consent register could not be reached.',
				status: RefusedException::STATUS_INDETERMINATE,
			),
		)->saveGoal(planId: self::PLAN_ID);

		self::assertSame(503, $response->getStatus());
	}//end testAnUnanswerableWriteIsFiveOhThreeAndNotTheSameAsARefusal()

	/**
	 * `saveIntervention` carries the provider and both dates.
	 *
	 * @return void
	 */
	public function testSaveInterventionCarriesTheProviderAndBothDates(): void {
		$response = $this->controller(
			params: [
				'goal' => self::GOAL_ID,
				'title' => 'Ambulante begeleiding',
				'provider' => 'aanbieder-118',
				'providerName' => 'Stichting Thuisbasis',
				'startDate' => '2026-04-01',
				'targetDate' => '2026-10-01',
			]
		)->saveIntervention(planId: self::PLAN_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(self::PLAN_ID, $this->seen['plan']);
		self::assertSame(self::GOAL_ID, $this->seen['goal']);
		self::assertSame('aanbieder-118', $this->seen['provider']);
		// BOTH dates, distinctly. One date read into the other would make every
		// intervention start and end on the same day and still answer 200.
		self::assertSame('2026-04-01', $this->seen['startDate']);
		self::assertSame('2026-10-01', $this->seen['targetDate']);
		self::assertSame('planned', $this->seen['state']);
	}//end testSaveInterventionCarriesTheProviderAndBothDates()

	/**
	 * `recordReview` stamps the session user, never a body parameter.
	 *
	 * @return void
	 */
	public function testRecordReviewStampsTheSessionUserAndNotTheBody(): void {
		$response = $this->controller(
			params: [
				'changes' => [['field' => 'goal', 'was' => 'open']],
				'nextReviewDate' => '2026-12-01',
				// The body claims somebody else did the review.
				'reviewedBy' => 'iemand-anders',
			]
		)->recordReview(planId: self::PLAN_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('consulent', $this->seen['reviewedBy']);
		self::assertSame('2026-12-01', $this->seen['nextReviewDate']);
		self::assertCount(1, $this->seen['changes']);
	}//end testRecordReviewStampsTheSessionUserAndNotTheBody()

	/**
	 * A non-array `changes` becomes an empty list rather than a type error.
	 *
	 * @return void
	 */
	public function testANonArrayChangesBecomesAnEmptyListRatherThanBreaking(): void {
		$response = $this->controller(params: ['changes' => 'not-a-list'])
			->recordReview(planId: self::PLAN_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $this->seen['changes']);
	}//end testANonArrayChangesBecomesAnEmptyListRatherThanBreaking()

	/**
	 * `grounds` answers the service's own list, so the dialog cannot invent one.
	 *
	 * @return void
	 */
	public function testGroundsAnswersTheServersOwnListAndNoOther(): void {
		$response = $this->controller()->grounds();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$ids = array_column($response->getData()['grounds'], 'id');

		self::assertSame(array_keys(CrossDomainExistence::GROUNDS), $ids);
		// Every one carries a label. An id with no label renders as a blank
		// radio button that a consulent cannot choose between.
		foreach ($response->getData()['grounds'] as $ground) {
			self::assertNotSame('', $ground['label'], $ground['id'] . ' has no label');
		}
	}//end testGroundsAnswersTheServersOwnListAndNoOther()
}//end class
