<?php

/**
 * Dossiq pre-save check
 *
 * The plumbing a pre-persist check shares: the object an OpenRegister
 * pre-persist event is about to save (the new object of a create, the updated
 * object of an update), and its payload; a payload that cannot be read is
 * logged at debug level and reads as none, so the save is left alone.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a listener's inspection on the object a pre-persist event saves, and reads its payload.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class PreSaveCheck {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect the object a create or an update is about to save; any other event is left alone.
	 *
	 * @param Event   $event   The dispatched event.
	 * @param Closure $inspect The inspection, called with the event and the object it saves.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function inspect(Event $event, Closure $inspect): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$inspect($event, $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$inspect($event, $event->getNewObject());
		}
	}//end inspect()

	/**
	 * The payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The object being saved.
	 * @param string       $check  The check reading it, named in the debug line.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function read(ObjectEntity $entity, string $check): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: ' . $check . ' could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end read()
}//end class
