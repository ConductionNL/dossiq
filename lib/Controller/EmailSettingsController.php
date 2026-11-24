<?php

/**
 * Dossiq Email Settings Controller
 *
 * REST surface for the mail settings form: the account and folder intake reads,
 * the poller, the fallback case type, the sender block list and the junk rules,
 * and the accounts Nextcloud Mail offers. Split from EmailTemplateController,
 * which keeps the templates.
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
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\MailIntakeSettings;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the mail settings form (admin only).
 *
 * The block list is written through the class that reads it rather than
 * straight to appconfig, so the class that reads it is the class that
 * normalises it.
 *
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class EmailSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     Inbound request.
	 * @param IUserSession       $userSession Current user session.
	 * @param MailIntakeSettings $settings    The settings keys, the block list and the Mail accounts.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly MailIntakeSettings $settings,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read the masked IMAP / poller settings.
	 *
	 * ADMIN-ONLY. These are instance-wide connection settings for the shared
	 * case mailbox, not per-user preferences: host, port, username, folder and
	 * the poller cadence. Even with the password masked, the host/username pair
	 * describes the municipality's mail infrastructure, so it follows the same
	 * posture as `createTemplate()` above — `#[AuthorizedAdminSetting]` and no
	 * `@NoAdminRequired` beside it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function getSettings(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->settings->values());
	}//end getSettings()

	/**
	 * Persist IMAP / poller settings.
	 *
	 * None of these is a credential any more. Nextcloud Mail holds the account
	 * and the password, so this surface stores which account intake reads,
	 * which folder, and the policy values around it.
	 *
	 * ADMIN-ONLY. Under `@NoAdminRequired` this wrote INSTANCE-WIDE app config
	 * — `email_imap_host`, `email_imap_user` and the shared-mailbox password —
	 * behind nothing but a "is anyone logged in" check, so any authenticated
	 * account could repoint the municipality's case mailbox at a host it
	 * controls. It also armed `testImap()` below, which dialled whatever host was
	 * stored and reports reachability, into an internal port prober. There is
	 * no per-object guard to add here: the object IS the instance settings, so
	 * the correct posture is the admin attribute this controller already uses
	 * for `createTemplate()` / `updateTemplate()`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function saveSettings(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$params = [];
		foreach ($this->settings->keys() as $key) {
			$params[$key] = $this->request->getParam($key);
		}

		$this->settings->save(params: $params);

		return new JSONResponse(['saved' => true]);
	}//end saveSettings()

	/**
	 * The Nextcloud Mail accounts an administrator can point intake at.
	 *
	 * 🔴 THIS REPLACED A PORT PROBER. The endpoint used to open a TCP socket to
	 * whatever host was stored and report whether the connect succeeded, which
	 * on an instance-wide setting is a reachability oracle for any address an
	 * administrator can type. There is no host to dial any more: Nextcloud Mail
	 * holds the connection, and the only thing this surface needs is the list of
	 * accounts it can choose between.
	 *
	 * ADMIN-ONLY, like the settings it feeds. The list names the functional
	 * mailboxes of the whole instance, which is not something every
	 * authenticated user should be able to enumerate.
	 *
	 * @return JSONResponse The accounts, or an empty list when Mail is absent.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function mailAccounts(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->settings->accounts());
	}//end mailAccounts()
}//end class
