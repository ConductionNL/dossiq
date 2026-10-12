<?php

/**
 * Dossiq Mail Transport Policy
 *
 * Which transport each kind of outbound mail leaves through, as configuration.
 *
 * Decision 165 splits outbound mail by kind: a case mail a handler writes
 * leaves through the Nextcloud Mail account (filed in its sent folder), while
 * term notices and other service mail leave through Nextcloud's IMailer,
 * because they carry the RFC 8058 `List-Unsubscribe` headers and Nextcloud
 * Mail takes no custom header. Decision 182 makes that split configuration,
 * not code: the map lives in app config under `mail_transport_by_kind`, and
 * these defaults apply to any kind it does not name. When Nextcloud Mail
 * accepts headers (decision 147), flipping a kind to `mail-account` is a
 * configuration change.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the kind-to-transport map, with decision 165's defaults.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */
class MailTransportPolicy {

	/**
	 * The app-config key holding the JSON map of kind to transport.
	 */
	public const CONFIG_KEY = 'mail_transport_by_kind';

	/**
	 * A message a handler writes about a case.
	 */
	public const KIND_CASE_MAIL = 'case-mail';

	/**
	 * A notice a case type's trigger sends (a term notice, an extension notice, a decision notice).
	 */
	public const KIND_NOTICE = 'notice';

	/**
	 * Any other service mail.
	 */
	public const KIND_SERVICE = 'service';

	/**
	 * Through the Nextcloud Mail account: filed in its sent folder, no custom headers.
	 */
	public const TRANSPORT_MAIL_ACCOUNT = 'mail-account';

	/**
	 * Through Nextcloud's IMailer: carries the RFC 8058 headers, not filed in a mailbox.
	 */
	public const TRANSPORT_IMAILER = 'imailer';

	/**
	 * Decision 165, for every kind the configuration does not name.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULTS = [
		self::KIND_CASE_MAIL => self::TRANSPORT_MAIL_ACCOUNT,
		self::KIND_NOTICE => self::TRANSPORT_IMAILER,
		self::KIND_SERVICE => self::TRANSPORT_IMAILER,
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The transport a kind of mail leaves through.
	 *
	 * A configured value that is not a known transport is ignored, so a typo
	 * keeps the default rather than sending nothing.
	 *
	 * @param string $kind One of the KIND_ constants, or a kind a case type configures.
	 *
	 * @return string TRANSPORT_MAIL_ACCOUNT or TRANSPORT_IMAILER.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function transportFor(string $kind): string {
		$configured = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''),
			true
		);
		if (is_array($configured) === true) {
			$value = ($configured[$kind] ?? null);
			if (in_array($value, [self::TRANSPORT_MAIL_ACCOUNT, self::TRANSPORT_IMAILER], true) === true) {
				return $value;
			}
		}

		return (self::DEFAULTS[$kind] ?? self::TRANSPORT_IMAILER);
	}//end transportFor()
}//end class
