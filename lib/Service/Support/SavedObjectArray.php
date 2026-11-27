<?php

/**
 * Dossiq saved object array.
 *
 * Saves an object in a register and schema and answers the stored object
 * as OpenRegister serialises it. Split out of
 * {@see \OCA\Dossiq\Service\SamenwerkverzoekService}, whose create and
 * response writes both ended this way.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * One save, answered as the serialised stored object.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SavedObjectArray {

	/**
	 * Save the object and answer what was stored.
	 *
	 * OpenRegister's saveObject() returns an entity (JsonSerializable), never
	 * an array, so the answer is its serialised form.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param array<string, mixed> $object        The object to save.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function save(object $objectService, string $register, string $schema, array $object): array {
		return $objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema))
		)->jsonSerialize();
	}//end save()
}//end class
