<?php

/**
 * A saved OpenRegister object as CaseTransferService reads it back.
 *
 * Carries the jsonSerialize()/getUuid() shape of OpenRegister's ObjectEntity
 * and writes every read into the shared call log.
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

use JsonSerializable;

final class RecordedTransfer implements JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The stored fields.
	 * @param array<int, mixed>    $log  The shared call log.
	 */
	public function __construct(
		private array $data,
		private array &$log,
	) {
	}//end __construct()

	/**
	 * The stored fields.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$this->log[] = ['saved.jsonSerialize' => (string)($this->data['id'] ?? '')];
		return $this->data;
	}//end jsonSerialize()

	/**
	 * The object's uuid.
	 *
	 * @return string
	 */
	public function getUuid(): string {
		$this->log[] = ['saved.getUuid' => (string)($this->data['id'] ?? '')];
		return (string)($this->data['uuid'] ?? ($this->data['id'] ?? ''));
	}//end getUuid()
}//end class
