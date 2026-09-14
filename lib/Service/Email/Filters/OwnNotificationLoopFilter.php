<?php

/**
 * Dossiq Own Notification Loop Filter
 *
 * This app's own notification, delivered back into the intake folder, is the
 * start of a loop: it names a case in its subject, so it would be filed on that
 * case, which would notify, which would arrive again.
 *
 * Two signals, and the header is the reliable one. Every message this app sends
 * carries `X-Dossiq-Notification`, so a message carrying it is ours by
 * construction. The from-address comparison is the fallback for mail sent
 * before that header existed, and for a relay that stripped it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email\Filters;

use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Refuses a message this app sent itself.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class OwnNotificationLoopFilter implements InboundMailFilter {

	/**
	 * The name the intake log records.
	 */
	public const NAME = 'own-notification-loop';

	/**
	 * The header every message dossiq sends carries.
	 */
	public const NOTIFICATION_HEADER = 'X-Dossiq-Notification';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Instance configuration, for the from-address.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The name the intake log records.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function name(): string {
		return self::NAME;
	}//end name()

	/**
	 * Where this filter sits in the declared order.
	 *
	 * First. A loop is the one outcome that gets worse the longer it runs, so
	 * it is cut before any filter that has to read the body.
	 *
	 * @return integer The order.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function order(): int {
		return 10;
	}//end order()

	/**
	 * Whether this app sent this message.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict Reject when it is ours, pass otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict {
		if ($message->hasHeader(name: self::NOTIFICATION_HEADER) === true) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The message carries ' . self::NOTIFICATION_HEADER . ', so dossiq sent it.'
			);
		}

		$ourAddress = strtolower(trim(
			$this->appConfig->getValueString(Application::APP_ID, 'email_from_address', '')
		));
		if ($ourAddress === '') {
			return FilterVerdict::pass(filterName: self::NAME);
		}

		if ($message->senderAddress() === $ourAddress) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The sender is this instance\'s own from-address, so the message came back to us.'
			);
		}

		return FilterVerdict::pass(filterName: self::NAME);
	}//end decide()
}//end class
