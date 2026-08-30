<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectUpdatingEvent.
 *
 * Loaded by tests/bootstrap.php when the real class is absent (bare CI
 * containers without the openregister runtime installed).
 * Self-skips via class_exists() guard when the real class is present.
 * Mirrors the real class's public surface exactly (see
 * `bag-location-save-validation` design.md §5) — `getNewObject()` /
 * `getOldObject()`, `setErrors()`/`getErrors()`,
 * `stopPropagation()`/`isPropagationStopped()`,
 * `setModifiedData()`/`getModifiedData()` — so
 * `LocationBagValidationListenerTest` can exercise the real accept/reject
 * decision through `handle()`.
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
use Psr\EventDispatcher\StoppableEventInterface;

if (class_exists(ObjectUpdatingEvent::class) === false) {
	/**
	 * Stub class for ObjectUpdatingEvent — used only in standalone unit
	 * tests.
	 */
	class ObjectUpdatingEvent extends Event implements StoppableEventInterface {
		/**
		 * Whether event propagation has been stopped.
		 *
		 * @var bool
		 */
		private bool $propagationStopped = false;

		/**
		 * Errors set by a hook that stopped propagation.
		 *
		 * @var array<string, mixed>
		 */
		private array $errors = [];

		/**
		 * Modified data set by a hook.
		 *
		 * @var array<string, mixed>
		 */
		private array $modifiedData = [];

		/**
		 * Constructor.
		 *
		 * @param ObjectEntity $newObject The new (post-mutation) entity.
		 * @param ObjectEntity|null $oldObject The pre-mutation entity.
		 */
		public function __construct(
			private readonly ObjectEntity $newObject,
			private readonly ?ObjectEntity $oldObject = null,
		) {
			parent::__construct();
		}//end __construct()

		/**
		 * Get the new (post-mutation) object entity.
		 *
		 * @return ObjectEntity
		 */
		public function getNewObject(): ObjectEntity {
			return $this->newObject;
		}//end getNewObject()

		/**
		 * Get the pre-mutation object entity.
		 *
		 * @return ObjectEntity|null
		 */
		public function getOldObject(): ?ObjectEntity {
			return $this->oldObject;
		}//end getOldObject()

		/**
		 * Whether propagation has been stopped by a hook.
		 *
		 * @return bool
		 */
		public function isPropagationStopped(): bool {
			return $this->propagationStopped;
		}//end isPropagationStopped()

		/**
		 * Stop event propagation (used by hooks to reject the update).
		 *
		 * @return void
		 */
		public function stopPropagation(): void {
			$this->propagationStopped = true;
		}//end stopPropagation()

		/**
		 * Set errors from a hook.
		 *
		 * @param array<string, mixed> $errors Error details
		 *
		 * @return void
		 */
		public function setErrors(array $errors): void {
			$this->errors = $errors;
		}//end setErrors()

		/**
		 * Get errors set by a hook.
		 *
		 * @return array<string, mixed>
		 */
		public function getErrors(): array {
			return $this->errors;
		}//end getErrors()

		/**
		 * Set modified data from a hook.
		 *
		 * @param array<string, mixed> $data Modified data
		 *
		 * @return void
		 */
		public function setModifiedData(array $data): void {
			$this->modifiedData = $data;
		}//end setModifiedData()

		/**
		 * Get modified data set by a hook.
		 *
		 * @return array<string, mixed>
		 */
		public function getModifiedData(): array {
			return $this->modifiedData;
		}//end getModifiedData()
	}//end class
}//end if
