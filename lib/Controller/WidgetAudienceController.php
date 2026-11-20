<?php

/**
 * Dossiq WidgetAudienceController.
 *
 * Reads and stores which groups may see each dashboard widget dossiq
 * contributes. Admin only, twice: Nextcloud refuses a non-admin before the
 * method runs (no NoAdminRequired), and the body checks again, so a
 * non-administrator's write is refused on the server whatever the page shows.
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
 * @link https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Dashboard\WidgetAudience;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and stores the audience per contributed dashboard widget.
 *
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */
class WidgetAudienceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request      The request.
	 * @param WidgetAudience $audience     Reads and stores the groups per widget.
	 * @param IUserSession   $userSession  The caller.
	 * @param IGroupManager  $groupManager Answers whether the caller is an admin.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly WidgetAudience $audience,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every contributed widget with the groups that may see it.
	 *
	 * @auth admin-only The audience decides who sees which case widget; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse Comma-separated group ids by widget id, or 403.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#requirement-req-dwv-004-an-administrator-sets-the-audience-per-widget
	 */
	public function show(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->body(audiences: $this->audience->all()), Http::STATUS_OK);
	}//end show()

	/**
	 * Store the groups per widget from the request; a widget id the app does not ship is ignored.
	 *
	 * @auth admin-only Narrows or widens who sees a case widget; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse The stored audience, or 403.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#requirement-req-dwv-004-an-administrator-sets-the-audience-per-widget
	 */
	public function save(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$saved = $this->audience->save(audiences: $this->request->getParams());

		return new JSONResponse($this->body(audiences: $saved), Http::STATUS_OK);
	}//end save()

	/**
	 * Whether the caller is a Nextcloud admin.
	 *
	 * @return bool True for an admin.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID()) === true;
	}//end isAdmin()

	/**
	 * The audience as one comma-separated text per widget, the shape a settings text field reads.
	 *
	 * @param array<string, list<string>> $audiences Group ids by widget id.
	 *
	 * @return array<string, string> Comma-separated group ids by widget id.
	 */
	private function body(array $audiences): array {
		return array_map(static fn (array $groups): string => implode(', ', $groups), $audiences);
	}//end body()
}//end class
