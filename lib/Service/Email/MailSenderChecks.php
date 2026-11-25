<?php

/**
 * Dossiq MailSenderChecks.
 *
 * What Nextcloud Mail says about a sender, for NextcloudMailGateway: the DKIM
 * verdict its verifier reaches on a raw message, and whether any mailbox
 * owner's trusted-sender list carries an address. It names no `OCA\Mail`
 * symbol (design D-1): the gateway resolves Mail's services and hands them in.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mail's DKIM verdict and trusted-sender answers, built and read by NextcloudMailGateway only.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class MailSenderChecks {

	/**
	 * Constructor.
	 *
	 * @param MailMessageSource $mailTables Read-only access to the Mail tables, for the mailbox owners.
	 * @param LoggerInterface   $logger     Logger.
	 */
	public function __construct(
		private readonly MailMessageSource $mailTables,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask Mail's DKIM validator about a raw message.
	 *
	 * @param object $validator Mail's DKIM service.
	 * @param string $source    The raw message source.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function dkim(object $validator, string $source): string {
		try {
			$valid = $validator->validateRaw($source);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: the DKIM check could not run',
				['error' => $e->getMessage()]
			);
			return AuthenticationResult::UNAVAILABLE;
		}

		return self::dkimVerdictOf(valid: $valid);
	}//end dkim()

	/**
	 * The DKIM verdict for the validator's answer.
	 *
	 * Only a boolean is an answer; anything else means the check did not run.
	 *
	 * @param mixed $valid The validator's answer.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public static function dkimVerdictOf(mixed $valid): string {
		if (is_bool($valid) === false) {
			return AuthenticationResult::UNAVAILABLE;
		}

		if ($valid === true) {
			return AuthenticationResult::PASS;
		}

		return AuthenticationResult::FAIL;
	}//end dkimVerdictOf()

	/**
	 * Whether any mailbox owner's trusted-sender list carries the address.
	 *
	 * The list is per user and intake has no session, so the identities asked
	 * about are the ones that own the mailboxes being read.
	 *
	 * @param object $service Mail's trusted-sender service.
	 * @param string $address The normalised sender address.
	 *
	 * @return boolean True when one owner trusts the address.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function trusted(object $service, string $address): bool {
		foreach ($this->trustAnswers(service: $service, address: $address) as $trusted) {
			if ($trusted === true) {
				return true;
			}
		}

		return false;
	}//end trusted()

	/**
	 * Each mailbox owner's answer to whether the address is trusted, asked lazily.
	 *
	 * A lookup that fails is logged and yields nothing, so the next owner is asked.
	 *
	 * @param object $service Mail's trusted-sender service.
	 * @param string $address The normalised sender address.
	 *
	 * @return iterable<int, mixed> The answers, in account order.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function trustAnswers(object $service, string $address): iterable {
		foreach ($this->accountOwners() as $owner) {
			try {
				yield $service->isTrusted($owner, $address);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Dossiq: the trusted-sender lookup failed',
					['error' => $e->getMessage()]
				);
			}
		}
	}//end trustAnswers()

	/**
	 * The owner of each Mail account that has one, read one account at a time.
	 *
	 * @return iterable<int, string> The owners, in account order.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function accountOwners(): iterable {
		foreach ($this->mailTables->allAccounts() as $account) {
			$owner = $this->mailTables->ownerOf(accountId: (int)$account['id']);
			if ($owner !== '') {
				yield $owner;
			}
		}
	}//end accountOwners()
}//end class
