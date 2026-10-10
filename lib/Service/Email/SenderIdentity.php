<?php

/**
 * Dossiq Sender Identity
 *
 * Which Nextcloud Mail account a case message leaves from.
 *
 * A case type may name the account its team's mail leaves from, by the
 * account's address, so a bezwaar goes out as Juridische Zaken rather than as
 * one instance-wide address. A case type that names none uses the account an
 * administrator picked for the mailbox (the same account intake reads). The
 * address is the declaration, not the account id, because an id differs per
 * instance and an address survives an export.
 *
 * dossiq never writes a From address of its own: the account the message
 * leaves from decides the sender. An address no Mail account holds is refused,
 * so dossiq cannot be asked to forge one.
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Resolves the sending account for a case, a case type or a requested address.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
 */
class SenderIdentity {

	/**
	 * The case type property that names the sending account by its address.
	 */
	public const CASE_TYPE_PROPERTY = 'mailAccount';

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway       The mail app, for its accounts.
	 * @param IntakeAccount        $intakeAccount The account an administrator picked.
	 * @param CaseTypeResolver     $caseTypes     The effective case type, parents included.
	 * @param CaseTypeStore        $store         Reads a case's case-type reference.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly IntakeAccount $intakeAccount,
		private readonly CaseTypeResolver $caseTypes,
		private readonly CaseTypeStore $store,
	) {
	}//end __construct()

	/**
	 * The account a message about this case leaves from.
	 *
	 * @param array<string, mixed> $caseData The raw case record.
	 *
	 * @return array{id: int, name: string, email: string} The account.
	 *
	 * @throws RefusedException sender-not-held when the case type names an address no account holds,
	 *                          mail-account-unavailable when no account can be used at all.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
	 */
	public function accountForCase(array $caseData): array {
		return $this->accountFor(address: $this->declaredFor(caseData: $caseData));
	}//end accountForCase()

	/**
	 * The account that holds an address, or the default account for an empty one.
	 *
	 * @param string $address The address asked for, or '' for the default.
	 *
	 * @return array{id: int, name: string, email: string} The account.
	 *
	 * @throws RefusedException sender-not-held or mail-account-unavailable.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
	 */
	public function accountFor(string $address): array {
		$address = trim($address);
		if ($address !== '') {
			$account = $this->holding(address: $address);
			if ($account === null) {
				throw new RefusedException(
					rule: 'sender-not-held',
					sentence: 'No Nextcloud Mail account holds ' . $address . ', so dossiq does not send from it.',
					status: RefusedException::STATUS_UNPROCESSABLE
				);
			}

			return $account;
		}

		$defaultId = $this->intakeAccount->accountId();
		foreach ($this->gateway->accounts() as $account) {
			if ($defaultId > 0 && (int)$account['id'] === $defaultId) {
				return $account;
			}
		}

		throw new RefusedException(
			rule: 'mail-account-unavailable',
			sentence: 'No Nextcloud Mail account is picked for case mail, or it cannot be reached. Pick one in the dossiq mail settings.',
			status: RefusedException::STATUS_INDETERMINATE
		);
	}//end accountFor()

	/**
	 * Why a case type cannot be published with its sending account, if it cannot.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return array<int, string> One finding naming the account, or none.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
	 */
	public function publicationFindings(array $caseType): array {
		$declared = trim((string)($caseType[self::CASE_TYPE_PROPERTY] ?? ''));
		if ($declared === '' || $this->holding(address: $declared) !== null) {
			return [];
		}

		return [
			'The mail account ' . $declared . ' does not exist in Nextcloud Mail. '
			. 'Pick an account that does, or leave it empty to use the default account.',
		];
	}//end publicationFindings()

	/**
	 * The address a case's case type declares, or ''.
	 *
	 * @param array<string, mixed> $caseData The raw case record.
	 *
	 * @return string The declared address.
	 */
	private function declaredFor(array $caseData): string {
		$caseTypeId = $this->store->referenceId(value: ($caseData['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return '';
		}

		$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);

		return trim((string)($caseType[self::CASE_TYPE_PROPERTY] ?? ''));
	}//end declaredFor()

	/**
	 * The Mail account whose own address is this one.
	 *
	 * @param string $address The address.
	 *
	 * @return array{id: int, name: string, email: string}|null The account, or null.
	 */
	private function holding(string $address): ?array {
		foreach ($this->gateway->accounts() as $account) {
			if (strcasecmp(trim((string)$account['email']), $address) === 0) {
				return $account;
			}
		}

		return null;
	}//end holding()
}//end class
