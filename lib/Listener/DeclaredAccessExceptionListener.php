<?php

/**
 * Dossiq: a document stored with an access exception gets that exception applied
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Zaakdossier\DeclaredAccessExceptions;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands every stored document to DeclaredAccessExceptions, which acts only when an exception is out of step.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 */
class DeclaredAccessExceptionListener implements IEventListener {

	/**
	 * The document schema this listener answers to.
	 */
	public const SCHEMA = 'informatieobject';

	/**
	 * Constructor.
	 *
	 * @param DeclaredAccessExceptions $exceptions   Applies an exception that is out of step.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves a schema id to its slug.
	 * @param LoggerInterface          $logger       Where a failure is reported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DeclaredAccessExceptions $exceptions,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the exception of the document a create or an update stored.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
	 */
	public function handle(Event $event): void {
		$record = null;
		$before = null;
		if ($event instanceof ObjectCreatedEvent) {
			$record = $this->recordOf(object: $event->getObject());
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$record = $this->recordOf(object: $event->getNewObject());
			$before = $this->recordOf(object: $event->getOldObject());
		}

		if ($record === null || $this->slugResolver->resolveFromPayload(payload: $record) !== self::SCHEMA) {
			return;
		}

		try {
			$this->exceptions->apply(record: $record, before: $before);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the access exception stored on a document could not be applied',
				['app' => Application::APP_ID, 'documentId' => (string)($record['id'] ?? ''), 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * A stored object as a record with its `id`, or null when it cannot be read.
	 *
	 * @param mixed $object The stored object.
	 *
	 * @return array<string, mixed>|null The record.
	 */
	private function recordOf(mixed $object): ?array {
		if (is_object($object) === false || method_exists($object, 'jsonSerialize') === false) {
			return null;
		}

		$record = $object->jsonSerialize();
		if (is_array($record) === false) {
			return null;
		}

		$record['id'] = (string)($record['id'] ?? ($record['@self']['id'] ?? ''));

		return $record;
	}//end recordOf()
}//end class
