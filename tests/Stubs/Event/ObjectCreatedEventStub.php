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
		 * 🔴 MIRRORS THE REAL SIGNATURE, WHICH THIS STUB USED NOT TO.
		 *
		 * The real ObjectCreatedEvent::getObject() returns an ObjectEntity.
		 * This stub declared `: array` and returned `[]`, so a listener
		 * written against the real contract fataled here while working in
		 * production, and one written against the stub would have done the
		 * reverse. VergunningaanvraagCreatedListener carries a comment about
		 * exactly this divergence and normalises both shapes to survive it.
		 *
		 * @param ObjectEntity|null $object The created object.
		 */
		public function __construct(private $object = null) {
		}//end __construct()

		/**
		 * Returns the created object.
		 *
		 * @return mixed The ObjectEntity, or null when the stub was built bare.
		 */
		public function getObject() {
			return $this->object;
		}//end getObject()
	}//end class
}//end if
