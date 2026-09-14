<?php

/**
 * Test double: the replacing object service plus a merging patchObject().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Fixtures;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The current OpenRegister shape: a merging `patchObject()` beside the replacing save.
 */
class PatchingObjectService extends ReplacingObjectService {

	/**
	 * Merge the patch onto the stored object and save the result.
	 *
	 * @param string               $objectId The uuid.
	 * @param array<string, mixed> $data     The fields to change.
	 * @param string|int|null      $register Unused register scope.
	 * @param string|int|null      $schema   Unused schema scope.
	 *
	 * @return object The stored object.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string|int|null $register = null,
		string|int|null $schema = null,
	): object {
		if (isset($this->stored[$objectId]) === false) {
			throw new DoesNotExistException('not found: ' . $objectId);
		}

		return $this->saveObject(
			object: array_merge($this->stored[$objectId], $data),
			register: $register,
			schema: $schema,
			uuid: $objectId
		);
	}
}//end class
