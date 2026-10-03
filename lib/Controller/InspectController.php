<?php

/**
 * Dossiq InspectController.
 *
 * Answers one question the manifest cannot answer for itself: is the person
 * reading this page an administrator?
 *
 * 🔴 WHY AN ENDPOINT AND NOT A FLAG ON THE ACTION. A manifest action is a
 * CLOSED object in the nextcloud-vue schema and its vocabulary has no
 * `adminOnly`. `visibleWhen` has three modes, and only its `endpoint` mode can
 * ask about the READER: the local mode dot-paths into the case record and the
 * source mode queries OpenRegister objects, and neither knows who is looking.
 * So the Inspect action gates on this, which is the smallest endpoint that
 * answers it.
 *
 * 🔴 THIS IS AN AFFORDANCE, NOT A PERMISSION. Hiding a menu entry is not a
 * control, and nothing here pretends otherwise: both entries open OpenRegister
 * pages, and OpenRegister refuses a non-admin on its own. What this prevents
 * is a handler being offered a door that will be shut in their face.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Whether the reader may be offered the raw inspection surfaces.
 *
 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
 */
class InspectController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Who is asking.
	 * @param IGroupManager $groupManager Whether they are an administrator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the signed-in reader is an administrator.
	 *
	 * 🔴 ANSWERS FALSE FOR AN ANONYMOUS READER RATHER THAN 401. The caller is
	 * a visibility predicate, and nextcloud-vue treats a predicate that errors
	 * as "hide". Both readings hide the entry, but a 401 in the network log of
	 * every public page reads as a bug somebody has to rule out, and this is
	 * not one: nobody is signed in, so nobody is an admin.
	 *
	 * @return JSONResponse `{isAdmin: bool}`.
	 *
	 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md#requirement-an-admin-inspects-a-case-from-its-page-req-cm-36
	 */
	#[NoAdminRequired]
	public function availability(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['isAdmin' => false], Http::STATUS_OK);
		}

		return new JSONResponse(
			['isAdmin' => $this->groupManager->isAdmin($user->getUID())],
			Http::STATUS_OK
		);
	}//end availability()
}//end class
