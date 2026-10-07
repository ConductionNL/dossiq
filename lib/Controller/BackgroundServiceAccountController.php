<?php

/**
 * Dossiq BackgroundServiceAccountController.
 *
 * Reads and picks the background service account: the Nextcloud account the
 * background jobs (the termijn reminder sweep) write as. Admin only, twice:
 * Nextcloud refuses a non-admin before the method runs (no NoAdminRequired),
 * and the body checks again, because the choice decides who every
 * background write runs as.
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and picks the background service account.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class BackgroundServiceAccountController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request        The request.
	 * @param BackgroundServiceAccount $serviceAccount The account the background jobs write as.
	 * @param IUserSession             $userSession    The caller.
	 * @param IGroupManager            $groupManager   Answers whether the caller is an admin.
	 */
	public function __construct(
		IRequest $request,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Which account the background jobs write as, and whether it can be used.
	 *
	 * @auth admin-only Names the account every background write runs as; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse `{userId, usable, reason, group}`, or 403.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function show(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->body(status: $this->serviceAccount->status()), Http::STATUS_OK);
	}//end show()

	/**
	 * Pick the account the background jobs write as. It must exist and be
	 * enabled; it joins the background service group.
	 *
	 * @auth admin-only Chooses the identity every background write runs as; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse The new status, 400 when the account cannot be used, or 403.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function save(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$status = $this->serviceAccount->assign(userId: (string)$this->request->getParam('userId', ''));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['errorCode' => 'badRequest', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->body(status: $status), Http::STATUS_OK);
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
	 * The status with the group the account must be in.
	 *
	 * @param array{userId: string, usable: bool, reason: string|null} $status The status.
	 *
	 * @return array<string, mixed> The response body.
	 */
	private function body(array $status): array {
		return array_merge($status, ['group' => BackgroundServiceAccount::GROUP]);
	}//end body()
}//end class
