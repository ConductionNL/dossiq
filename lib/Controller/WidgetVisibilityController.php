<?php

/**
 * Which declared widgets the signed-in reader is shown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The layout half of `widget-roles-declared`.
 *
 * 🔑 WHAT THIS CLOSES. `widget-roles-declared` made 14 widgets declare their
 * readers and made the DATA obey: `DashboardWidgetScope::narrowFor()` takes the
 * figures out of the KPI payload, and the endpoints behind the other thirteen
 * refuse outright (`ReportingAudience`, `ProcessMiningController`). What no
 * layer did was take the TILE off the page, because no component in
 * `@conduction/nextcloud-vue` reads a widget's `roles` key. Measured 2026-09-18
 * by grepping the installed 3.2.0 for a reader of it: there is none, in
 * CnDashboardPage, in CnDetailPage or anywhere else under `src/components`.
 *
 * So a reader who may not see a tile got the tile, drawn over a payload that
 * correctly carried none of its numbers. An empty box where a figure should be
 * is itself information, and REQ-WRD-02 says the page is laid out without it.
 *
 * 🔴 THIS IS NOT THE ENFORCEMENT, AND MUST NOT BECOME IT. ADR-004's hard rule
 * is that a frontend decision is not an access check. The check is on the read,
 * where it already is. This answers a question about the CALLER -- which tiles
 * would you be shown -- so it discloses nothing, and a caller who lies to it
 * gains a tile that answers them 403.
 *
 * WHY `visibleWhen` AND NOT A NEW LIBRARY FEATURE. `CnDashboardPage` already
 * honours `visibleWhen` with an `endpoint`, fetches it, reads a dot-path out of
 * the answer, and collapses the cell when the fetch or the shape fails
 * (`utils/visibleWhen.js`). That is fail-closed and it ships today. A second
 * mechanism in the browser would be a second answer to one question.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */
class WidgetVisibilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName     The app name.
	 * @param IRequest             $request     The request.
	 * @param DashboardWidgetScope $scope       The one place the verdict is decided.
	 * @param IUserSession         $userSession The session.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DashboardWidgetScope $scope,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The widgets this reader is shown.
	 *
	 * One map rather than a route per widget: `visibleWhen` fetches per
	 * condition and reads a dot-path into the response, so every tile on a page
	 * names `visible.<its id>` and shares one cacheable answer instead of
	 * costing a round trip each.
	 *
	 * @return JSONResponse `{"visible": {"<widgetId>": bool}}`.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['visible' => $this->scope->visibilityFor(userId: $user->getUID())]);
	}//end index()
}//end class
