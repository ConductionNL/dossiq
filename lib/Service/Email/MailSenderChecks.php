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

use Closure;
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
	 * The DKIM verdict Mail reaches for a raw message.
	 *
	 * Mail's verifier answers a boolean, which cannot distinguish "no signature"
	 * from "a signature that did not verify". So an unsigned message is `none`
	 * from the absence of a `DKIM-Signature` header, and only a message that
	 * carries one can be `pass` or `fail`. Mail's verifier is resolved only
	 * then; when it is absent the answer is `unavailable`, never `pass`.
	 *
	 * @param string  $source    The raw message source.
	 * @param Closure $validator Resolves Mail's DKIM service: fn (): ?object.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function dkimResult(string $source, Closure $validator): string {
		if ($source === '') {
			return AuthenticationResult::UNAVAILABLE;
		}

		if (stripos($source, 'DKIM-Signature:') === false) {
			return AuthenticationResult::NONE;
		}

		$service = $validator();
		if ($service === null) {
			return AuthenticationResult::UNAVAILABLE;
		}

		return $this->dkim(validator: $service, source: $source);
	}//end dkimResult()

	/**
	 * Whether Mail's trusted-sender list carries this address.
	 *
	 * An empty address is not trusted and Mail is not asked; otherwise Mail's
	 * service is resolved and every mailbox owner asked in turn.
	 *
	 * @param string  $email   The sender address, in any case.
	 * @param Closure $service Resolves Mail's trusted-sender service: fn (): ?object.
	 *
	 * @return boolean True when one owner trusts the address.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isTrustedSender(string $email, Closure $service): bool {
		$address = trim(strtolower($email));
		if ($address === '') {
			return false;
		}

		$trustedSenders = $service();
		if ($trustedSenders === null) {
			return false;
		}

		return $this->trusted(service: $trustedSenders, address: $address);
	}//end isTrustedSender()

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
