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

use Closure;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Dashboard\WidgetAudience;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Answers and stores the groups per dashboard widget, for an administrator only.
 *
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */
class WidgetAudienceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request  The request.
	 * @param WidgetAudience $audience Reads and stores the groups per widget.
	 * @param IUserSession   $session  The caller.
	 * @param IGroupManager  $groups   Answers whether the caller is an admin.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly WidgetAudience $audience,
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
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
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function show(): JSONResponse {
		return $this->forAdmin(audiences: fn (): array => $this->audience->all());
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
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function save(): JSONResponse {
		return $this->forAdmin(audiences: fn (): array => $this->audience->save(audiences: $this->request->getParams()));
	}//end save()

	/**
	 * The audience as one comma-separated text per widget, the shape a settings
	 * text field reads; 403 before anything is read or stored unless the caller
	 * is a Nextcloud administrator.
	 *
	 * @param Closure(): array<string, list<string>> $audiences Reads or stores the audience.
	 *
	 * @return JSONResponse The audience, or 403.
	 */
	private function forAdmin(Closure $audiences): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null || $this->groups->isAdmin($uid) === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$texts = array_map(static fn (array $groups): string => implode(', ', $groups), $audiences());

		return new JSONResponse($texts, Http::STATUS_OK);
	}//end forAdmin()
}//end class
