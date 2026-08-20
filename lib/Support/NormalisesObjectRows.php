<?php

/**
 * Procest NormalisesObjectRows support trait.
 *
 * OpenRegister's `find()` / `searchObjectsPaginated()` return either a plain
 * associative array or an `ObjectEntity` depending on the call path and the
 * register configuration. Every ZGW controller therefore repeated the same
 * three-line branch before it could read a property off the result:
 *
 * ```php
 * if (is_array($obj) === true) {
 *     $data = $obj;
 * } else {
 *     $data = $obj->jsonSerialize();
 * }
 * ```
 *
 * That branch was written out ~60 times across DrcController, ZrcController,
 * ZtcController and BrcController. {@see self::objectToArray()} replaces it
 * with one named call that also copes with an object that does NOT expose
 * `jsonSerialize()` (the old inline branch fatalled on those) and with null.
 *
 * @category Support
 * @package  OCA\Procest\Support
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Support;

/**
 * Normalise a single OpenRegister result row to an associative array.
 */
trait NormalisesObjectRows {
	/**
	 * Flatten one OpenRegister result row into an associative array.
	 *
	 * Declared `protected` rather than `private`: the trait is composed into
	 * the abstract {@see \OCA\Procest\Controller\ZgwController} and the call
	 * sites live in its concrete subclasses (Drc/Zrc/Ztc/BrcController). A
	 * private trait method composed into a parent is not visible to a child.
	 *
	 * @param mixed $row An array, an ObjectEntity, any other object, or null.
	 *
	 * @return array<string, mixed> The row as an associative array; `[]` when
	 *                              the row carries nothing usable.
	 */
	protected function objectToArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		if (method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return (array)$row;
	}//end objectToArray()
}//end trait
