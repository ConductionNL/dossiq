<?php

/**
 * SociaalDomeinPlanController write access tests.
 *
 * A family plan belongs to a case (`gezinsplan.caseId`), and every write on
 * the plan, its goals and its interventions needs the right to change that
 * case, checked before anything is written. A caller who may not change the
 * case gets 403 and the store is not written.
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
 *
 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SociaalDomeinPlanController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\CasePlanReview;
use OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence;
use OCA\Dossiq\Service\SociaalDomein\FamilyPlanAccess;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every plan write checks the plan's case first.
 *
 * @covers \OCA\Dossiq\Controller\SociaalDomeinPlanController
 * @covers \OCA\Dossiq\Service\SociaalDomein\FamilyPlanAccess
 * @uses \OCA\Dossiq\Controller\Support\TranslatesRefusals
 */
class SociaalDomeinPlanWriteAccessTest extends TestCase {

	/**
	 * The case the plan belongs to.
	 */
	private const CASE_ID = 'e0b306d2-c523-5938-9b5c-3474d292dc01';

	/**
	 * Another family's case.
	 */
	private const OTHER_CASE_ID = '9a1c55e0-0000-4000-8000-00000000c0de';

	/**
	 * The plan the writes are routed to.
	 */
	private const PLAN_ID = 'plan-2b19';

	/**
	 * Another family's plan.
	 */
	private const OTHER_PLAN_ID = 'plan-other';

	/**
	 * A goal on the plan.
	 */
	private const GOAL_ID = 'goal-55af';

	/**
	 * A goal that belongs to a case directly.
	 */
	private const CASE_GOAL_ID = 'goal-on-case';

	/**
	 * A goal on another family's plan.
	 */
	private const OTHER_GOAL_ID = 'goal-other';

	/**
	 * An intervention on another family's plan.
	 */
	private const OTHER_INTERVENTION_ID = 'intervention-other';

	/**
	 * The service writes that ran.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * The cases the guard was asked about.
	 *
	 * @var array<int, string>
	 */
	private array $checked = [];

	/**
	 * Build the controller.
	 *
	 * @param array<int, string>   $mayChange The cases the caller may change.
	 * @param array<string, mixed> $params    Request parameters.
	 * @param bool                 $loggedIn  Whether there is a session user.
	 *
	 * @return SociaalDomeinPlanController The controller.
	 */
	private function controller(array $mayChange, array $params = [], bool $loggedIn = true): SociaalDomeinPlanController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('consulent');
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$rows = [
			CasePlanReview::SCHEMA => [
				self::PLAN_ID => ['id' => self::PLAN_ID, 'caseId' => self::CASE_ID],
				self::OTHER_PLAN_ID => ['id' => self::OTHER_PLAN_ID, 'caseId' => self::OTHER_CASE_ID],
				'plan-without-case' => ['id' => 'plan-without-case'],
			],
			CasePlanGoals::SCHEMA => [
				self::GOAL_ID => ['id' => self::GOAL_ID, 'plan' => self::PLAN_ID],
				self::CASE_GOAL_ID => ['id' => self::CASE_GOAL_ID, 'case' => self::CASE_ID],
				self::OTHER_GOAL_ID => ['id' => self::OTHER_GOAL_ID, 'plan' => self::OTHER_PLAN_ID],
			],
			CasePlanInterventions::SCHEMA => [
				self::OTHER_INTERVENTION_ID => ['id' => self::OTHER_INTERVENTION_ID, 'plan' => self::OTHER_PLAN_ID],
			],
		];
		$store = $this->createMock(originalClassName: SociaalDomeinStore::class);
		$store->method('read')->willReturnCallback(
			static fn (string $schema, string $id): ?array => ($rows[$schema][$id] ?? null)
		);
		$store->expects($this->never())->method('write');

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId, IUser $user) use ($mayChange): bool {
				$this->checked[] = $caseId;
				return in_array(needle: $caseId, haystack: $mayChange, strict: true);
			}
		);

		$goals = $this->createMock(originalClassName: CasePlanGoals::class);
		$goals->method('save')->willReturnCallback(
			function (array $goal): array {
				$this->writes[] = 'goal.save';
				return $goal;
			}
		);
		$goals->method('close')->willReturnCallback(
			function (string $goalId): array {
				$this->writes[] = 'goal.close';
				return ['id' => $goalId];
			}
		);

		$interventions = $this->createMock(originalClassName: CasePlanInterventions::class);
		$interventions->method('save')->willReturnCallback(
			function (array $intervention): array {
				$this->writes[] = 'intervention.save';
				return $intervention;
			}
		);

		$review = $this->createMock(originalClassName: CasePlanReview::class);
		$review->method('record')->willReturnCallback(
			function (string $planId): array {
				$this->writes[] = 'review.record';
				return ['id' => $planId];
			}
		);

		return new SociaalDomeinPlanController(
			appName: 'dossiq',
			request: $request,
			store: $store,
			goals: $goals,
			interventions: $interventions,
			review: $review,
			existence: $this->createMock(originalClassName: CrossDomainExistence::class),
			planAccess: new FamilyPlanAccess(store: $store, caseAccessGuard: $guard, userSession: $session),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A caller who may not change the plan's case cannot save a goal on it.
	 *
	 * @return void
	 */
	public function testSaveGoalIsRefusedWithoutTheRightToChangeTheCase(): void {
		$response = $this->controller(mayChange: [])->saveGoal(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
		self::assertSame(expected: [self::CASE_ID], actual: $this->checked);
	}//end testSaveGoalIsRefusedWithoutTheRightToChangeTheCase()

	/**
	 * A caller who may change the case saves the goal.
	 *
	 * @return void
	 */
	public function testSaveGoalWritesWithTheRightToChangeTheCase(): void {
		$response = $this->controller(mayChange: [self::CASE_ID], params: ['title' => 'Sleeps through'])
			->saveGoal(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['goal.save'], actual: $this->writes);
	}//end testSaveGoalWritesWithTheRightToChangeTheCase()

	/**
	 * Naming another family's goal by id, through a plan the caller may change, is refused.
	 *
	 * @return void
	 */
	public function testSaveGoalCannotRewriteAGoalOfAnotherCase(): void {
		$response = $this->controller(mayChange: [self::CASE_ID], params: ['id' => self::OTHER_GOAL_ID, 'title' => 'Mine now'])
			->saveGoal(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testSaveGoalCannotRewriteAGoalOfAnotherCase()

	/**
	 * Closing a goal checks the case of the goal's plan.
	 *
	 * @return void
	 */
	public function testCloseGoalIsRefusedWithoutTheRightToChangeTheCase(): void {
		$response = $this->controller(mayChange: [self::OTHER_CASE_ID], params: ['state' => 'met'])
			->closeGoal(goalId: self::GOAL_ID);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
		self::assertSame(expected: [self::CASE_ID], actual: $this->checked);
	}//end testCloseGoalIsRefusedWithoutTheRightToChangeTheCase()

	/**
	 * Closing a goal that belongs to a case directly checks that case.
	 *
	 * @return void
	 */
	public function testCloseGoalOfACaseChecksThatCase(): void {
		$response = $this->controller(mayChange: [self::CASE_ID], params: ['state' => 'met'])
			->closeGoal(goalId: self::CASE_GOAL_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['goal.close'], actual: $this->writes);
		self::assertSame(expected: [self::CASE_ID], actual: $this->checked);
	}//end testCloseGoalOfACaseChecksThatCase()

	/**
	 * A goal that is not there is 404, and nothing is written.
	 *
	 * @return void
	 */
	public function testCloseGoalOfAnUnknownGoalIsNotFound(): void {
		$response = $this->controller(mayChange: [self::CASE_ID])->closeGoal(goalId: 'goal-missing');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testCloseGoalOfAnUnknownGoalIsNotFound()

	/**
	 * Interventions and reviews are writes on the plan too.
	 *
	 * @return void
	 */
	public function testInterventionAndReviewAreRefusedWithoutTheRightToChangeTheCase(): void {
		$controller = $this->controller(mayChange: []);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->saveIntervention(planId: self::PLAN_ID)->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->recordReview(planId: self::PLAN_ID)->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testInterventionAndReviewAreRefusedWithoutTheRightToChangeTheCase()

	/**
	 * Naming another family's intervention by id is refused.
	 *
	 * @return void
	 */
	public function testSaveInterventionCannotRewriteAnInterventionOfAnotherCase(): void {
		$response = $this->controller(mayChange: [self::CASE_ID], params: ['id' => self::OTHER_INTERVENTION_ID])
			->saveIntervention(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testSaveInterventionCannotRewriteAnInterventionOfAnotherCase()

	/**
	 * A plan without a case, or one that is not there, is not written: there is no case to check.
	 *
	 * @return void
	 */
	public function testAPlanWithoutACaseOrUnknownIsNotWritten(): void {
		$controller = $this->controller(mayChange: [self::CASE_ID]);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->saveGoal(planId: 'plan-without-case')->getStatus());
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->saveGoal(planId: 'plan-missing')->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testAPlanWithoutACaseOrUnknownIsNotWritten()

	/**
	 * Without a session the write is 401.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheWriteIsUnauthorized(): void {
		$response = $this->controller(mayChange: [self::CASE_ID], loggedIn: false)->saveGoal(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testWithoutASessionTheWriteIsUnauthorized()
}//end class
