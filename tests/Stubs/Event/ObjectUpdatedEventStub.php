<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 *
 * Loaded by tests/bootstrap.php when the real class is absent (bare CI
 * containers without the openregister runtime installed). Self-skips via a
 * class_exists() guard when the real class is present, so an installed
 * openregister always wins.
 *
 * Mirrors the real class's public surface exactly — `getNewObject()` and
 * `getOldObject()` — so BezwaarDecisionListenerTest can exercise the real
 * guard decision through `handle()` rather than around it. The post-persist
 * counterpart of ObjectUpdatingEventStub.
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

if (class_exists(ObjectUpdatedEvent::class) === false) {
	/**
	 * Stub class for ObjectUpdatedEvent — used only in standalone unit tests.
	 */
	class ObjectUpdatedEvent extends Event {

		/**
		 * Constructor.
		 *
		 * @param ObjectEntity $newObject The post-update entity.
		 * @param ObjectEntity|null $oldObject The pre-update entity.
		 */
		public function __construct(
			private readonly ObjectEntity $newObject,
			private readonly ?ObjectEntity $oldObject = null,
		) {
			parent::__construct();
		}//end __construct()

		/**
		 * Get the post-update object entity.
		 *
		 * @return ObjectEntity
		 */
		public function getNewObject(): ObjectEntity {
			return $this->newObject;
		}//end getNewObject()

		/**
		 * Get the pre-update object entity.
		 *
		 * @return ObjectEntity|null
		 */
		public function getOldObject(): ?ObjectEntity {
			return $this->oldObject;
		}//end getOldObject()
	}//end class
}//end if
