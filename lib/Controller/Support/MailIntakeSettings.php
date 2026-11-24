<?php

/**
 * Dossiq mail intake settings.
 *
 * What the mail settings form reads and writes: the appconfig keys of mail
 * intake, the sender block list written through the class that reads it, and
 * the Nextcloud Mail accounts intake can read, with the mailbox status the
 * settings card shows.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\EmailTemplateServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SenderBlocklistInterface;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCP\IAppConfig;

/**
 * The mail intake settings behind EmailSettingsController.
 *
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class MailIntakeSettings {

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
	 * @param IAppConfig                    $appConfig       App config (IMAP settings).
	 * @param SenderBlocklistInterface      $blocklist       Who may not open a case by mail, normalised as it is stored.
	 * @param MailGatewayInterface          $mailGateway     The mail gateway (the accounts intake can read).
	 * @param EmailTemplateServiceInterface $templateService Records the mailbox status the settings card shows.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SenderBlocklistInterface $blocklist,
		private readonly MailGatewayInterface $mailGateway,
		private readonly EmailTemplateServiceInterface $templateService,
	) {
	}//end __construct()

	/**
	 * The settings keys the form reads and writes.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function keys(): array {
		return self::IMAP_KEYS;
	}//end keys()

	/**
	 * Every settings key with its stored value, empty when unset.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 */
	public function values(): array {
		$values = [];
		foreach (self::IMAP_KEYS as $key) {
			$values[$key] = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		}

		return $values;
	}//end values()

	/**
	 * Store the settings keys the request named; a key it left out (null) keeps its value.
	 *
	 * @param array<string, mixed> $params The request's value per settings key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 */
	public function save(array $params): void {
		foreach (self::IMAP_KEYS as $key) {
			$value = ($params[$key] ?? null);
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
	}//end save()

	/**
	 * The Nextcloud Mail accounts intake can read, recording the mailbox status they amount to.
	 *
	 * @return array{available: bool, accounts: array<mixed>}
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 */
	public function accounts(): array {
		if ($this->mailGateway->isAvailable() === false) {
			$this->templateService->recordMailboxStatus(
				status: 'unconfigured',
				message: 'The Mail app is not installed, so intake is unavailable'
			);
			return ['available' => false, 'accounts' => []];
		}

		$accounts = $this->mailGateway->accounts();
		$status = 'unconfigured';
		$message = 'No mail account is available to read';
		if ($accounts !== []) {
			$status = 'configured';
			$message = 'Nextcloud Mail holds ' . count($accounts) . ' account(s) intake can read';
		}

		$this->templateService->recordMailboxStatus(status: $status, message: $message);

		return ['available' => true, 'accounts' => $accounts];
	}//end accounts()
}//end class
