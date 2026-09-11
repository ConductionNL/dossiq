<?php

/**
 * A user's own email-to-case matching settings.
 *
 * Backs the section on the personal settings page where a user switches
 * matching on for their own Nextcloud Mail account, picks which account, and
 * sees what the last run did. Nothing here reaches anybody else's settings: the
 * user id always comes from the session, never from the request.
 *
 * The one value the request does supply is a Mail account id, and that is an
 * object reference like any other. {@see self::saveSettings()} refuses an
 * account the caller does not own before anything is stored, because the
 * matcher would otherwise scan whichever mailbox the id names.
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseEmailMatchService;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read and write the caller's own matching settings.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseEmailMatchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request     Inbound request.
	 * @param CaseEmailMatchService $matcher     Settings and status.
	 * @param MailMessageSource     $messages    The caller's Mail accounts.
	 * @param IUserSession          $userSession The session.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseEmailMatchService $matcher,
		private readonly MailMessageSource $messages,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's settings, their Mail accounts, and their last run.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @no-admin-idor-exempt Reads only the caller's own preferences and Mail accounts, keyed by the session user; it takes no object id.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	#[NoAdminRequired]
	public function getSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->describe(userId: $user->getUID()));
	}//end getSettings()

	/**
	 * Save the caller's settings.
	 *
	 * @param bool $enabled Whether to match the caller's mail.
	 * @param int  $account The Mail account to match, 0 for none.
	 *
	 * @return JSONResponse The settings now stored, or 403 for an account that is not the caller's.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	#[NoAdminRequired]
	public function saveSettings(bool $enabled = false, int $account = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		if ($account > 0 && $this->messages->ownsAccount(accountId: $account, userId: $userId) === false) {
			return new JSONResponse(['message' => 'That Mail account is not yours.'], Http::STATUS_FORBIDDEN);
		}

		if ($enabled === true && $account <= 0) {
			return new JSONResponse(['message' => 'Choose a Mail account first.'], Http::STATUS_BAD_REQUEST);
		}

		$this->matcher->saveUserSettings(userId: $userId, enabled: $enabled, account: $account);

		return new JSONResponse($this->describe(userId: $userId));
	}//end saveSettings()

	/**
	 * Everything the settings section shows for one user.
	 *
	 * @param string $userId The session user.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function describe(string $userId): array {
		$settings = $this->matcher->getUserSettings(userId: $userId);

		return [
			'instanceEnabled' => $this->matcher->isInstanceEnabled(),
			'enabled' => $settings['enabled'],
			'account' => $settings['account'],
			'accounts' => $this->messages->accountsOf(userId: $userId),
			'status' => $this->matcher->getStatus(userId: $userId),
		];
	}//end describe()
}//end class
