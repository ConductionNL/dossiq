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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseType\CaseTypePhases;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers a case type's phases and their tasks.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypePhasesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string         $appName The app name.
	 * @param IRequest       $request The request.
	 * @param CaseTypePhases $phases  Groups the workflow steps under the statuses.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTypePhases $phases,
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
	 * @spec openspec/specs/case-types/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$phases = $this->phases->phasesFor(caseTypeId: $id);
		if ($phases === null) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($phases);
	}//end index()
}//end class
