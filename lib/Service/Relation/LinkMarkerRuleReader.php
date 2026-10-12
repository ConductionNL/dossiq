<?php

/**
 * Dossiq link marker rule reader.
 *
 * Reads the stored JSON list of link marker rules, each through
 * {@see LinkMarkerRule}. A blank list holds no rules; a list that does not
 * parse, or an entry that is not a well-formed rule, is refused.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use InvalidArgumentException;

/**
 * Reads the link marker rules from their stored JSON form.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
 */
final class LinkMarkerRuleReader {

	/**
	 * Every rule in a stored JSON list. A blank list holds no rules.
	 *
	 * @param string $json The stored list.
	 *
	 * @return array<int, LinkMarkerRule> The rules.
	 *
	 * @throws InvalidArgumentException When the list does not parse, or an entry is not a well-formed rule.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
	 */
	public function fromJson(string $json): array {
		if (trim($json) === '') {
			return [];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('The marker rules are not a JSON list.');
		}

		$rules = [];
		foreach ($decoded as $entry) {
			$rules[] = new LinkMarkerRule(config: $entry);
		}

		return $rules;
	}//end fromJson()
}//end class
