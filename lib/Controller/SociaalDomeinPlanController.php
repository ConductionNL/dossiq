<?php

/**
 * Dossiq Sociaal Domein Plan Controller.
 *
 * The family plan with its goals and interventions, and the cross-domain
 * existence lookup that is gated on a recorded ground.
 *
 * 🔴 `#[NoAdminRequired]` ON EVERY METHOD, WITH THE GUARD IN THE SERVICE. The
 * lookup's guard is not a role: it is that a GROUND has been chosen, and
 * {@see \OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence::assertGround()}
 * refuses without one before it reads anything at all. The plan's writes are
 * guarded by the case the plan belongs to, through the same
 * {@see CaseAccessGuard} every other write on a case uses, because a family
 * plan is the most sensitive record this app holds and an unguarded
 * `#[NoAdminRequired]` write on it is the IDOR gate 12 exists for.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\CasePlanReview;
use OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence;
use OCA\Dossiq\Service\SociaalDomein\FamilyPlanAccess;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The plan, its goals and interventions, the review and the lookup.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class SociaalDomeinPlanController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string                $appName       The app name.
	 * @param IRequest              $request       The request.
	 * @param SociaalDomeinStore    $store         The one reader of these schemas.
	 * @param CasePlanGoals         $goals         The plan's goals.
	 * @param CasePlanInterventions $interventions The plan's interventions.
	 * @param CasePlanReview        $review        The plan's reviews.
	 * @param CrossDomainExistence  $existence     The cross-domain lookup.
	 * @param FamilyPlanAccess $planAccess    The case check every plan write runs first.
	 * @param LoggerInterface       $logger        The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SociaalDomeinStore $store,
		private readonly CasePlanGoals $goals,
		private readonly CasePlanInterventions $interventions,
		private readonly CasePlanReview $review,
		private readonly CrossDomainExistence $existence,
		private readonly FamilyPlanAccess $planAccess,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * One family plan: its goals, its interventions and whether it is stale.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return JSONResponse The plan.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	#[NoAdminRequired]
	public function plan(string $planId): JSONResponse {
		try {
			$status = $this->planAccess->planRead(planId: $planId);
			if ($status !== Http::STATUS_OK) {
				return $this->denied(status: $status);
			}

			$plan = $this->store->read(schema: CasePlanReview::SCHEMA, id: $planId);
			if ($plan === null) {
				return new JSONResponse(['error' => 'Family plan not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->planPayload(plan: $plan, planId: $planId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-plan', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-plan', e: $e);
		}
	}//end plan()

	/**
	 * The family plan of a case: the gezinsplan whose `caseId` is this case,
	 * the most recently prepared one when there are several, in the shape of
	 * `plan()`. A case without a plan answers an empty plan, so the card says
	 * the plan has no goals yet; an unreadable register stays a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The plan, or an empty one.
	 *
	 * @spec openspec/changes/family-plan-by-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-finds-its-family-plan-by-the-plans-case
	 */
	#[NoAdminRequired]
	public function planOfCase(string $caseId): JSONResponse {
		try {
			$status = $this->planAccess->caseRead(caseId: $caseId);
			if ($status !== Http::STATUS_OK) {
				return $this->denied(status: $status);
			}

			$plans = [];
			if (trim($caseId) !== '') {
				$plans = $this->store->rows(schema: CasePlanReview::SCHEMA, filters: ['caseId' => $caseId]);
			}

			if ($plans === []) {
				return new JSONResponse(['plan' => null, 'goals' => [], 'interventions' => [], 'dueForReview' => false]);
			}

			usort(
				$plans,
				static fn (array $one, array $two): int => strcmp((string)($two['preparedDate'] ?? ''), (string)($one['preparedDate'] ?? ''))
			);
			$plan   = $plans[0];
			$planId = (string)($plan['id'] ?? ($plan['@self']['id'] ?? ''));

			return new JSONResponse($this->planPayload(plan: $plan, planId: $planId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-plan-of-case', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-plan-of-case', e: $e);
		}//end try
	}//end planOfCase()

	/**
	 * A plan with its goals, interventions and review state.
	 *
	 * @param array<string, mixed> $plan   The gezinsplan.
	 * @param string               $planId Its uuid.
	 *
	 * @return array<string, mixed> The payload `plan()` and `planOfCase()` answer.
	 */
	private function planPayload(array $plan, string $planId): array {
		return [
			'plan' => $plan,
			'goals' => $this->goals->ofPlan(planId: $planId),
			'interventions' => $this->interventions->ofPlan(planId: $planId),
			'dueForReview' => $this->review->isDue(plan: $plan),
		];
	}//end planPayload()

	/**
	 * Save a goal on a plan.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return JSONResponse The stored goal, or the refusal.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	#[NoAdminRequired]
	public function saveGoal(string $planId): JSONResponse {
		try {
			$status = $this->planAccess->planWrite(
				planId: $planId,
				recordSchema: CasePlanGoals::SCHEMA,
				recordId: trim((string)$this->request->getParam('id', ''))
			);
			if ($status !== Http::STATUS_OK) {
				return $this->denied(status: $status);
			}

			return new JSONResponse(
				$this->goals->save(
					goal: [
						'id' => trim((string)$this->request->getParam('id', '')),
						'plan' => $planId,
						'title' => (string)$this->request->getParam('title', ''),
						'metWhen' => (string)$this->request->getParam('metWhen', ''),
						'state' => (string)$this->request->getParam('state', 'open'),
					]
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-plan-goal', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-plan-goal', e: $e);
		}
	}//end saveGoal()

	/**
	 * Close a goal against what it said would count as met.
	 *
	 * @param string $goalId The goal uuid.
	 *
	 * @return JSONResponse The closed goal, or the refusal.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	#[NoAdminRequired]
	public function closeGoal(string $goalId): JSONResponse {
		try {
			$status = $this->planAccess->goalWrite(goalId: $goalId);
			if ($status !== Http::STATUS_OK) {
				return $this->denied(status: $status);
			}

			return new JSONResponse(
				$this->goals->close(
					goalId: $goalId,
					state: (string)$this->request->getParam('state', ''),
					observation: (string)$this->request->getParam('observation', ''),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-plan-goal-close', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-plan-goal-close', e: $e);
		}
	}//end closeGoal()

	/**
	 * Save an intervention on a plan.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return JSONResponse The stored intervention, or the refusal.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	#[NoAdminRequired]
	public function saveIntervention(string $planId): JSONResponse {
		try {
			$status = $this->planAccess->planWrite(
				planId: $planId,
				recordSchema: CasePlanInterventions::SCHEMA,
				recordId: trim((string)$this->request->getParam('id', ''))
			);
			if ($status === Http::STATUS_OK) {
				$status = $this->planAccess->goalOnPlan(planId: $planId, goalId: trim((string)$this->request->getParam('goal', '')));
			}

			if ($status !== Http::STATUS_OK) {
				return $this->denied(status: $status);
			}

			return new JSONResponse(
				$this->interventions->save(
					intervention: [
						'id' => trim((string)$this->request->getParam('id', '')),
						'plan' => $planId,
						'goal' => trim((string)$this->request->getParam('goal', '')),
						'title' => (string)$this->request->getParam('title', ''),
						'description' => (string)$this->request->getParam('description', ''),
						'provider' => (string)$this->request->getParam('provider', ''),
						'providerName' => (string)$this->request->getParam('providerName', ''),
						'startDate' => (string)$this->request->getParam('startDate', ''),
						'targetDate' => (string)$this->request->getParam('targetDate', ''),
						'state' => (string)$this->request->getParam('state', 'planned'),
						'outcome' => (string)$this->request->getParam('outcome', ''),
					]
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-plan-intervention', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-plan-intervention', e: $e);
		}
	}//end saveIntervention()

	/**
	 * Record a review of a plan.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return JSONResponse The plan with its review, or the refusal.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-plan-is-reviewed-and-the-review-is-recorded-req-cpn-03
	 */
	#[NoAdminRequired]
	public function recordReview(string $planId): JSONResponse {
		$changes = $this->request->getParam('changes', []);
		if (is_array($changes) === false) {
			$changes = [];
		}

		try {
			$status = $this->planAccess->planWrite(planId: $planId);
			if ($status !== Http::STATUS_OK) {
				return $this->denied(status: $status);
			}

			return new JSONResponse(
				$this->review->record(
					planId: $planId,
					reviewedBy: $this->planAccess->currentUid(),
					changes: $changes,
					nextReviewDate: (string)$this->request->getParam('nextReviewDate', ''),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-plan-review', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-plan-review', e: $e);
		}
	}//end recordReview()

	/**
	 * The grounds a cross-domain lookup can be made on.
	 *
	 * Its own endpoint so the dialog offers the SERVER's list rather than one
	 * written twice: a ground on screen that the service does not recognise is
	 * a choice a consulent makes and is then refused for.
	 *
	 * @return JSONResponse The grounds.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	#[NoAdminRequired]
	public function grounds(): JSONResponse {
		$grounds = [];
		foreach (CrossDomainExistence::GROUNDS as $id => $label) {
			$grounds[] = ['id' => $id, 'label' => $label];
		}

		return new JSONResponse(['grounds' => $grounds]);
	}//end grounds()

	/**
	 * Whether an open case exists in another domain, on a recorded ground.
	 *
	 * @return JSONResponse The existence answer, or the refusal.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
	 */
	#[NoAdminRequired]
	public function lookUp(): JSONResponse {
		$uid = $this->planAccess->currentUid();
		if ($uid === '') {
			return new JSONResponse(
				['message' => 'Sign in before looking a person up.', 'error' => 'not-signed-in'],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			return new JSONResponse(
				[
					'found' => $this->existence->lookUp(
						bsn: (string)$this->request->getParam('bsn', ''),
						ownDomain: (string)$this->request->getParam('domain', ''),
						ground: (string)$this->request->getParam('ground', ''),
						requester: $uid,
					),
				]
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'cross-domain-lookup', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'cross-domain-lookup', e: $e);
		}
	}//end lookUp()

	/**
	 * What was looked up about one person, with the grounds and the dates.
	 *
	 * @param string $bsn The person.
	 *
	 * @return JSONResponse The lookups.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	#[NoAdminRequired]
	public function lookupsAbout(string $bsn): JSONResponse {
		try {
			return new JSONResponse(['lookups' => $this->existence->lookupsAbout(bsn: $bsn)]);
		} catch (RefusedException $e) {
			return $this->refused(op: 'cross-domain-lookups-about', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'cross-domain-lookups-about', e: $e);
		}
	}//end lookupsAbout()

	/**
	 * The answer to a plan read or write the case check refused; nothing was read or written.
	 *
	 * @param int $status 401, 403 or 404 from FamilyPlanAccess.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	private function denied(int $status): JSONResponse {
		$message = match ($status) {
			Http::STATUS_UNAUTHORIZED => 'unauthenticated',
			Http::STATUS_NOT_FOUND => 'not_found',
			default => 'access_denied',
		};

		return new JSONResponse(['message' => $message], $status);
	}//end denied()

	/**
	 * A failure that is not a refusal, logged and answered as one.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error(
			'SociaalDomeinPlanController: ' . $op . ' failed',
			['exception' => $e->getMessage()]
		);

		return new JSONResponse(
			['message' => 'That did not work.', 'error' => 'sociaal-domein-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
