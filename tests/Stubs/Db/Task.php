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

use JsonSerializable;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

if (class_exists(Task::class) === false) {
	// Composer's classmap answers this stub before an installed OpenRegister,
	// whose TaskService declares the real Task as its TaskInterface. So the
	// stub is that interface whenever OpenRegister's kernel contracts load.
	if (interface_exists(TaskInterface::class) === true) {
		/**
		 * The kernel task contract, when OpenRegister is installed.
		 */
		interface TaskStubContract extends TaskInterface {
		}
	} else {
		/**
		 * A serialisable task, when OpenRegister is absent.
		 */
		interface TaskStubContract extends JsonSerializable {
		}
	}//end if

	/**
	 * Stub class for Task — used only in standalone unit tests.
	 */
	class Task implements TaskStubContract {

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

		/**
		 * @return boolean Whether the state is completed, terminated or disabled.
		 */
		public function isInTerminalState(): bool {
			return in_array($this->state, ['completed', 'terminated', 'disabled'], true);
		}//end isInTerminalState()

		/**
		 * @param array<string, mixed> $object The uuid, runUuid, nodeId and state to set.
		 *
		 * @return self This task.
		 */
		public function hydrate(array $object): self {
			foreach (['uuid', 'runUuid', 'nodeId', 'state'] as $field) {
				if (array_key_exists($field, $object) === true) {
					$this->$field = $object[$field];
				}
			}

			return $this;
		}//end hydrate()

		/**
		 * @return array<string, string|null> The uuid, runUuid, nodeId and state.
		 */
		public function jsonSerialize(): array {
			return ['uuid' => $this->uuid, 'runUuid' => $this->runUuid, 'nodeId' => $this->nodeId, 'state' => $this->state];
		}//end jsonSerialize()
	}//end class
}
