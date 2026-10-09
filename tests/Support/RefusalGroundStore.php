<?php

/**
 * An object service holding the seeded Woo refusal grounds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use RuntimeException;

/**
 * Answers `searchObjectsBySlug()` with the real signature of OpenRegister's
 * ObjectService, from the rows of the real register fragment.
 */
class RefusalGroundStore {

	/**
	 * The stored ground rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Whether the next read fails.
	 *
	 * @var bool
	 */
	public bool $fails = false;

	/**
	 * The scope flags each read was made with.
	 *
	 * @var list<array{rbac: bool, multitenancy: bool}>
	 */
	public array $reads = [];

	/**
	 * A store seeded from `register.d/84-woo-refusal-grounds.json`.
	 *
	 * @return self The store.
	 */
	public static function seeded(): self {
		$store = new self();
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/lib/Settings/register.d/84-woo-refusal-grounds.json'),
			true
		);
		foreach ($fragment['components']['objects'] as $index => $object) {
			$slug = $object['@self']['slug'];
			unset($object['@self']);
			$store->rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $index), '@self' => ['slug' => $slug]] + $object;
		}

		return $store;
	}//end seeded()

	/**
	 * OpenRegister's `ObjectService::searchObjectsBySlug()`.
	 *
	 * @param string               $registerSlug  The register slug.
	 * @param string               $schemaSlug    The schema slug.
	 * @param array<string, mixed> $filters       Filters.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Whether tenancy applies.
	 *
	 * @return array<int, array<string, mixed>>|int The rows.
	 *
	 * @throws RuntimeException When told to fail.
	 */
	public function searchObjectsBySlug(
		string $registerSlug,
		string $schemaSlug,
		array $filters = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array|int {
		$this->reads[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		if ($this->fails === true) {
			throw new RuntimeException('database gone');
		}

		if ($registerSlug !== 'dossiq' || $schemaSlug !== 'wooRefusalGround') {
			return [];
		}

		return $this->rows;
	}//end searchObjectsBySlug()
}//end class
