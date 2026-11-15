<?php

/**
 * A stand-in for OpenRegister's ObjectService, recording what it is asked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

/**
 * Objects by uuid, relation rows by uuid, and a log of every call.
 */
class FakeObjectService {

	/**
	 * @var list<array>
	 */
	public array $calls = [];

	/**
	 * @param array<string, mixed> $objects Objects by uuid; a Throwable is thrown.
	 * @param array<string, array> $usedBy  Incoming rows by uuid.
	 * @param array<string, array> $uses    Outgoing rows by uuid.
	 */
	public function __construct(
		public array $objects = [],
		public array $usedBy = [],
		public array $uses = [],
		public ?\Throwable $saveFails = null,
	) {
	}

	public function find(string $uuid, string $register, string $schema): mixed {
		$this->calls[] = ['find', $uuid, $register, $schema];
		$object = ($this->objects[$uuid] ?? null);
		if ($object instanceof \Throwable) {
			throw $object;
		}

		return $object;
	}

	public function saveObject(array $object, string $register, string $schema): void {
		$this->calls[] = ['saveObject', $object, $register, $schema];
		if ($this->saveFails !== null) {
			throw $this->saveFails;
		}
	}

	public function setRegister(string $register): void {
		$this->calls[] = ['setRegister', $register];
	}

	public function setSchema(string $schema): void {
		$this->calls[] = ['setSchema', $schema];
	}

	public function getObjectUsedBy(string $uuid): array {
		$this->calls[] = ['getObjectUsedBy', $uuid];
		$rows = ($this->usedBy[$uuid] ?? ['results' => []]);
		if ($rows instanceof \Throwable) {
			throw $rows;
		}

		return $rows;
	}

	public function getObjectUses(string $uuid): array {
		$this->calls[] = ['getObjectUses', $uuid];

		return ($this->uses[$uuid] ?? ['results' => []]);
	}
}
