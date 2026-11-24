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
