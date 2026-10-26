<?php

/**
 * Moves a document's file into its case's folder when a join is stored.
 *
 * The ZRC endpoint homes a document when it stores a `zaakinformatieobject`,
 * but a join written straight into OpenRegister (an import, an example set,
 * a migration) passed no such step: the file stayed in the record's own
 * folder, the case's Files tab, which lists the case folder, stayed empty, and
 * the linked rows skipped a record without a `fileId`. This listener homes the
 * file for a join however it was written. A join saved again homes a file that
 * is still in its own folder and does nothing once it is home.
 *
 * IT NEVER THROWS. {@see DocumentJoinHoming::home()} logs a failed move; the
 * join is stored either way.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Homes the file of every stored document join.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class DocumentJoinHomingListener implements IEventListener {

	/**
	 * The join schema this listener answers to.
	 *
	 * @var string
	 */
	public const SCHEMA = 'zaakinformatieobject';

	/**
	 * Constructor.
	 *
	 * @param DocumentJoinHoming       $homing       Moves the file into the case's folder.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves a schema id to its slug.
	 */
	public function __construct(
		private readonly DocumentJoinHoming $homing,
		private readonly ObjectSchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Home the file of the join a create or an update stored.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$payload = $this->payload(event: $event);
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== self::SCHEMA) {
			return;
		}

		$caseId = trim((string)($payload['case'] ?? ''));
		$recordId = trim((string)($payload['informatieobject'] ?? ''));
		if ($caseId === '' || $recordId === '') {
			return;
		}

		$this->homing->home(caseUrl: $caseId, informatieobjectUrl: $recordId);
	}//end handle()

	/**
	 * The stored object as a plain array.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The payload, or null when it cannot be read.
	 */
	private function payload(Event $event): ?array {
		$object = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$object = $event->getNewObject();
		}

		if ($event instanceof ObjectCreatedEvent) {
			$object = $event->getObject();
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end payload()
}//end class
