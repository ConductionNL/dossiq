<?php

/**
 * Dossiq DeadlineInstanceLegacyPathController.
 *
 * The deprecated `/api/termijn/instances*` addresses of the term-instance
 * lifecycle. Each action answers exactly as its counterpart on
 * {@see TermijnController} under `/api/deadlines/instances*`.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The term-instance lifecycle on its old addresses.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class DeadlineInstanceLegacyPathController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string             $appName App id.
	 * @param IRequest           $request Request.
	 * @param TermijnController  $termijn The controller on the current addresses.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TermijnController $termijn,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * TermijnController::create() on its old address.
	 *
	 * Deprecated alias of POST /api/deadlines/instances; remove after the next major.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 *
	 * @contract exclude Deprecated path alias for create(); behaviour covered by the create() tests.
	 */
	public function create(): JSONResponse {
		return $this->termijn->create();
	}//end create()

	/**
	 * TermijnController::show() on its old address.
	 *
	 * Deprecated alias of GET /api/deadlines/instances/{id}; remove after the next major.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 *
	 * @contract exclude Deprecated path alias for show(); behaviour covered by the show() tests.
	 */
	public function show(string $id): JSONResponse {
		return $this->termijn->show(id: $id);
	}//end show()

	/**
	 * TermijnController::pauze() on its old address.
	 *
	 * Deprecated alias of /api/deadlines/instances/{id}/pause; remove after the next major.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 *
	 * @contract exclude Deprecated path alias for pauze(); behaviour covered by the pauze() tests.
	 */
	public function pauze(string $id): JSONResponse {
		return $this->termijn->pauze(id: $id);
	}//end pauze()

	/**
	 * TermijnController::hervat() on its old address.
	 *
	 * Deprecated alias of /api/deadlines/instances/{id}/resume; remove after the next major.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 *
	 * @contract exclude Deprecated path alias for hervat(); behaviour covered by the hervat() tests.
	 */
	public function hervat(string $id): JSONResponse {
		return $this->termijn->hervat(id: $id);
	}//end hervat()

	/**
	 * TermijnController::verleng() on its old address.
	 *
	 * Deprecated alias of /api/deadlines/instances/{id}/extend; remove after the next major.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 *
	 * @contract exclude Deprecated path alias for verleng(); behaviour covered by the verleng() tests.
	 */
	public function verleng(string $id): JSONResponse {
		return $this->termijn->verleng(id: $id);
	}//end verleng()

	/**
	 * TermijnController::voltooi() on its old address.
	 *
	 * Deprecated alias of /api/deadlines/instances/{id}/complete; remove after the next major.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 *
	 * @contract exclude Deprecated path alias for voltooi(); behaviour covered by the voltooi() tests.
	 */
	public function voltooi(string $id): JSONResponse {
		return $this->termijn->voltooi(id: $id);
	}//end voltooi()
}//end class
