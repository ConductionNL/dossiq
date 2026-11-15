<?php

/**
 * Test stub for OpenRegister's Mapping entity.
 *
 * Only what dossiq calls: a Mapping is built and hydrated from a plain array,
 * then handed to the mapping service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal Mapping stub.
 */
class Mapping {

	/**
	 * The fields it was hydrated with.
	 *
	 * @var array<string, mixed>
	 */
	public array $fields = [];

	/**
	 * Set the fields from an array, as Entity::hydrate() does.
	 *
	 * @param array<string, mixed> $object The fields.
	 *
	 * @return static
	 */
	public function hydrate(array $object): static {
		$this->fields = $object;

		return $this;
	}//end hydrate()
}//end class
