<?php

/**
 * Dossiq new-messages listener.
 *
 * Runs the intake pipeline when Nextcloud Mail reports that a folder gained
 * messages, so dossiq stops polling IMAP and reads what Mail already fetched
 * (task 1.2).
 *
 * THE LISTENER NAMES NO `OCA\Mail` SYMBOL, not even in its type hint. It takes
 * the framework's own `Event` and hands it to the gateway, which is the one
 * class allowed to know what the event is. Registering a listener for an event
 * class that does not exist is inert, so an instance without the Mail app
 * simply never fires this.
 *
 * IT ONLY ACTS ON THE ACCOUNT AND FOLDER AN ADMINISTRATOR PICKED. Nextcloud
 * Mail synchronises every account of every user on the instance, and a listener
 * that processed all of them would turn every colleague's inbox into a case
 * intake.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs intake on the messages Nextcloud Mail just synchronised.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class NewMessagesSynchronizedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway The mail gateway, which owns the event shape.
	 * @param IntakeAccount        $account The account and folder an administrator picked.
	 * @param InboundMailIntake    $intake  The intake path.
	 * @param LoggerInterface      $logger  Logger.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly IntakeAccount $account,
		private readonly InboundMailIntake $intake,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one synchronisation event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function handle(Event $event): void {
		if ($this->account->isConfigured() === false) {
			return;
		}

		$unpacked = $this->gateway->unpackSynchronisation($event);
		if ($unpacked === null) {
			return;
		}

		if ($this->account->matches(accountId: $unpacked['accountId'], mailbox: $unpacked['mailbox']) === false) {
			return;
		}

		try {
			$counts = $this->intake->processBatch(
				rows: $unpacked['messages'],
				accountId: $unpacked['accountId'],
				mailbox: $unpacked['mailbox']
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: intake failed on a Mail synchronisation event',
				['error' => $e->getMessage()]
			);
			return;
		}

		if ($counts === []) {
			return;
		}

		$this->logger->info(
			'Dossiq: intake processed a synchronised batch',
			['outcomes' => $counts]
		);
	}//end handle()
}//end class
