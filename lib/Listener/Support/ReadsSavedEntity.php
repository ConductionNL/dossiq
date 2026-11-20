<?php

/**
 * The object a created or updated OpenRegister event saved.
 *
 * Null for any other event.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared step of several listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;

/**
 * Reads the saved entity of a created or updated event.
 *
 * @spec exclude shared step of several listeners, covered by their own specs
 */
trait ReadsSavedEntity {
	/**
	 * The created object, or the updated object's new state, or null.
	 *
	 * @param Event $event The event.
	 *
	 * @return ObjectEntity|null
	 */
	private function savedEntity(Event $event): ?ObjectEntity {
		$entity = null;
		if ($event instanceof ObjectCreatedEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getNewObject();
		}

		return $entity;
	}//end savedEntity()
}//end trait
