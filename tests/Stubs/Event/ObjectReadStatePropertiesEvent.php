<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectReadStatePropertiesEvent.
 *
 * Autoloaded from tests/Stubs (autoload-dev maps OCA\OpenRegister\ here) when
 * the real class is absent; an installed openregister always wins. Mirrors the
 * real class's public surface: the object, the schema, the declared list, and
 * `narrowTo()` that can only narrow it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\Event
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

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

if (class_exists(ObjectReadStatePropertiesEvent::class, false) === false) {
	/**
	 * Stub class for ObjectReadStatePropertiesEvent, used only in standalone unit tests.
	 */
	class ObjectReadStatePropertiesEvent extends Event {

		/**
		 * @var array<int, string>
		 */
		private array $properties;

		/**
		 * @param ObjectEntity       $object   The object.
		 * @param Schema             $schema   Its schema.
		 * @param array<int, string> $declared The schema's list.
		 */
		public function __construct(
			private readonly ObjectEntity $object,
			private readonly Schema $schema,
			private readonly array $declared,
		) {
			parent::__construct();
			$this->properties = array_values($declared);
		}//end __construct()

		/**
		 * @return ObjectEntity The object.
		 */
		public function getObject(): ObjectEntity {
			return $this->object;
		}//end getObject()

		/**
		 * @return Schema The schema.
		 */
		public function getSchema(): Schema {
			return $this->schema;
		}//end getSchema()

		/**
		 * @return array<int, string> The schema's list.
		 */
		public function getDeclared(): array {
			return $this->declared;
		}//end getDeclared()

		/**
		 * @param array<int, mixed> $properties The properties to keep.
		 *
		 * @return void
		 */
		public function narrowTo(array $properties): void {
			$names = array_filter($properties, static fn ($name): bool => is_string($name) === true && $name !== '');
			$this->properties = array_values(array_intersect($this->properties, $names));
		}//end narrowTo()

		/**
		 * @return array<int, string> The narrowed list.
		 */
		public function getProperties(): array {
			return $this->properties;
		}//end getProperties()
	}//end class
}//end if
