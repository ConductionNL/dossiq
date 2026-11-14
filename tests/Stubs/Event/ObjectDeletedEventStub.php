<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectDeletedEvent.
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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

if (class_exists(ObjectDeletedEvent::class) === false) {
	/**
	 * Stub class for ObjectDeletedEvent, used only in standalone unit tests.
	 */
	class ObjectDeletedEvent extends Event {

		/**
		 * Constructor.
		 *
		 * @param ObjectEntity $object The deleted entity.
		 */
		public function __construct(private readonly ObjectEntity $object) {
			parent::__construct();
		}//end __construct()

		/**
		 * Get the deleted object entity.
		 *
		 * @return ObjectEntity
		 */
		public function getObject(): ObjectEntity {
			return $this->object;
		}//end getObject()
	}//end class
}//end if
