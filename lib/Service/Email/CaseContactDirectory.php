<?php

/**
 * Dossiq case contact directory.
 *
 * Knows every shape a contact address can take on a case object and reduces
 * them to one normalised, validated allow-list. Cases carry contacts in four
 * different places — the top-level `email` field, a single `initiator` object,
 * and the `betrokkenen` / `contacts` collections, whose entries may key the
 * address as either `email` or `emailadres`.
 *
 * Split out of CaseEmailService so that service states the *policy* ("the
 * recipient must be allowed") while the knowledge of where contacts are stored
 * lives here.
 *
 * ⚠️ MEASURED 2026-09-10, not assumed: the `case` schema in
 * `lib/Settings/dossiq_register.json` declares NONE of these four fields, and
 * `git log -S` finds no commit that ever added one. The requester is a
 * cross-register UUID reference (`requester`), not an address. So on today's
 * data model this class returns [] for every case, and the allow-list carries
 * the whole recipient policy. It is kept, and fed the raw case record, so that
 * the day the schema grows a contact field the policy reads it — but nothing
 * here may be read as evidence that a case supplies an address today.
 *
 * An empty result means "no contact matched". It is NOT a licence to send: the
 * caller must reject a recipient that no source allows.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * Collects the normalised contact addresses registered on a case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseContactDirectory {
	/**
	 * Collect the normalised (lowercased) email addresses of all contacts on a case.
	 *
	 * Inspects the following fields (all optional): `betrokkenen`, `contacts`,
	 * `initiator`, and the top-level `email` field. None of the four is declared
	 * on today's `case` schema, so this returns an empty array for every real
	 * case; the caller MUST treat an empty array as "no contact matched", never
	 * as "no restriction".
	 *
	 * @param array<string, mixed> $caseData The case data array
	 *
	 * @return array<string> Lowercase email addresses
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function collectAddresses(array $caseData): array {
		$emails = array_merge(
			$this->collectPrimaryContactEmails(caseData: $caseData),
			$this->collectContactListEmails(caseData: $caseData),
		);

		return array_unique($emails);
	}//end collectAddresses()

	/**
	 * Collect the single-valued contact addresses on a case.
	 *
	 * Covers the top-level `email` field and the `initiator` contact object, in
	 * that order.
	 *
	 * @param array<string, mixed> $caseData The case data array
	 *
	 * @return array<string> Lowercase email addresses
	 */
	private function collectPrimaryContactEmails(array $caseData): array {
		$emails = [];

		// Top-level email field.
		$topEmail = $this->normalizeContactEmail(value: (string)($caseData['email'] ?? ''));
		if ($topEmail !== null) {
			$emails[] = $topEmail;
		}

		// Initiator field (single contact object or email string).
		$initiator = ($caseData['initiator'] ?? null);
		if (is_array($initiator) === true) {
			$addr = $this->normalizeContactEmail(value: (string)($initiator['email'] ?? ''));
			if ($addr !== null) {
				$emails[] = $addr;
			}
		}

		return $emails;
	}//end collectPrimaryContactEmails()

	/**
	 * Collect the addresses held in a case's contact collections.
	 *
	 * Covers `betrokkenen` and `contacts`, in that order; each entry may carry
	 * either an `email` or an `emailadres` key.
	 *
	 * @param array<string, mixed> $caseData The case data array
	 *
	 * @return array<string> Lowercase email addresses
	 */
	private function collectContactListEmails(array $caseData): array {
		$contactArrays = [];
		if (is_array($caseData['betrokkenen'] ?? null) === true) {
			$contactArrays[] = $caseData['betrokkenen'];
		}

		if (is_array($caseData['contacts'] ?? null) === true) {
			$contactArrays[] = $caseData['contacts'];
		}

		$emails = [];
		foreach ($contactArrays as $contacts) {
			foreach ($contacts as $contact) {
				if (is_array($contact) === false) {
					continue;
				}

				$addr = $this->normalizeContactEmail(
					value: (string)($contact['email'] ?? ($contact['emailadres'] ?? ''))
				);
				if ($addr !== null) {
					$emails[] = $addr;
				}
			}
		}

		return $emails;
	}//end collectContactListEmails()

	/**
	 * Normalise a raw contact value to a lowercase, validated email address.
	 *
	 * @param string $value The raw contact value
	 *
	 * @return string|null The lowercase address, or null when absent/invalid
	 */
	private function normalizeContactEmail(string $value): ?string {
		$addr = strtolower(trim($value));
		if ($addr === '' || filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		return $addr;
	}//end normalizeContactEmail()
}//end class
