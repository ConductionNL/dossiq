<?php

/**
 * SociaalDomeinPlanController read access tests.
 *
 * A family plan is read only by whoever may read its case: `plan()` checks
 * the case the plan points at, `planOfCase()` the case in the route, before
 * the goals, interventions or plans are read.
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
 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-family-plan-is-read-only-by-whoever-may-read-its-case
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
 * Both plan reads check the case first.
 *
 * @covers \OCA\Dossiq\Controller\SociaalDomeinPlanController
 * @covers \OCA\Dossiq\Service\SociaalDomein\FamilyPlanAccess
 * @uses \OCA\Dossiq\Controller\Support\TranslatesRefusals
 */
class SociaalDomeinPlanReadAccessTest extends TestCase {

	/**
	 * The case the plan belongs to.
	 */
	private const CASE_ID = 'e0b306d2-c523-5938-9b5c-3474d292dc01';

	/**
	 * The plan of that case.
	 */
	private const PLAN_ID = 'plan-2b19';

	/**
	 * What was read past the access check.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Build the controller.
	 *
	 * @param array<int, string> $mayRead  The cases the caller may read.
	 * @param bool               $loggedIn Whether there is a session user.
	 *
	 * @return SociaalDomeinPlanController The controller.
	 */
	private function controller(array $mayRead, bool $loggedIn = true): SociaalDomeinPlanController {
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('consulent');
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$plans = [
			self::PLAN_ID => ['id' => self::PLAN_ID, 'caseId' => self::CASE_ID, 'preparedDate' => '2026-10-18'],
			'plan-without-case' => ['id' => 'plan-without-case'],
		];
		$store = $this->createMock(originalClassName: SociaalDomeinStore::class);
		$store->method('read')->willReturnCallback(
			static fn (string $schema, string $id): ?array => ($plans[$id] ?? null)
		);
		$store->method('rows')->willReturnCallback(
			function (string $schema, array $filters = []) use ($plans): array {
				$this->reads[] = 'plans-of-case';
				return [$plans[self::PLAN_ID]];
			}
		);

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			static fn (string $caseId, IUser $user): bool => in_array(needle: $caseId, haystack: $mayRead, strict: true)
		);
		$guard->method('hasCaseMutationAccess')->willReturn(false);

		$goals = $this->createMock(originalClassName: CasePlanGoals::class);
		$goals->method('ofPlan')->willReturnCallback(
			function (string $planId): array {
				$this->reads[] = 'goals';
				return [];
			}
		);
		$interventions = $this->createMock(originalClassName: CasePlanInterventions::class);
		$interventions->method('ofPlan')->willReturn([]);
		$review = $this->createMock(originalClassName: CasePlanReview::class);
		$review->method('isDue')->willReturn(false);

		return new SociaalDomeinPlanController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
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
	 * A caller who may not read the plan's case does not get the plan.
	 *
	 * @return void
	 */
	public function testPlanIsRefusedWithoutTheRightToReadTheCase(): void {
		$response = $this->controller(mayRead: [])->plan(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertArrayNotHasKey(key: 'plan', array: $response->getData());
		self::assertSame(expected: [], actual: $this->reads);
	}//end testPlanIsRefusedWithoutTheRightToReadTheCase()

	/**
	 * A caller who may read the case gets the plan.
	 *
	 * @return void
	 */
	public function testPlanIsReadWithTheRightToReadTheCase(): void {
		$response = $this->controller(mayRead: [self::CASE_ID])->plan(planId: self::PLAN_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: self::PLAN_ID, actual: $response->getData()['plan']['id']);
	}//end testPlanIsReadWithTheRightToReadTheCase()

	/**
	 * A plan without a case, or one that is not there, is not answered.
	 *
	 * @return void
	 */
	public function testAPlanWithoutACaseOrUnknownIsNotAnswered(): void {
		$controller = $this->controller(mayRead: [self::CASE_ID]);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->plan(planId: 'plan-without-case')->getStatus());
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->plan(planId: 'plan-missing')->getStatus());
		self::assertSame(expected: [], actual: $this->reads);
	}//end testAPlanWithoutACaseOrUnknownIsNotAnswered()

	/**
	 * The plan of a case the caller may not read is refused before the plans are read.
	 *
	 * @return void
	 */
	public function testPlanOfCaseIsRefusedBeforeThePlansAreRead(): void {
		$response = $this->controller(mayRead: [])->planOfCase(caseId: self::CASE_ID);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->reads);
	}//end testPlanOfCaseIsRefusedBeforeThePlansAreRead()

	/**
	 * A caller who may read the case gets its plan.
	 *
	 * @return void
	 */
	public function testPlanOfCaseIsReadWithTheRightToReadTheCase(): void {
		$response = $this->controller(mayRead: [self::CASE_ID])->planOfCase(caseId: self::CASE_ID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: self::PLAN_ID, actual: $response->getData()['plan']['id']);
	}//end testPlanOfCaseIsReadWithTheRightToReadTheCase()

	/**
	 * Without a session both reads are 401.
	 *
	 * @return void
	 */
	public function testWithoutASessionBothReadsAreUnauthorized(): void {
		$controller = $this->controller(mayRead: [self::CASE_ID], loggedIn: false);

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->plan(planId: self::PLAN_ID)->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->planOfCase(caseId: self::CASE_ID)->getStatus());
		self::assertSame(expected: [], actual: $this->reads);
	}//end testWithoutASessionBothReadsAreUnauthorized()
}//end class
