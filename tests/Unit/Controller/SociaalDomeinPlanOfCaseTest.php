<?php

/**
 * SociaalDomeinPlanController::planOfCase tests.
 *
 * A case finds its family plan through the relation the gezinsplan schema
 * holds (`caseId`), not through a field on the case: the case schema has no
 * `gezinsplanId`, so a lookup by that field found no plan for any case.
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
 * @spec openspec/changes/family-plan-by-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-finds-its-family-plan-by-the-plans-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SociaalDomeinPlanController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\CasePlanReview;
use OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the family plan of a case.
 *
 * @covers \OCA\Dossiq\Controller\SociaalDomeinPlanController
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Controller\Support\TranslatesRefusals
 */
class SociaalDomeinPlanOfCaseTest extends TestCase {

	/** The case every test asks about. */
	private const CASE_ID = 'e0b306d2-c523-5938-9b5c-3474d292dc01';

	/**
	 * The filters the store was asked with.
	 *
	 * @var array<string, mixed>
	 */
	private array $asked = [];

	/**
	 * Build the controller over a store that answers these plans.
	 *
	 * @param array<int, array<string, mixed>>|RefusedException $plans The plans, or a refusal.
	 *
	 * @return SociaalDomeinPlanController The controller.
	 */
	private function controller(array|RefusedException $plans): SociaalDomeinPlanController {
		$store = $this->createMock(SociaalDomeinStore::class);
		$store->method('rows')->willReturnCallback(
			function (string $schema, array $filters = []) use ($plans): array {
				$this->asked = ['schema' => $schema] + $filters;
				if ($plans instanceof RefusedException) {
					throw $plans;
				}
				return $plans;
			}
		);

		$goals = $this->createMock(CasePlanGoals::class);
		$goals->method('ofPlan')->willReturnCallback(static fn (string $planId): array => [['plan' => $planId, 'title' => 'Sleeps through']]);
		$interventions = $this->createMock(CasePlanInterventions::class);
		$interventions->method('ofPlan')->willReturn([]);
		$review = $this->createMock(CasePlanReview::class);
		$review->method('isDue')->willReturn(false);

		return new SociaalDomeinPlanController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			store: $store,
			goals: $goals,
			interventions: $interventions,
			review: $review,
			existence: $this->createMock(CrossDomainExistence::class),
			userSession: $this->createMock(IUserSession::class),
			caseAccessGuard: $this->createMock(originalClassName: CaseAccessGuard::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The plan is the gezinsplan whose caseId is this case; the newest wins.
	 *
	 * @return void
	 */
	public function testFindsThePlanThatPointsAtTheCase(): void {
		$response = $this->controller(
			plans: [
				['id' => 'plan-old', 'caseId' => self::CASE_ID, 'preparedDate' => '2026-03-01', 'status' => 'evaluated'],
				['id' => 'plan-new', 'caseId' => self::CASE_ID, 'preparedDate' => '2026-10-18', 'status' => 'draft'],
			]
		)->planOfCase(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['schema' => 'gezinsplan', 'caseId' => self::CASE_ID], $this->asked);
		$data = $response->getData();
		self::assertSame('plan-new', $data['plan']['id']);
		self::assertSame('draft', $data['plan']['status']);
		self::assertSame('plan-new', $data['goals'][0]['plan']);
		self::assertFalse($data['dueForReview']);
	}//end testFindsThePlanThatPointsAtTheCase()

	/**
	 * A case without a plan answers an empty plan, not an error: the card
	 * then says the plan has no goals yet.
	 *
	 * @return void
	 */
	public function testACaseWithoutAPlanAnswersAnEmptyPlan(): void {
		$response = $this->controller(plans: [])->planOfCase(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['plan' => null, 'goals' => [], 'interventions' => [], 'dueForReview' => false], $response->getData());
	}//end testACaseWithoutAPlanAnswersAnEmptyPlan()

	/**
	 * An unreadable register keeps its refusal, so it never looks like "no plan".
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsRefusedNotEmpty(): void {
		$refusal = new RefusedException(rule: 'sociaal-domein-unreadable', sentence: 'Unreadable.', status: RefusedException::STATUS_INDETERMINATE);
		$response = $this->controller(plans: $refusal)->planOfCase(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testAnUnreadableRegisterIsRefusedNotEmpty()
}//end class
