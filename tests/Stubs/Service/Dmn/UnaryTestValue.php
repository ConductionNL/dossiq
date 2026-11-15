<?php

/**
 * OpenRegister UnaryTestValue
 *
 * A runtime value and its declared type, as a unary test compares them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * A coerced runtime value and its type.
 */
final class UnaryTestValue {

	/**
	 * Constructor.
	 *
	 * @param mixed  $value The runtime value, already coerced.
	 * @param string $type  Its declared type.
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly string $type,
	) {
	}//end __construct()

	/**
	 * Whether a coerced literal equals this value; numbers and dates compare within a tolerance.
	 *
	 * @param mixed $coerced The coerced literal.
	 *
	 * @return bool
	 */
	public function equals(mixed $coerced): bool {
		if ($this->type === 'number' || $this->type === 'date') {
			return (abs(((float)$this->value) - ((float)$coerced)) < 1.0e-9);
		}

		return ($this->value === $coerced);
	}//end equals()
}//end class
