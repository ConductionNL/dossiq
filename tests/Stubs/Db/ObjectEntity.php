<?php

/**
 * Test stub for OpenRegister's ObjectEntity.
 *
 * Minimal surface needed by procest unit tests: the audit listener resolves an
 * ObjectEntity from OR's ObjectService and hands it to AuditTrailMapper. Only
 * the accessors the procest code touches are stubbed.
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
 * Stub of OpenRegister's ObjectEntity for unit tests.
 */
class ObjectEntity implements \OCA\OpenRegister\Contract\ObjectEntityInterface {
		/**
		 * @return ?string
		 */
		public function getRegister(): ?string {
			return $this->register ?? null;
		}

		/**
		 * @return ?string
		 */
		public function getSchema(): ?string {
			return $this->schema ?? null;
		}

		/**
		 * @return ?string
		 */
		public function getOrganisation(): ?string {
			return $this->organisation ?? null;
		}

		/**
		 * @return ?string
		 */
		public function getOwner(): ?string {
			return $this->owner ?? null;
		}

	/**
	 * Object UUID.
	 *
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * Raw object data (excl. `@self`).
	 *
	 * @var array<string, mixed>
	 */
	private array $object = [];

	/**
	 * Schema identifier surfaced under `@self.schema` by jsonSerialize().
	 *
	 * @var string|null
	 */
	private ?string $schemaId = null;

	/**
	 * Get the object UUID.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * Set the object UUID.
	 *
	 * @param string|null $uuid The UUID
	 *
	 * @return void
	 */
	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}//end setUuid()

	/**
	 * Set the raw object data.
	 *
	 * @param array<string, mixed> $object Object data
	 *
	 * @return void
	 */
	public function setObject(array $object): void {
		$this->object = $object;
	}//end setObject()

	/**
	 * Get the raw object data (excl. `@self`).
	 *
	 * @return array<string, mixed>
	 */
	public function getObject(): array {
		return $this->object;
	}//end getObject()

	/**
	 * Set the schema identifier surfaced under `@self.schema`.
	 *
	 * @param string|null $schemaId Schema identifier
	 *
	 * @return void
	 */
	public function setSchemaId(?string $schemaId): void {
		$this->schemaId = $schemaId;
	}//end setSchemaId()

	/**
	 * Minimal stand-in for the real ObjectEntity::jsonSerialize() — merges
	 * the raw object data with an `@self.schema` (and `@self.id`) envelope,
	 * matching the fields procest listeners actually read.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$data = $this->object;
		$data['@self'] = [
			'schema' => $this->schemaId,
			'id' => $this->uuid,
		];

		return $data;
	}//end jsonSerialize()
}//end class
