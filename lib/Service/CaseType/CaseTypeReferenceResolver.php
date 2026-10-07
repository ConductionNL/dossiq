<?php

/**
 * Resolves a case type reference to the case type row a new case is filed on.
 *
 * A case stores its case type as a uuid (`case.caseType` is `$ref: caseType`,
 * `format: uuid`), and its status as the uuid of one of that type's status
 * types. Callers outside the case type editor rarely hold a uuid: a DSO
 * activity mapping row names a catalogue identifier, a quick action names a
 * zaaktype code, a ZGW client names a zaaktype URL. Writing that text into the
 * uuid field is refused by OpenRegister ("should match format 'uuid'"), so the
 * case is never made. This class turns the reference into the row first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeStore;

/**
 * A uuid, a ZGW zaaktype URL or a catalogue identifier, to one case type row.
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */
class CaseTypeReferenceResolver {

	private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore $store The app's one case type reader.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
	) {
	}//end __construct()

	/**
	 * The case type a new case with this reference is filed on.
	 *
	 * A uuid, or a URL ending in one, is read directly. Anything else is a
	 * catalogue identifier, and the version of that chain new cases get is the
	 * published one nothing has superseded. A reference that answers nothing
	 * gives an empty array: the caller decides whether that refuses the write,
	 * and it never guesses a type.
	 *
	 * @param string $reference The reference.
	 *
	 * @return array<string, mixed> The case type row, or an empty array.
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function resolve(string $reference): array {
		$reference = trim($reference);
		if ($reference === '') {
			return [];
		}

		$matches = [];
		if (preg_match(self::UUID_PATTERN, $reference, $matches) === 1) {
			$row = $this->store->readCaseType(caseTypeId: strtolower($matches[0]));
			if ($row !== [] || strlen($reference) === 36) {
				return $row;
			}
		}

		return $this->currentWithIdentifier(identifier: $reference);
	}//end resolve()

	/**
	 * The uuid of a resolved case type.
	 *
	 * @param array<string, mixed> $caseType The row.
	 *
	 * @return string The uuid, or '' when the row carries none.
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function idOf(array $caseType): string {
		return $this->store->rowId(row: $caseType);
	}//end idOf()

	/**
	 * The status type a new case of this type opens at.
	 *
	 * The case type's `x-openregister-prefill` fills a form and does not run
	 * on a write, so a writer that leaves the status out stores none.
	 *
	 * @param array<string, mixed> $caseType The row.
	 *
	 * @return string The status type uuid, or '' when the type names none.
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function initialStatusOf(array $caseType): string {
		return $this->store->referenceId(value: ($caseType['initialStatus'] ?? ''));
	}//end initialStatusOf()

	/**
	 * The published, unsuperseded version carrying the identifier.
	 *
	 * The identifier is compared again on the row, because a search that
	 * ignores an unknown filter answers every case type, and the first of
	 * those is not the one the caller named.
	 *
	 * @param string $identifier The catalogue identifier.
	 *
	 * @return array<string, mixed> The row, or an empty array.
	 */
	private function currentWithIdentifier(string $identifier): array {
		$current = [];
		foreach ($this->store->versionsWithIdentifier(identifier: $identifier) as $row) {
			if (trim((string)($row['identifier'] ?? '')) !== $identifier) {
				continue;
			}

			if (($row['isDraft'] ?? false) === true || $this->store->referenceId(value: ($row['supersededBy'] ?? '')) !== '') {
				continue;
			}

			$newer = version_compare((string)($row['version'] ?? '0'), (string)($current['version'] ?? '0'), '>');
			if ($current === [] || $newer === true) {
				$current = $row;
			}
		}

		return $current;
	}//end currentWithIdentifier()
}//end class
