<?php

/**
 * An in-memory OpenRegister ObjectService for the case share register.
 *
 * Records every find() and saveObject() call by parameter name. Behaviour
 * keys: `find` (id => fields, or 'throw'), `findAs` ('array' answers a plain
 * array instead of an object).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use RuntimeException;

final class RecordingShareObjects {
	/**
	 * The objects written, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $behaviour How the store answers.
	 * @param array<int, mixed>    $log       The shared call log.
	 */
	public function __construct(
		private array $behaviour,
		private array &$log,
	) {
	}//end __construct()

	/**
	 * Find one object.
	 *
	 * @param int|string $id   The object id.
	 * @param mixed      $args The remaining arguments, by name.
	 *
	 * @return mixed
	 */
	public function find($id, ...$args) {
		$this->log[] = ['objects.find' => ['id' => $id] + $args];
		$found = ($this->behaviour['find'][(string)$id] ?? null);
		if ($found === 'throw') {
			throw new RuntimeException('find failed');
		}

		if ($found === null || ($this->behaviour['findAs'] ?? '') === 'array') {
			return $found;
		}

		return new RecordedShare(data: $found, log: $this->log);
	}//end find()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object   The fields.
	 * @param int                  $register The register id.
	 * @param int                  $schema   The schema id.
	 *
	 * @return RecordedShare
	 */
	public function saveObject(array $object, int $register, int $schema) {
		$this->log[] = ['objects.saveObject' => ['object' => $object, 'register' => $register, 'schema' => $schema]];
		$object['id'] = ($object['id'] ?? ('share-' . (count($this->saved) + 1)));
		$this->saved[] = $object;
		return new RecordedShare(data: $object, log: $this->log);
	}//end saveObject()
}//end class
