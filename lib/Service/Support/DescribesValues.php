<?php

/**
 * Dossiq DescribesValues trait
 *
 * A value described for a message: itself when a string, its type otherwise.
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
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * A value described for a message: itself when a string, its type otherwise.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait DescribesValues {
	/**
	 * A value as it reads in a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string, or the type of anything else.
	 */
	private function describe(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return gettype($value);
	}//end describe()
}//end trait
