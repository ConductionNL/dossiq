<?php

/**
 * Dossiq NormalisesObjectRows trait
 *
 * An OpenRegister object, or an array, read as a plain array of its data.
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
 * An OpenRegister object, or an array, read as a plain array of its data.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait NormalisesObjectRows {
	/**
	 * Normalise an OpenRegister object to a plain array.
	 *
	 * @param mixed $object The object entity or array.
	 *
	 * @return array The object's data.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === false) {
			return [];
		}

		if (method_exists($object, 'getObject') === true) {
			return (array)$object->getObject();
		}

		if (method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end trait
