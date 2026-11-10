<?php

/**
 * Dossiq OutboxSend.
 *
 * One message handed to a Nextcloud Mail account's outbox while it is sent:
 * the account, Mail's outbox service, and the outbox row once it is saved.
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * The state of one outbox send, built and read by NextcloudMailGateway only.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class OutboxSend {
	/**
	 * Mail's account.
	 *
	 * @var object
	 */
	public readonly object $account;

	/**
	 * Mail's outbox service.
	 *
	 * @var object
	 */
	public readonly object $outbox;

	/**
	 * The Mail account id.
	 *
	 * @var integer
	 */
	public int $accountId = 0;

	/**
	 * The saved outbox row; set once Mail took the message.
	 *
	 * @var object|null
	 */
	public ?object $saved = null;

	/**
	 * The outbox row's id, or null when Mail gave none.
	 *
	 * @var integer|null
	 */
	public ?int $outboxId = null;

	/**
	 * Start a send through an account's outbox.
	 *
	 * @param object $account Mail's account.
	 * @param object $outbox  Mail's outbox service.
	 */
	public function __construct(object $account, object $outbox) {
		$this->account = $account;
		$this->outbox = $outbox;
	}//end __construct()
}//end class
