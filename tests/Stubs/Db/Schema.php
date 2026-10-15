<?php

/**
 * OpenRegister Schema stub.
 *
 * Declaration-only mirror of the few members of OpenRegister's Schema entity
 * the dossiq unit tests use. The other getters and setters come from the
 * Entity base class, as they do on the real one. StubApiDriftTest keeps it
 * in line.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A schema: what an object of it may hold.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 */
class Schema extends Entity implements JsonSerializable {

	/**
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * @var string|null
	 */
	protected ?string $slug = null;

	/**
	 * @var array<string, mixed>|null
	 */
	protected ?array $properties = [];

	/**
	 * Get the properties data
	 *
	 * @return array The properties data or empty array if null
	 */
	public function getProperties(): array {
		return ($this->properties ?? []);
	}//end getProperties()

	/**
	 * Set the slug.
	 *
	 * @param string|null $slug The slug to set
	 *
	 * @return void
	 */
	public function setSlug(?string $slug): void {
		$this->slug = $slug;
		$this->markFieldUpdated(attribute: 'slug');
	}//end setSlug()

	/**
	 * The schema as an array.
	 *
	 * @return array<string, mixed> The schema.
	 */
	public function jsonSerialize(): array {
		return ['id' => $this->id, 'uuid' => $this->uuid, 'slug' => $this->slug, 'properties' => $this->getProperties()];
	}//end jsonSerialize()
}//end class
