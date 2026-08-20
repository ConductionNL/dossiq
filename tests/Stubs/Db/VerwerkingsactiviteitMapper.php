<?php

/**
 * Test stub for OpenRegister's VerwerkingsactiviteitMapper.
 *
 * Minimal surface needed by procest unit tests: the catalogue seed repair
 * step calls findByCode / insert / update. The stub keeps an in-memory
 * code-indexed store so upsert-by-code and status-preservation semantics
 * are assertable. The real OR mapper persists to
 * oc_openregister_verwerkingsactiviteiten with vocabulary validation.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's VerwerkingsactiviteitMapper for unit tests.
 */
class VerwerkingsactiviteitMapper {
	/**
	 * In-memory store, keyed by activity code.
	 *
	 * @var array<string, Verwerkingsactiviteit>
	 */
	private array $store = [];

	/**
	 * Number of insert() calls (test accessor).
	 *
	 * @var int
	 */
	public int $inserts = 0;

	/**
	 * Number of update() calls (test accessor).
	 *
	 * @var int
	 */
	public int $updates = 0;

	/**
	 * Find by short readable code.
	 *
	 * @param string $code The activity code.
	 *
	 * @return Verwerkingsactiviteit|null Null when no row matches.
	 */
	public function findByCode(string $code): ?Verwerkingsactiviteit {
		return ($this->store[$code] ?? null);
	}//end findByCode()

	/**
	 * Insert, defaulting a blank status to `concept` (mirrors OR).
	 *
	 * @param Verwerkingsactiviteit $entity Entity to insert.
	 *
	 * @return Verwerkingsactiviteit
	 */
	public function insert(Verwerkingsactiviteit $entity): Verwerkingsactiviteit {
		if ($entity->getStatus() === null || $entity->getStatus() === '') {
			$entity->setStatus('draft');
		}

		$this->store[(string)$entity->getCode()] = $entity;
		$this->inserts++;
		return $entity;
	}//end insert()

	/**
	 * Update an existing entity.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to update.
	 *
	 * @return Verwerkingsactiviteit
	 */
	public function update(Verwerkingsactiviteit $entity): Verwerkingsactiviteit {
		$this->store[(string)$entity->getCode()] = $entity;
		$this->updates++;
		return $entity;
	}//end update()
}//end class
