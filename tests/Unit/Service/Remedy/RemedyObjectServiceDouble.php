<?php

/**
 * An OpenRegister object service double for the remedy lodging tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Remedy;

/**
 * An object service double: search answers decisions, save opens a case, delete is recorded.
 */
class RemedyObjectServiceDouble {

	/**
	 * The decisions the search answers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $decisions = [];

	/**
	 * Saved objects, as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $saved = [];

	/**
	 * Deleted uuids.
	 *
	 * @var list<string>
	 */
	public array $deleted = [];

	/**
	 * Search.
	 *
	 * @param mixed ...$arguments Ignored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(mixed ...$arguments): array {
		return $this->decisions;
	}//end searchObjects()

	/**
	 * Slug-aware search.
	 *
	 * @param mixed ...$arguments Ignored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(mixed ...$arguments): array {
		return $this->decisions;
	}//end searchObjectsBySlug()

	/**
	 * Find the decided case.
	 *
	 * @param mixed ...$arguments Ignored.
	 *
	 * @return array<string, mixed>
	 */
	public function find(mixed ...$arguments): array {
		return ['id' => 'woo-1', 'title' => 'Verlichting fietspad Lindelaan'];
	}//end find()

	/**
	 * Save: opens the remedy case.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param mixed                $register The register.
	 * @param mixed                $schema   The schema.
	 * @param mixed                $uuid     The uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, mixed $register = null, mixed $schema = null, mixed $uuid = null): array {
		$this->saved[] = [(string)$schema, $object];
		return $object + ['id' => 'bezwaar-1', 'plannedEndDate' => '2027-01-26'];
	}//end saveObject()

	/**
	 * Delete.
	 *
	 * @param string $uuid     The uuid.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return bool
	 */
	public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null): bool {
		$this->deleted[] = $uuid;
		return true;
	}//end deleteObject()
}//end class
