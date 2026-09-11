<?php

/**
 * Test stub for OCA\OpenRegister\Db\Task.
 *
 * Loaded by tests/bootstrap.php when the real class is absent (bare CI
 * containers without the openregister runtime installed). Self-skips via a
 * class_exists() guard, so an installed openregister always wins.
 *
 * Mirrors ONLY the accessors `TaskCompletionResumeListener` reads:
 * `getRunUuid()`, `getNodeId()`, `getState()` and `getUuid()`. The real
 * entity has 57 columns; stubbing all of them would be a second copy to keep
 * in sync for no gain, and StubApiDriftTest checks the surface that is here.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\Db
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

if (class_exists(Task::class) === false) {
	/**
	 * Stub class for Task — used only in standalone unit tests.
	 */
	class Task {

		/**
		 * The engine task uuid.
		 *
		 * @var string|null
		 */
		private ?string $uuid = null;

		/**
		 * The flow run this task was raised by, when it was.
		 *
		 * @var string|null
		 */
		private ?string $runUuid = null;

		/**
		 * The node within that run.
		 *
		 * @var string|null
		 */
		private ?string $nodeId = null;

		/**
		 * The CMMN state.
		 *
		 * @var string|null
		 */
		private ?string $state = null;

		/**
		 * @return string|null The uuid.
		 */
		public function getUuid(): ?string {
			return $this->uuid;
		}//end getUuid()

		/**
		 * @return string|null The run uuid.
		 */
		public function getRunUuid(): ?string {
			return $this->runUuid;
		}//end getRunUuid()

		/**
		 * @return string|null The node id.
		 */
		public function getNodeId(): ?string {
			return $this->nodeId;
		}//end getNodeId()

		/**
		 * @return string|null The state.
		 */
		public function getState(): ?string {
			return $this->state;
		}//end getState()
	}//end class
}
