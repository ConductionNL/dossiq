<?php

/**
 * Test stub for OCA\OpenRegister\Event\SchemaUpdatedEvent.
 *
 * Autoloaded from tests/Stubs (autoload-dev maps OCA\OpenRegister\ here) when
 * the real class is absent; an installed openregister always wins. Mirrors the
 * real class's public surface: `getNewSchema()` and `getOldSchema()`.
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

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

if (class_exists(SchemaUpdatedEvent::class, false) === false) {
	/**
	 * Stub class for SchemaUpdatedEvent, used only in standalone unit tests.
	 */
	class SchemaUpdatedEvent extends Event {

		/**
		 * @param Schema $newSchema The schema after the update.
		 * @param Schema $oldSchema The schema before it.
		 */
		public function __construct(
			private readonly Schema $newSchema,
			private readonly Schema $oldSchema,
		) {
			parent::__construct();
		}//end __construct()

		/**
		 * @return Schema The schema after the update.
		 */
		public function getNewSchema(): Schema {
			return $this->newSchema;
		}//end getNewSchema()

		/**
		 * @return Schema The schema before the update.
		 */
		public function getOldSchema(): Schema {
			return $this->oldSchema;
		}//end getOldSchema()
	}//end class
}//end if
