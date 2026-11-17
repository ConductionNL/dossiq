<?php

/**
 * Dossiq bezwaar record save into a resolved store.
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
 * Saves a record into a store array through `saveObjectAsArray()`, answering the record itself when the save answers nothing.
 *
 * @spec exclude shared save step; each using bezwaar service carries its own spec
 */
trait SavesIntoStore {

	/**
	 * Save the record into the store.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store  The store.
	 * @param array<string, mixed>                                            $record The record.
	 *
	 * @return array<string, mixed> The saved record, or the record as given when the save answers nothing.
	 */
	private function saveIntoStore(array $store, array $record): array {
		return ($this->saveObjectAsArray(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			object: $record
		) ?? $record);
	}//end saveIntoStore()
}//end trait
