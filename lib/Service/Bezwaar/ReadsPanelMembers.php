<?php

/**
 * Dossiq panel member reader.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

/**
 * Reads stored panel members and authors as UIDs: trimmed, without blanks.
 *
 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
 */
trait ReadsPanelMembers {

	/**
	 * The panel's member UIDs, trimmed, without blanks.
	 *
	 * A member that is not a UID (an array or an object) leaves no member at
	 * all, so the panel is refused rather than read without it.
	 *
	 * @param array<mixed> $panel The panel as stored.
	 *
	 * @return list<string> The members.
	 *
	 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 */
	private function panelMembers(array $panel): array {
		$members = [];
		foreach ($panel as $member) {
			if ($member !== null && is_scalar($member) === false) {
				return [];
			}

			$uid = $this->uidOf(value: $member);
			if ($uid !== null) {
				$members[] = $uid;
			}
		}

		return $members;
	}//end panelMembers()

	/**
	 * A UID with leading and trailing whitespace removed, Unicode spaces included.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The UID, or null when it is not a scalar or is blank.
	 *
	 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 */
	private function uidOf(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$uid = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $value);
		if ($uid === '') {
			return null;
		}

		return $uid;
	}//end uidOf()
}//end trait
