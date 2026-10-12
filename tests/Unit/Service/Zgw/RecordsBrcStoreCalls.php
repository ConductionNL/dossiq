<?php

/**
 * A recording object store for the Besluiten API tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

/**
 * Records every read, write and delete, and fails the methods it is told to.
 */
final class RecordsBrcStoreCalls {
	/**
	 * Every call, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	public array $calls = [];

	/**
	 * Objects find() answers, by uuid; a missing uuid throws.
	 *
	 * @var array<string, mixed>
	 */
	public array $objects = [];

	/**
	 * What a search answers.
	 *
	 * @var array
	 */
	public array $searchResult = ['results' => []];

	/**
	 * Methods that throw when called.
	 *
	 * @var array<int, string>
	 */
	public array $failOn = [];

	/**
	 * What saveObject() answers.
	 *
	 * @var mixed
	 */
	public mixed $saved = ['id' => 'new-uuid'];

	private function record(string $method, array $arguments): void {
		$this->calls[] = [$method, $arguments];
		if (in_array($method, $this->failOn, true) === true) {
			throw new \RuntimeException($method . ' is down');
		}
	}//end record()

	public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
		$this->record('find', [$id, $register, $schema]);
		if (array_key_exists($id, $this->objects) === false) {
			throw new \RuntimeException('not found');
		}

		return $this->objects[$id];
	}//end find()

	public function saveObject(mixed $register, mixed $schema, array $object): mixed {
		$this->record('saveObject', [$register, $schema, $object]);
		return $this->saved;
	}//end saveObject()

	public function deleteObject(string $uuid, bool $_rbac = true, bool $_multitenancy = true): bool {
		$this->record('deleteObject', [$uuid, $_rbac, $_multitenancy]);
		return true;
	}//end deleteObject()

	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
		return ['params' => $requestParams, 'register' => $register, 'schema' => $schema];
	}//end buildSearchQuery()

	public function searchObjectsPaginated(array $query): array {
		$this->record('search', [$query]);
		return $this->searchResult;
	}//end searchObjectsPaginated()

	/**
	 * The arguments of every call of one method.
	 *
	 * @param string $method The method.
	 *
	 * @return array<int, array>
	 */
	public function callsOf(string $method): array {
		return array_values(array_map(static fn (array $c): array => $c[1], array_filter($this->calls, static fn (array $c): bool => $c[0] === $method)));
	}//end callsOf()
}//end class
