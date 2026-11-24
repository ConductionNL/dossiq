<?php

/**
 * Dossiq Mail Source Reader
 *
 * Reads a message's raw source through whichever MailManager::getSource() the installed
 * Nextcloud Mail declares.
 *
 * Mail 5 declares getSource(Horde_Imap_Client_Socket $client, Account $account, string $mailbox,
 * int $uid): the caller opens the IMAP client and logs it out afterwards. Without a leading
 * `$client` parameter the call keeps the account, mailbox and uid form.
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
 * @spec openspec/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use Closure;
use ReflectionMethod;
use Throwable;

/**
 * Calls getSource() in the form the installed Mail declares.
 *
 * @spec openspec/specs/inbound-mail-filters/spec.md
 */
final class MailSourceReader {
	/**
	 * Read a message source.
	 *
	 * @param object  $manager       The Mail manager.
	 * @param Closure $clientFactory Resolves Mail's IMAP client factory, or null when it cannot.
	 * @param object  $account       The Mail account.
	 * @param string  $mailbox       The folder.
	 * @param integer $uid           The message uid.
	 *
	 * @return mixed What getSource() answered, or '' when Mail 5's client factory is missing.
	 *
	 * @throws Throwable When Mail cannot open the client or read the message.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 */
	public function read(object $manager, Closure $clientFactory, object $account, string $mailbox, int $uid): mixed {
		$first = ((new ReflectionMethod($manager, 'getSource'))->getParameters()[0] ?? null);
		if ($first === null || $first->getName() !== 'client') {
			return $manager->getSource($account, $mailbox, $uid);
		}

		$factory = $clientFactory();
		if ($factory === null || method_exists($factory, 'getClient') === false) {
			return '';
		}

		$client = $factory->getClient($account);
		try {
			return $manager->getSource($client, $account, $mailbox, $uid);
		} finally {
			if (method_exists($client, 'logout') === true) {
				$client->logout();
			}
		}
	}//end read()
}//end class
