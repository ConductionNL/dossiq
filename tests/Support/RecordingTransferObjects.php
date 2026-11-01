<?php

/**
 * An in-memory OpenRegister ObjectService for the transfer register.
 *
 * Records every find() and saveObject() call by parameter name. Behaviour
 * keys: `find` (id => fields, or 'throw'), `findAs` ('array' answers a plain
 * array instead of an object), `saveAs` ('array' answers the saved fields as
 * an array), `idKey` ('uuid' or 'none' to change which key a new object gets).
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

final class RecordingTransferObjects {
	/**
	 * The objects written, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $saved = [];

	private int $next = 1;

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

		return new RecordedTransfer(data: $found, log: $this->log);
	}//end find()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object   The fields.
	 * @param int                  $register The register id.
	 * @param int                  $schema   The schema id.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, int $register, int $schema) {
		$this->log[] = ['objects.saveObject' => ['object' => $object, 'register' => $register, 'schema' => $schema]];
		$idKey = ($this->behaviour['idKey'] ?? 'id');
		if ($idKey !== 'none' && isset($object['id']) === false && isset($object['uuid']) === false) {
			$object[$idKey] = 'transfer-' . $this->next++;
		}

		$this->saved[(string)($object['id'] ?? ($object['uuid'] ?? ''))] = $object;
		if (($this->behaviour['saveAs'] ?? '') === 'array') {
			return $object;
		}

		return new RecordedTransfer(data: $object, log: $this->log);
	}//end saveObject()
}//end class
