<?php

/**
 * Dossiq Case Type Phases Controller.
 *
 * The phases of a case type with the tasks each runs, for the Fasen en taken
 * tab of the case type page. Its own controller because CaseTypeController is
 * at the collaborator count phpmd allows.
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
 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseType\CaseTypePhases;
use OCA\Dossiq\Service\CaseType\CaseTypePhaseSteps;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers a case type's phases and their tasks.
 *
 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
 */
class CaseTypePhasesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string         $appName The app name.
	 * @param IRequest       $request The request.
	 * @param CaseTypePhases $phases  Groups the workflow steps under the statuses.
	 * @param CaseTypePhaseSteps $steps Adds a task to a phase of the draft workflow.
	 *
	 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTypePhases $phases,
		private readonly CaseTypePhaseSteps $steps,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The phases of a case type, each with its tasks, and the acts available in
	 * every phase.
	 *
	 * `#[NoAdminRequired]` with no body guard, as the blueprint read beside it:
	 * this READS a case type, which ordinary users do on the case page, and
	 * OpenRegister applies its own read authority underneath. Nothing is written.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse The phases, or 404 when the case type cannot be read.
	 *
	 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$phases = $this->phases->phasesFor(caseTypeId: $id);
		if ($phases === null) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($phases);
	}//end index()

	/**
	 * Add a task to a phase of the case type's draft workflow definition.
	 *
	 * Admin-only: a task is configuration of the case type, as the admin settings
	 * that edit the workflow are. The task runs once the draft is published, so a
	 * case type without a draft is refused with 409 and asked for a new version.
	 *
	 * @param string $id    The case type id.
	 * @param string $phase The status the task runs in.
	 *
	 * @return JSONResponse 201 with the step and the draft's id and version; 400
	 *                      without a title, 404 for a phase the type does not
	 *                      have, 409 without a draft, 500 when the draft was not saved.
	 *
	 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function addStep(string $id, string $phase): JSONResponse {
		$result = $this->steps->addStep(
			caseTypeId: $id,
			phaseId: $phase,
			input: [
				'title'           => $this->request->getParam('title'),
				'description'     => $this->request->getParam('description'),
				'leadTimeDays'    => $this->request->getParam('leadTimeDays'),
				'candidateGroups' => $this->request->getParam('candidateGroups'),
			]
		);

		return match ($result['outcome']) {
			CaseTypePhaseSteps::ADDED => new JSONResponse($result, Http::STATUS_CREATED),
			CaseTypePhaseSteps::NO_TITLE => new JSONResponse(['error' => 'A task needs a title'], Http::STATUS_BAD_REQUEST),
			CaseTypePhaseSteps::NO_PHASE => new JSONResponse(['error' => 'This case type has no such phase'], Http::STATUS_NOT_FOUND),
			CaseTypePhaseSteps::NO_DRAFT => new JSONResponse(
				['error' => 'This case type has no draft workflow. Start a new version to add a task.'],
				Http::STATUS_CONFLICT
			),
			default => new JSONResponse(['error' => 'The task was not saved'], Http::STATUS_INTERNAL_SERVER_ERROR),
		};
	}//end addStep()
}//end class
