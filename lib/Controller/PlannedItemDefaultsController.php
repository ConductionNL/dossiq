<?php

/**
 * Dossiq PlannedItemDefaultsController.
 *
 * What a new planned item on the caller's own queue opens with. It answers for
 * the caller and takes no user parameter, like the personal queue it serves.
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
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Queue\PlannedItemDefaults;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The defaults of a new planned item.
 *
 * @spec openspec/specs/my-work/spec.md
 */
class PlannedItemDefaultsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param IUserSession        $userSession The caller.
	 * @param PlannedItemDefaults $defaults    What a new planned item opens with.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PlannedItemDefaults $defaults,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * What a new planned item opens with: the next working day at 10:00.
	 *
	 * @return JSONResponse The defaults.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->defaults->startsAt());
	}//end show()
}//end class
