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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\EmailTemplateServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SenderBlocklistInterface;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
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
	 * IMAP/poller config keys handled here.
	 *
	 * @var array<int, string>
	 */
	private const IMAP_KEYS = [
		// 🔴 THE HOST, USERNAME AND PASSWORD ARE GONE. Nextcloud Mail owns the
		// account and the credential; this surface picks one of its accounts by
		// id and names the folder intake reads. A form that still offered a
		// password field would keep writing one into appconfig, which is why the
		// keys leave here in the same change that deletes the stored values.
		'email_mail_account_id',
		'email_imap_folder',
		'email_transport',
		'email_poll_interval',
		'email_poll_batch_size',
		'email_max_attachment_size',
		// The case type an unmatched mail becomes a case of. Empty means the
		// mail stays in the mailbox, which is the default and the behaviour
		// every instance had before this key existed.
		'email_fallback_case_type',
		// Who may open a case by mail, the readable junk rules, and the group
		// that reads the intake log.
		'email_intake_blocklist',
		'email_intake_junk_rules',
		'email_intake_role',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param EmailTemplateServiceInterface $templateService Records the mailbox status the settings card shows.
	 * @param IAppConfig $appConfig App config (IMAP settings).
	 * @param IUserSession $userSession Current user session.
	 * @param MailGatewayInterface $mailGateway The mail gateway (the accounts intake can read).
	 * @param SenderBlocklistInterface $blocklist Who may not open a case by mail, normalised as it is stored.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		IRequest $request,
		private readonly EmailTemplateServiceInterface $templateService,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly MailGatewayInterface $mailGateway,
		private readonly SenderBlocklistInterface $blocklist,
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

		$values = [];
		foreach (self::IMAP_KEYS as $key) {
			$values[$key] = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		}

		return new JSONResponse($values);
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

		foreach (self::IMAP_KEYS as $key) {
			$value = $this->request->getParam($key);
			if ($value === null) {
				continue;
			}

			// 🔴 THE BLOCK LIST IS WRITTEN BY THE CLASS THAT READS IT. `blocks()`
			// compares an entry against a normalised address, so a list stored
			// as an administrator typed it — `Spammer@Voorbeeld.NL `, with the
			// capitals and the trailing space — matches nothing and the surface
			// still shows the address as blocked. Normalising on the way in is
			// the only place that can be true for every reader.
			if ($key === SenderBlocklistInterface::BLOCKLIST_KEY) {
				$this->blocklist->replace(entries: explode(',', (string)$value));
				continue;
			}

			// 🔴 NOTHING HERE IS SENSITIVE ANY MORE, AND THE MASKING IS GONE WITH
			// IT. This surface held exactly one secret, the shared-mailbox
			// password, and Nextcloud Mail holds the account now. The `***`
			// placeholder that meant "unchanged" went with it: a form that still
			// understood it would accept three asterisks as a value for a key
			// that is no longer a credential.
			$this->appConfig->setValueString(AppIdentity::APP_ID, $key, (string)$value);
		}//end foreach

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

		if ($this->mailGateway->isAvailable() === false) {
			$this->templateService->recordMailboxStatus(
				status: 'unconfigured',
				message: 'The Mail app is not installed, so intake is unavailable'
			);
			return new JSONResponse(['available' => false, 'accounts' => []]);
		}

		$accounts = $this->mailGateway->accounts();
		$status = 'unconfigured';
		$message = 'No mail account is available to read';
		if ($accounts !== []) {
			$status = 'configured';
			$message = 'Nextcloud Mail holds ' . count($accounts) . ' account(s) intake can read';
		}

		$this->templateService->recordMailboxStatus(status: $status, message: $message);

		return new JSONResponse(['available' => true, 'accounts' => $accounts]);
	}//end mailAccounts()

}//end class
