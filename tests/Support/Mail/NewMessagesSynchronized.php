<?php

/**
 * A stand-in for Nextcloud Mail's new-messages event, for unit tests only.
 *
 * Nextcloud Mail is not installed in the unit-test run, and dossiq's gateway
 * recognises `OCA\Mail\Events\NewMessagesSynchronized` by name. This
 * stand-in answers the event's getters from what it was built with, so the
 * gateway's real unpacking runs. Loaded by hand from the tests that need it,
 * never by the autoloader, so no other test sees a Mail class that is not
 * there.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Mail\Events;

/**
 * The account, mailbox and messages of one synchronisation.
 */
class NewMessagesSynchronized {

	/**
	 * Constructor.
	 *
	 * @param mixed $account  What getAccount() answers, or a Throwable it throws.
	 * @param mixed $mailbox  What getMailbox() answers.
	 * @param mixed $messages What getMessages() answers.
	 */
	public function __construct(
		private mixed $account,
		private mixed $mailbox,
		private mixed $messages,
	) {
	}//end __construct()

	/**
	 * The account.
	 *
	 * @return mixed
	 */
	public function getAccount(): mixed {
		if ($this->account instanceof \Throwable) {
			throw $this->account;
		}

		return $this->account;
	}//end getAccount()

	/**
	 * The mailbox.
	 *
	 * @return mixed
	 */
	public function getMailbox(): mixed {
		return $this->mailbox;
	}//end getMailbox()

	/**
	 * The messages.
	 *
	 * @return mixed
	 */
	public function getMessages(): mixed {
		return $this->messages;
	}//end getMessages()
}//end class
