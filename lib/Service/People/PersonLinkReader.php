<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * The people linked to a case, read from OpenRegister.
 *
 * A person on a case is an OpenRegister link: a Nextcloud user or a vCard
 * contact, in a role, for a period. This reads them; `CaseRoleProjection`
 * turns them into the case's `role` records.
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */
class PersonLinkReader {

	/**
	 * @param SettingsService $settingsService OpenRegister access.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * OpenRegister's contact service, the read side of people on objects.
	 *
	 * The published object contract has no people method, so this goes through
	 * the generic class resolver, the same exception ADR-084 already makes for
	 * the file service.
	 */
	private const PEOPLE_SERVICE = 'OCA\\OpenRegister\\Service\\ContactService';

	/**
	 * The people linked to a case, [] when OpenRegister cannot answer.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The links.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function peopleOn(string $caseId): array {
		$people = $this->settingsService->getOpenRegisterClass(class: self::PEOPLE_SERVICE);
		if ($people === null) {
			return [];
		}

		try {
			$listing = $people->getContactsForObject($caseId);
		} catch (Throwable) {
			return [];
		}

		$rows = [];
		if (is_array($listing) === true && isset($listing['results']) === true && is_array($listing['results']) === true) {
			$rows = $listing['results'];
		}

		return array_values(array_filter($rows, static fn ($row): bool => is_array($row) === true));
	}//end peopleOn()

	/**
	 * One person on a case by their uid, or null when they are not on it.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $personUid The person's uid: `user:<uid>` or a vCard uid.
	 *
	 * @return array<string, mixed>|null The link.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function personOn(string $caseId, string $personUid): ?array {
		foreach ($this->peopleOn(caseId: $caseId) as $row) {
			if ((string)($row['contactUid'] ?? '') === $personUid) {
				return $row;
			}
		}

		return null;
	}//end personOn()

	/**
	 * The email address a link carries, '' when it has none.
	 *
	 * @param array<string, mixed> $link The link.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function emailOf(array $link): string {
		return trim((string)($link['email'] ?? ''));
	}//end emailOf()

	/**
	 * The name to show for a link: its display name, else its uid.
	 *
	 * @param array<string, mixed> $link The link.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function nameOf(array $link): string {
		$name = trim((string)($link['displayName'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return trim((string)($link['contactUid'] ?? ''));
	}//end nameOf()
}//end class
