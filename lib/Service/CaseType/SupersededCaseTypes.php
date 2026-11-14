<?php

/**
 * The version in use of a case type version.
 *
 * A choice made on a case type version that was superseded since names the
 * version in use: the end of its `supersededBy` chain.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-chosen-case-type-follows-its-version-chain
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * Follows a case type version's `supersededBy` links.
 *
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-chosen-case-type-follows-its-version-chain
 */
class SupersededCaseTypes {
	/**
	 * The longest `supersededBy` chain followed, so a cycle cannot hang a request.
	 *
	 * @var int
	 */
	private const MAX_HOPS = 50;

	/**
	 * The version in use for a case type version, following `supersededBy`.
	 *
	 * @param string                $uuid         A case type version uuid.
	 * @param array<string, string> $supersededBy Each superseded version's uuid => the uuid that replaced it.
	 *
	 * @return string The uuid of the version that is not superseded, or where a cycle stopped.
	 *
	 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-chosen-case-type-follows-its-version-chain
	 */
	public function currentVersionOf(string $uuid, array $supersededBy): string {
		$hops = 0;
		while (isset($supersededBy[$uuid]) === true && $hops < self::MAX_HOPS) {
			$uuid = $supersededBy[$uuid];
			$hops++;
		}

		return $uuid;
	}//end currentVersionOf()
}//end class
