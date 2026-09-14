<?php

/**
 * Email-to-case matching settings: each user's own, and the instance's.
 *
 * The user endpoints back the section on the personal settings page where a
 * user switches matching on for their own Nextcloud Mail account, picks which
 * account, and sees what the last run did. Nothing there reaches anybody
 * else's settings: the user id always comes from the session, never from the
 * request. The one value the request does supply is a Mail account id, and
 * that is an object reference like any other, so {@see self::saveSettings()}
 * refuses an account the caller does not own before anything is stored. The
 * matcher would otherwise scan whichever mailbox the id names.
 *
 * The instance endpoints back the switch and the pattern on the admin email
 * settings page. They sit here, not in EmailTemplateController, because they
 * belong to this feature and because a pattern is validated before it is
 * stored: a pattern the matcher cannot use would otherwise only surface as a
 * refused run in a log nobody reads.
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
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read and write matching settings.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseEmailMatchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request         Inbound request.
	 * @param CaseEmailMatchService     $matcher         The instance toggle.
	 * @param CaseEmailMatchPreferences $preferences     The caller's settings and status.
	 * @param CaseNumberRecognizer      $recognizer      Validates a pattern before it is stored.
	 * @param MailMessageSource         $messages        The caller's Mail accounts.
	 * @param SettingsService           $settingsService The instance settings.
	 * @param IUserSession              $userSession     The session.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseEmailMatchService $matcher,
		private readonly CaseEmailMatchPreferences $preferences,
		private readonly CaseNumberRecognizer $recognizer,
		private readonly MailMessageSource $messages,
		private readonly SettingsService $settingsService,
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
	 * Save the caller's settings: `enabled` and `account` from the request body.
	 *
	 * @return JSONResponse The settings now stored, or 403 for an account that is not the caller's.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	#[NoAdminRequired]
	public function saveSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$enabled = (filter_var($this->request->getParam('enabled', false), FILTER_VALIDATE_BOOLEAN) === true);
		$account = (int)$this->request->getParam('account', 0);

		if ($account > 0 && $this->messages->ownsAccount(accountId: $account, userId: $userId) === false) {
			return new JSONResponse(['message' => 'That Mail account is not yours.'], Http::STATUS_FORBIDDEN);
		}

		if ($enabled === true && $account <= 0) {
			return new JSONResponse(['message' => 'Choose a Mail account first.'], Http::STATUS_BAD_REQUEST);
		}

		$this->preferences->saveUserSettings(userId: $userId, enabled: $enabled, account: $account);

		return new JSONResponse($this->describe(userId: $userId));
	}//end saveSettings()

	/**
	 * The instance toggle and the configured pattern.
	 *
	 * @return JSONResponse The instance settings.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function getInstanceSettings(): JSONResponse {
		return new JSONResponse(
			[
				CaseEmailMatchService::INSTANCE_TOGGLE_KEY => $this->settingsService->getConfigValue(CaseEmailMatchService::INSTANCE_TOGGLE_KEY, 'no'),
				CaseNumberRecognizer::PATTERN_KEY => $this->settingsService->getConfigValue(CaseNumberRecognizer::PATTERN_KEY),
			]
		);
	}//end getInstanceSettings()

	/**
	 * Save the instance toggle and the pattern.
	 *
	 * A pattern the matcher cannot use is refused with 400 and nothing is
	 * written, the toggle included. An empty pattern means the default.
	 *
	 * @return JSONResponse The instance settings now stored, or 400.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function saveInstanceSettings(): JSONResponse {
		$pattern = trim((string)$this->request->getParam(CaseNumberRecognizer::PATTERN_KEY, ''));
		if ($pattern !== '') {
			$reason = $this->recognizer->validatePattern(pattern: $pattern);
			if ($reason !== null) {
				return new JSONResponse(
					['message' => $reason, 'field' => CaseNumberRecognizer::PATTERN_KEY],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		$enabled = 'no';
		if (filter_var($this->request->getParam(CaseEmailMatchService::INSTANCE_TOGGLE_KEY, 'no'), FILTER_VALIDATE_BOOLEAN) === true) {
			$enabled = 'yes';
		}

		$this->settingsService->setConfigValue(CaseEmailMatchService::INSTANCE_TOGGLE_KEY, $enabled);
		$this->settingsService->setConfigValue(CaseNumberRecognizer::PATTERN_KEY, $pattern);

		return $this->getInstanceSettings();
	}//end saveInstanceSettings()

	/**
	 * Everything the personal section shows for one user.
	 *
	 * @param string $userId The session user.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function describe(string $userId): array {
		$settings = $this->preferences->getUserSettings(userId: $userId);

		return [
			'instanceEnabled' => $this->matcher->isInstanceEnabled(),
			'enabled' => $settings['enabled'],
			'account' => $settings['account'],
			'accounts' => $this->messages->accountsOf(userId: $userId),
			'status' => $this->preferences->getStatus(userId: $userId),
		];
	}//end describe()
}//end class
