<?php

/**
 * A supervision plan's progress and a proposed schedule, read only.
 *
 * Both answers are read with the reader's own access (SupervisionPlanReader).
 * Neither endpoint writes: "Suggest a schedule" shows its proposal first, and
 * planning the inspections it proposes is a separate, deliberate act.
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
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use OCA\Dossiq\Service\Inspection\SupervisionPlanReader;
use OCA\Dossiq\Service\Inspection\SupervisionScheduleProposal;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read a supervision plan's figures and propose its schedule.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */
class SupervisionPlanController extends Controller {

	/**
	 * The sentence per refusal status.
	 */
	private const REFUSALS = [
		SupervisionPlanReader::NOT_FOUND    => 'Supervision plan not found',
		SupervisionPlanReader::BROKEN       => 'The supervision plan could not be read',
		SupervisionPlanReader::UNCONFIGURED => 'The supervision plan schemas are not configured',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  The request.
	 * @param SupervisionPlanReader       $reader   Reads the plan in the reader's scope.
	 * @param SupervisionPlanProgress     $progress Counts the plan's inspections.
	 * @param SupervisionScheduleProposal $proposal Proposes the schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function __construct(
		IRequest $request,
		private readonly SupervisionPlanReader $reader,
		private readonly SupervisionPlanProgress $progress,
		private readonly SupervisionScheduleProposal $proposal,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The plan's figures: totals, per category and per quarter.
	 *
	 * @param string $id The supervisionPlan uuid.
	 *
	 * @no-admin-idor-exempt Every read runs in the caller's own OpenRegister scope: SupervisionPlanReader finds the plan
	 *                        and searches its categories and inspections with RBAC and multitenancy on (their defaults), so
	 *                        a plan the caller may not read answers 404 and an inspection they may not see is not counted.
	 *                        It writes nothing.
	 *
	 * @return JSONResponse The figures, or the refusal.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function progress(string $id): JSONResponse {
		$read = $this->reader->read(id: $id, withInspections: true);
		if ($read['status'] !== SupervisionPlanReader::FOUND) {
			return $this->refused(status: $read['status']);
		}

		$figures = $this->progress->measure(
			plan: ($read['plan'] ?? []),
			categories: ($read['categories'] ?? []),
			inspections: ($read['inspections'] ?? [])
		);

		return new JSONResponse($figures);
	}//end progress()

	/**
	 * The figures of the plan for a year, so the plan page opens on its year.
	 *
	 * @param string $year The plan year, four digits.
	 *
	 * @no-admin-idor-exempt The plan is looked up by year in the caller's own OpenRegister scope (RBAC and multitenancy
	 *                        on), so a plan the caller may not read answers 404, and its figures are read as progress()
	 *                        reads them. It writes nothing.
	 *
	 * @return JSONResponse The figures, or the refusal.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-2
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function progressForYear(string $year): JSONResponse {
		$found = $this->planOfYear(year: $year);
		if ($found['status'] !== SupervisionPlanReader::FOUND) {
			return $this->refused(status: $found['status']);
		}

		return $this->progress(id: (string)($found['id'] ?? ''));
	}//end progressForYear()

	/**
	 * A proposed schedule for the plan; nothing is planned.
	 *
	 * The optional `from` (Y-m-d) is the first day a date may fall on; today
	 * when it is absent or not a date.
	 *
	 * @param string $id The supervisionPlan uuid.
	 *
	 * @no-admin-idor-exempt Every read runs in the caller's own OpenRegister scope: SupervisionPlanReader finds the plan
	 *                        and searches its categories and inspections with RBAC and multitenancy on (their defaults), so
	 *                        a plan the caller may not read answers 404 and an inspection they may not see is not counted.
	 *                        It writes nothing.
	 *
	 * @return JSONResponse The proposal, or the refusal.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function scheduleProposal(string $id): JSONResponse {
		$read = $this->reader->read(id: $id, withInspections: false);
		if ($read['status'] !== SupervisionPlanReader::FOUND) {
			return $this->refused(status: $read['status']);
		}

		$from = (string)$this->request->getParam('from', '');

		$proposal = $this->proposal->propose(plan: ($read['plan'] ?? []), categories: ($read['categories'] ?? []), from: $from);

		return new JSONResponse($proposal);
	}//end scheduleProposal()

	/**
	 * The proposed schedule of the plan for a year, so the plan page shows it beside the year's figures.
	 *
	 * @param string $year The plan year, four digits.
	 *
	 * @no-admin-idor-exempt The plan is looked up by year in the caller's own OpenRegister scope (RBAC and multitenancy
	 *                        on), so a plan the caller may not read answers 404, and the proposal is made as
	 *                        scheduleProposal() makes it. It writes nothing.
	 *
	 * @return JSONResponse The proposal, or the refusal.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function scheduleProposalForYear(string $year): JSONResponse {
		$found = $this->planOfYear(year: $year);
		if ($found['status'] !== SupervisionPlanReader::FOUND) {
			return $this->refused(status: $found['status']);
		}

		return $this->scheduleProposal(id: (string)($found['id'] ?? ''));
	}//end scheduleProposalForYear()

	/**
	 * The plan of a year as the reader finds it; a value that is not four digits is not found.
	 *
	 * @param string $year The plan year from the route.
	 *
	 * @return array<string, mixed> The reader's answer: `status`, and `id` when found.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	private function planOfYear(string $year): array {
		if (preg_match('/^\d{4}$/', $year) !== 1) {
			return ['status' => SupervisionPlanReader::NOT_FOUND];
		}

		return $this->reader->idForYear(year: (int)$year);
	}//end planOfYear()

	/**
	 * The refusal for a status the reader answered.
	 *
	 * @param int $status The status.
	 *
	 * @return JSONResponse The error.
	 */
	private function refused(int $status): JSONResponse {
		return new JSONResponse(['error' => (self::REFUSALS[$status] ?? self::REFUSALS[SupervisionPlanReader::BROKEN])], $status);
	}//end refused()
}//end class
