<?php

/**
 * SociaalDomeinPlanController failure-path and lookup tests.
 *
 * Covers what the access tests leave out: a plan that is gone, refusals and
 * failures raised by the services behind every endpoint, and the
 * cross-domain lookup with its sign-in requirement.
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
 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SociaalDomeinPlanController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CasePlanGoalsInterface;
use OCA\Dossiq\Kernel\Contracts\CasePlanInterventionsInterface;
use OCA\Dossiq\Kernel\Contracts\CasePlanReviewInterface;
use OCA\Dossiq\Kernel\Contracts\CrossDomainExistenceInterface;
use OCA\Dossiq\Kernel\Contracts\FamilyPlanAccessInterface;
use OCA\Dossiq\Kernel\Contracts\SociaalDomeinStoreInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The failure and lookup paths of the plan controller.
 *
 * @covers \OCA\Dossiq\Controller\SociaalDomeinPlanController
 * @uses \OCA\Dossiq\Controller\Support\TranslatesRefusals
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
final class SociaalDomeinPlanControllerCoverageTest extends TestCase {

	/**
	 * The plan the requests name.
	 */
	private const PLAN_ID = 'plan-2b19';

	/**
	 * The store.
	 *
	 * @var SociaalDomeinStoreInterface&MockObject
	 */
	private SociaalDomeinStoreInterface $store;

	/**
	 * The goals.
	 *
	 * @var CasePlanGoalsInterface&MockObject
	 */
	private CasePlanGoalsInterface $goals;

	/**
	 * The interventions.
	 *
	 * @var CasePlanInterventionsInterface&MockObject
	 */
	private CasePlanInterventionsInterface $interventions;

	/**
	 * The review.
	 *
	 * @var CasePlanReviewInterface&MockObject
	 */
	private CasePlanReviewInterface $review;

	/**
	 * The cross-domain lookup.
	 *
	 * @var CrossDomainExistenceInterface&MockObject
	 */
	private CrossDomainExistenceInterface $existence;

	/**
	 * The plan access check.
	 *
	 * @var FamilyPlanAccessInterface&MockObject
	 */
	private FamilyPlanAccessInterface $planAccess;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store         = $this->createMock(originalClassName: SociaalDomeinStoreInterface::class);
		$this->goals         = $this->createMock(originalClassName: CasePlanGoalsInterface::class);
		$this->interventions = $this->createMock(originalClassName: CasePlanInterventionsInterface::class);
		$this->review        = $this->createMock(originalClassName: CasePlanReviewInterface::class);
		$this->existence     = $this->createMock(originalClassName: CrossDomainExistenceInterface::class);
		$this->planAccess    = $this->createMock(originalClassName: FamilyPlanAccessInterface::class);
		$this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller with the given request parameters.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return SociaalDomeinPlanController The controller.
	 */
	private function controller(array $params = []): SociaalDomeinPlanController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		return new SociaalDomeinPlanController(
			appName: 'dossiq',
			request: $request,
			store: $this->store,
			goals: $this->goals,
			interventions: $this->interventions,
			review: $this->review,
			existence: $this->existence,
			planAccess: $this->planAccess,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Expect one error log line for the endpoint, carrying the failure's message.
	 *
	 * @param string $op The endpoint.
	 *
	 * @return void
	 */
	private function expectBrokeLog(string $op): void {
		$this->logger->expects($this->once())->method('error')->with(
			'SociaalDomeinPlanController: ' . $op . ' failed',
			['exception' => 'register down']
		);
	}//end expectBrokeLog()

	/**
	 * Assert the response is the generic 500.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return void
	 */
	private static function assertBroke($response): void {
		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		self::assertSame(
			expected: ['message' => 'That did not work.', 'error' => 'sociaal-domein-failed'],
			actual: $response->getData()
		);
	}//end assertBroke()

	/**
	 * Assert the response is the given refusal.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 * @param string                              $rule     The rule slug.
	 * @param int                                 $status   The status.
	 *
	 * @return void
	 */
	private static function assertRefused($response, string $rule, int $status): void {
		self::assertSame(expected: $status, actual: $response->getStatus());
		$data = $response->getData();
		self::assertSame(expected: $rule, actual: $data['error']);
		self::assertSame(expected: str_replace('-', '_', $rule), actual: $data['code']);
		self::assertIsString(actual: $data['message']);
		self::assertNotSame(expected: '', actual: $data['message']);
	}//end assertRefused()

	/**
	 * A readable plan that the store no longer holds is a 404.
	 *
	 * @return void
	 */
	public function testPlanThatIsGoneIsNotFound(): void {
		$this->planAccess->method('planRead')->with(self::PLAN_ID)->willReturn(Http::STATUS_OK);
		$this->store->expects($this->once())->method('read')
			->with(CasePlanReviewInterface::SCHEMA, self::PLAN_ID)
			->willReturn(null);
		$this->goals->expects($this->never())->method('ofPlan');

		$response = $this->controller()->plan(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'Family plan not found'], actual: $response->getData());
	}//end testPlanThatIsGoneIsNotFound()

	/**
	 * A readable plan answers its goals, interventions and review state.
	 *
	 * @return void
	 */
	public function testPlanAnswersItsPayload(): void {
		$plan = ['id' => self::PLAN_ID, 'caseId' => 'case-1'];
		$this->planAccess->method('planRead')->willReturn(Http::STATUS_OK);
		$this->store->method('read')->willReturn($plan);
		$this->goals->expects($this->once())->method('ofPlan')->with(self::PLAN_ID)->willReturn([['id' => 'g1']]);
		$this->interventions->expects($this->once())->method('ofPlan')->with(self::PLAN_ID)->willReturn([]);
		$this->review->expects($this->once())->method('isDue')->with($plan)->willReturn(true);

		$response = $this->controller()->plan(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: ['planId' => self::PLAN_ID, 'plan' => $plan, 'goals' => [['id' => 'g1']], 'interventions' => [], 'dueForReview' => true],
			actual: $response->getData()
		);
	}//end testPlanAnswersItsPayload()

	/**
	 * A refusal raised while reading a plan is answered with its own status.
	 *
	 * @return void
	 */
	public function testPlanTranslatesARefusal(): void {
		$this->planAccess->method('planRead')->willThrowException(
			RefusedException::indeterminate(rule: 'register-unreadable', sentence: 'The register could not be read.')
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'SociaalDomeinPlanController: case-plan could not be answered',
			['rule' => 'register-unreadable', 'status' => RefusedException::STATUS_INDETERMINATE]
		);

		$response = $this->controller()->plan(planId: self::PLAN_ID);

		self::assertRefused(response: $response, rule: 'register-unreadable', status: RefusedException::STATUS_INDETERMINATE);
	}//end testPlanTranslatesARefusal()

	/**
	 * Any other failure reading a plan is a logged 500.
	 *
	 * @return void
	 */
	public function testPlanAnswersAFailureAsA500(): void {
		$this->planAccess->method('planRead')->willReturn(Http::STATUS_OK);
		$this->store->method('read')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'case-plan');

		self::assertBroke(response: $this->controller()->plan(planId: self::PLAN_ID));
	}//end testPlanAnswersAFailureAsA500()

	/**
	 * A refusal finding a case's plan is answered with its own status.
	 *
	 * @return void
	 */
	public function testPlanOfCaseTranslatesARefusal(): void {
		$this->planAccess->method('caseRead')->willThrowException(
			new RefusedException(rule: 'case-closed', sentence: 'The case is closed.', status: RefusedException::STATUS_FORBIDDEN)
		);
		$this->logger->expects($this->once())->method('info')->with(
			'SociaalDomeinPlanController: case-plan-of-case refused',
			['rule' => 'case-closed', 'status' => RefusedException::STATUS_FORBIDDEN]
		);

		self::assertRefused(response: $this->controller()->planOfCase(caseId: 'case-1'), rule: 'case-closed', status: Http::STATUS_FORBIDDEN);
	}//end testPlanOfCaseTranslatesARefusal()

	/**
	 * A store failure finding a case's plan is a logged 500.
	 *
	 * @return void
	 */
	public function testPlanOfCaseAnswersAFailureAsA500(): void {
		$this->planAccess->method('caseRead')->willReturn(Http::STATUS_OK);
		$this->store->expects($this->once())->method('rows')
			->with(CasePlanReviewInterface::SCHEMA, ['caseId' => 'case-1'])
			->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'case-plan-of-case');

		self::assertBroke(response: $this->controller()->planOfCase(caseId: 'case-1'));
	}//end testPlanOfCaseAnswersAFailureAsA500()

	/**
	 * A goal the service refuses is answered with the refusal.
	 *
	 * @return void
	 */
	public function testSaveGoalTranslatesARefusal(): void {
		$this->planAccess->expects($this->once())->method('planWrite')
			->with(self::PLAN_ID, CasePlanGoalsInterface::SCHEMA, 'g-1')
			->willReturn(Http::STATUS_OK);
		$this->goals->expects($this->once())->method('save')
			->with(['id' => 'g-1', 'plan' => self::PLAN_ID, 'title' => 'Sleeps', 'metWhen' => '', 'state' => 'open'])
			->willThrowException(
				new RefusedException(rule: 'goal-title-required', sentence: 'A goal needs a title.', status: RefusedException::STATUS_UNPROCESSABLE)
			);

		$response = $this->controller(params: ['id' => ' g-1 ', 'title' => 'Sleeps'])->saveGoal(planId: self::PLAN_ID);

		self::assertRefused(response: $response, rule: 'goal-title-required', status: RefusedException::STATUS_UNPROCESSABLE);
	}//end testSaveGoalTranslatesARefusal()

	/**
	 * A goal save that fails is a logged 500.
	 *
	 * @return void
	 */
	public function testSaveGoalAnswersAFailureAsA500(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->goals->method('save')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'case-plan-goal');

		self::assertBroke(response: $this->controller()->saveGoal(planId: self::PLAN_ID));
	}//end testSaveGoalAnswersAFailureAsA500()

	/**
	 * Closing a goal passes state and observation and answers the closed goal.
	 *
	 * @return void
	 */
	public function testCloseGoalPassesStateAndObservation(): void {
		$this->planAccess->expects($this->once())->method('goalWrite')->with('g-1')->willReturn(Http::STATUS_OK);
		$this->goals->expects($this->once())->method('close')
			->with('g-1', 'met', 'Sleeps through')
			->willReturn(['id' => 'g-1', 'state' => 'met']);

		$response = $this->controller(params: ['state' => 'met', 'observation' => 'Sleeps through'])->closeGoal(goalId: 'g-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'g-1', 'state' => 'met'], actual: $response->getData());
	}//end testCloseGoalPassesStateAndObservation()

	/**
	 * Closing a goal the service refuses is answered with the refusal.
	 *
	 * @return void
	 */
	public function testCloseGoalTranslatesARefusal(): void {
		$this->planAccess->method('goalWrite')->willReturn(Http::STATUS_OK);
		$this->goals->method('close')->willThrowException(
			new RefusedException(rule: 'goal-met-without-observation', sentence: 'Say what you observed.', status: RefusedException::STATUS_UNPROCESSABLE)
		);

		self::assertRefused(
			response: $this->controller(params: ['state' => 'met'])->closeGoal(goalId: 'g-1'),
			rule: 'goal-met-without-observation',
			status: RefusedException::STATUS_UNPROCESSABLE
		);
	}//end testCloseGoalTranslatesARefusal()

	/**
	 * Closing a goal that fails is a logged 500.
	 *
	 * @return void
	 */
	public function testCloseGoalAnswersAFailureAsA500(): void {
		$this->planAccess->method('goalWrite')->willReturn(Http::STATUS_OK);
		$this->goals->method('close')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'case-plan-goal-close');

		self::assertBroke(response: $this->controller()->closeGoal(goalId: 'g-1'));
	}//end testCloseGoalAnswersAFailureAsA500()

	/**
	 * An intervention is saved with every field the request carries.
	 *
	 * @return void
	 */
	public function testSaveInterventionSavesTheRequestedIntervention(): void {
		$this->planAccess->expects($this->once())->method('planWrite')
			->with(self::PLAN_ID, CasePlanInterventionsInterface::SCHEMA, '')
			->willReturn(Http::STATUS_OK);
		$this->planAccess->expects($this->once())->method('goalOnPlan')
			->with(self::PLAN_ID, 'g-1')
			->willReturn(Http::STATUS_OK);
		$expected = [
			'id' => '',
			'plan' => self::PLAN_ID,
			'goal' => 'g-1',
			'title' => 'Parenting support',
			'description' => '',
			'provider' => 'p-1',
			'providerName' => 'Zorgaanbieder',
			'startDate' => '2026-11-01',
			'targetDate' => '',
			'state' => 'planned',
			'outcome' => '',
		];
		$this->interventions->expects($this->once())->method('save')->with($expected)->willReturn(['id' => 'i-1']);

		$response = $this->controller(
			params: ['goal' => ' g-1', 'title' => 'Parenting support', 'provider' => 'p-1', 'providerName' => 'Zorgaanbieder', 'startDate' => '2026-11-01']
		)->saveIntervention(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'i-1'], actual: $response->getData());
	}//end testSaveInterventionSavesTheRequestedIntervention()

	/**
	 * A goal not on the plan refuses the intervention before anything is saved.
	 *
	 * @return void
	 */
	public function testSaveInterventionForAGoalOffThePlanIsRefused(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->planAccess->method('goalOnPlan')->willReturn(Http::STATUS_NOT_FOUND);
		$this->interventions->expects($this->never())->method('save');

		$response = $this->controller(params: ['goal' => 'elsewhere'])->saveIntervention(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		self::assertSame(expected: ['message' => 'not_found'], actual: $response->getData());
	}//end testSaveInterventionForAGoalOffThePlanIsRefused()

	/**
	 * An intervention the service refuses is answered with the refusal.
	 *
	 * @return void
	 */
	public function testSaveInterventionTranslatesARefusal(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->planAccess->method('goalOnPlan')->willReturn(Http::STATUS_OK);
		$this->interventions->method('save')->willThrowException(
			new RefusedException(rule: 'intervention-provider-required', sentence: 'Name a provider.', status: RefusedException::STATUS_UNPROCESSABLE)
		);

		self::assertRefused(
			response: $this->controller()->saveIntervention(planId: self::PLAN_ID),
			rule: 'intervention-provider-required',
			status: RefusedException::STATUS_UNPROCESSABLE
		);
	}//end testSaveInterventionTranslatesARefusal()

	/**
	 * An intervention save that fails is a logged 500.
	 *
	 * @return void
	 */
	public function testSaveInterventionAnswersAFailureAsA500(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->planAccess->method('goalOnPlan')->willReturn(Http::STATUS_OK);
		$this->interventions->method('save')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'case-plan-intervention');

		self::assertBroke(response: $this->controller()->saveIntervention(planId: self::PLAN_ID));
	}//end testSaveInterventionAnswersAFailureAsA500()

	/**
	 * A review records the reviewer, the changes and the next date.
	 *
	 * @return void
	 */
	public function testRecordReviewRecordsWhoAndWhat(): void {
		$this->planAccess->expects($this->once())->method('planWrite')->with(self::PLAN_ID)->willReturn(Http::STATUS_OK);
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->review->expects($this->once())->method('record')
			->with(self::PLAN_ID, 'consulent', ['goal added'], '2027-05-01')
			->willReturn(['id' => self::PLAN_ID, 'reviewed' => true]);

		$response = $this->controller(params: ['changes' => ['goal added'], 'nextReviewDate' => '2027-05-01'])
			->recordReview(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['id' => self::PLAN_ID, 'reviewed' => true], actual: $response->getData());
	}//end testRecordReviewRecordsWhoAndWhat()

	/**
	 * Changes that are not a list are recorded as none.
	 *
	 * @return void
	 */
	public function testRecordReviewDropsChangesThatAreNotAList(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->review->expects($this->once())->method('record')
			->with(self::PLAN_ID, 'consulent', [], '')
			->willReturn([]);

		$response = $this->controller(params: ['changes' => 'not a list'])->recordReview(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testRecordReviewDropsChangesThatAreNotAList()

	/**
	 * A review the service refuses is answered with the refusal.
	 *
	 * @return void
	 */
	public function testRecordReviewTranslatesARefusal(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->review->method('record')->willThrowException(
			new RefusedException(rule: 'review-date-in-past', sentence: 'The next review must be later.', status: RefusedException::STATUS_UNPROCESSABLE)
		);

		self::assertRefused(
			response: $this->controller()->recordReview(planId: self::PLAN_ID),
			rule: 'review-date-in-past',
			status: RefusedException::STATUS_UNPROCESSABLE
		);
	}//end testRecordReviewTranslatesARefusal()

	/**
	 * A review that fails is a logged 500.
	 *
	 * @return void
	 */
	public function testRecordReviewAnswersAFailureAsA500(): void {
		$this->planAccess->method('planWrite')->willReturn(Http::STATUS_OK);
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->review->method('record')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'case-plan-review');

		self::assertBroke(response: $this->controller()->recordReview(planId: self::PLAN_ID));
	}//end testRecordReviewAnswersAFailureAsA500()

	/**
	 * A lookup without a signed-in user is refused before the service is asked.
	 *
	 * @return void
	 */
	public function testLookUpWithoutASessionIsRefused(): void {
		$this->planAccess->method('currentUid')->willReturn('');
		$this->existence->expects($this->never())->method('lookUp');

		$response = $this->controller(params: ['bsn' => '999993653'])->lookUp();

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(
			expected: ['message' => 'Sign in before looking a person up.', 'error' => 'not-signed-in'],
			actual: $response->getData()
		);
	}//end testLookUpWithoutASessionIsRefused()

	/**
	 * A lookup passes the person, domain, ground and requester and answers what was found.
	 *
	 * @return void
	 */
	public function testLookUpAnswersWhatWasFound(): void {
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->existence->expects($this->once())->method('lookUp')
			->with('999993653', 'jeugd', 'safety', 'consulent')
			->willReturn(['wmo' => true]);

		$response = $this->controller(params: ['bsn' => '999993653', 'domain' => 'jeugd', 'ground' => 'safety'])->lookUp();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['found' => ['wmo' => true]], actual: $response->getData());
	}//end testLookUpAnswersWhatWasFound()

	/**
	 * A lookup without a ground is the service's refusal.
	 *
	 * @return void
	 */
	public function testLookUpTranslatesARefusal(): void {
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->existence->method('lookUp')->willThrowException(
			new RefusedException(rule: 'lookup-ground-required', sentence: 'Choose a ground first.', status: RefusedException::STATUS_FORBIDDEN)
		);

		self::assertRefused(response: $this->controller()->lookUp(), rule: 'lookup-ground-required', status: Http::STATUS_FORBIDDEN);
	}//end testLookUpTranslatesARefusal()

	/**
	 * A lookup that fails is a logged 500.
	 *
	 * @return void
	 */
	public function testLookUpAnswersAFailureAsA500(): void {
		$this->planAccess->method('currentUid')->willReturn('consulent');
		$this->existence->method('lookUp')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'cross-domain-lookup');

		self::assertBroke(response: $this->controller()->lookUp());
	}//end testLookUpAnswersAFailureAsA500()

	/**
	 * The lookups about a person are answered as a list.
	 *
	 * @return void
	 */
	public function testLookupsAboutAnswersTheLookups(): void {
		$this->existence->expects($this->once())->method('lookupsAbout')
			->with('999993653')
			->willReturn([['ground' => 'safety', 'requester' => 'consulent']]);

		$response = $this->controller()->lookupsAbout(bsn: '999993653');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['lookups' => [['ground' => 'safety', 'requester' => 'consulent']]], actual: $response->getData());
	}//end testLookupsAboutAnswersTheLookups()

	/**
	 * Lookups the service refuses to list are answered with the refusal.
	 *
	 * @return void
	 */
	public function testLookupsAboutTranslatesARefusal(): void {
		$this->existence->method('lookupsAbout')->willThrowException(
			new RefusedException(rule: 'lookups-not-yours', sentence: 'You may not see these.', status: RefusedException::STATUS_FORBIDDEN)
		);

		self::assertRefused(response: $this->controller()->lookupsAbout(bsn: '999993653'), rule: 'lookups-not-yours', status: Http::STATUS_FORBIDDEN);
	}//end testLookupsAboutTranslatesARefusal()

	/**
	 * Lookups that cannot be listed are a logged 500.
	 *
	 * @return void
	 */
	public function testLookupsAboutAnswersAFailureAsA500(): void {
		$this->existence->method('lookupsAbout')->willThrowException(new RuntimeException('register down'));
		$this->expectBrokeLog(op: 'cross-domain-lookups-about');

		self::assertBroke(response: $this->controller()->lookupsAbout(bsn: '999993653'));
	}//end testLookupsAboutAnswersAFailureAsA500()
}//end class
