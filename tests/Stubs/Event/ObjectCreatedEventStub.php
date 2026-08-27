<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 *
 * Loaded by tests/bootstrap.php when the real class is absent (bare CI
 * containers without the openregister runtime installed).
 * Self-skips via class_exists() guard when the real class is present.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

if (class_exists(ObjectCreatedEvent::class) === false) {
	/**
	 * Stub class for ObjectCreatedEvent — used only in standalone unit tests.
	 *
	 * Mirrors the getObject() signature used by VergunningaanvraagCreatedListener.
	 */
	class ObjectCreatedEvent extends Event {
		/**
		 * Returns the created object payload.
		 *
		 * @return array<string, mixed>
		 */
		public function getObject(): array {
			return [];
		}//end getObject()
	}//end class
}//end if
