<?php

/**
 * Dossiq FindsMappedObjects trait.
 *
 * The two lookups the ZRC rule helpers share: one object of a mapped schema,
 * and the uuid in a URL or bare value. The using class provides a
 * `$zgwService` property and a `UUID_PATTERN` constant.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;


use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
/**
 * Finds an object of a mapped schema, and reads the uuid in a reference.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
trait FindsMappedObjects {
	/**
	 * Find one object of a mapped schema.
	 *
	 * @param string $uuid   The object.
	 * @param array  $config The mapping.
	 *
	 * @return mixed The row, or null.
	 */
	private function find(string $uuid, array $config): mixed {
		return $this->zgwService->getObjectService()->find(
			$uuid,
			new ObjectFindOptions(register: $config['sourceRegister'], schema: $config['sourceSchema'])
		);
	}//end find()

	/**
	 * The first UUID in a value, or null.
	 *
	 * @param string $value A URL or bare uuid.
	 *
	 * @return string|null
	 */
	private function uuidOf(string $value): ?string {
		if ($value === '' || preg_match(self::UUID_PATTERN, $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end uuidOf()
}//end trait
