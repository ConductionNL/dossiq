<?php

/**
 * Whether a value read off a case is filled in.
 *
 * Intake's completeness check and the derived-status evaluator both ask it:
 * a blank string and an empty list are not filled in; anything else beyond
 * what each caller rules out first is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Reads whether a value is filled in.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class FilledValue {
	/**
	 * Whether a value is filled in: a non-blank string, a non-empty list, or any other value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when filled in.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public static function isFilled(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return true;
	}//end isFilled()
}//end class
