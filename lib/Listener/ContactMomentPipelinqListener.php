<?php

/**
 * Appends a contact moment logged on a case to pipelinq's record, whichever
 * surface logged it.
 *
 * THE APPEND MOVED HERE FROM `ContactMomentService` FOR THE SAME REASON THE
 * TIMELINE WRITE DID ({@see ContactMomentTimelineListener}). The case page's
 * Log contact form is a manifest `open-form` action that saves straight to
 * `/apps/openregister/api/objects` and runs no dossiq service, so a bridge call
 * inside the service never saw a moment logged on a case. The one path that did
 * run the service, the KCC werkplek, never carries a case, so the bridge
 * answered "names no case" on every call it got. REQ-PLQ-02 had a tested
 * bridge and no moment that could reach it.
 *
 * ONE WRITER. An `ObjectCreatedEvent` fires on both paths, so binding the append
 * to the event covers both exactly once; the service no longer calls the bridge.
 *
 * IT NEVER THROWS. The dossiq record is already saved when this runs. The bridge
 * logs a refusal with its reason, and anything else that goes wrong is logged
 * here and dropped, because a throw would undo nothing and fail the request
 * that saved the handler's work.
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
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands every created contact moment that names a case to the pipelinq bridge.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
class ContactMomentPipelinqListener implements IEventListener {

	/**
	 * The schema slug of dossiq's contact moment.
	 */
	public const SCHEMA = 'contactmoment';

	/**
	 * Constructor.
	 *
	 * @param ContactMomentBridge      $bridge       Appends the moment to pipelinq.
	 * @param ObjectSchemaSlugResolver $slugResolver Which schema a created object is.
	 * @param LoggerInterface          $logger       Where an unexpected failure goes.
	 */
	public function __construct(
		private readonly ContactMomentBridge $bridge,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Append a created contact moment that names a case.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->payload(event: $event);
		if ($payload === null) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== self::SCHEMA) {
			return;
		}

		$caseId = trim((string)($payload['case'] ?? ''));
		if ($caseId === '') {
			return;
		}

		try {
			$this->bridge->append(caseId: $caseId, moment: $payload);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq pipelinq: a contact moment on case {case} was saved here and not appended there: {reason}',
				['app' => 'dossiq', 'case' => $caseId, 'reason' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The created object as an array, or null when it cannot be read.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function payload(Event $event): ?array {
		$object = null;

		if (method_exists($event, 'getObject') === true) {
			$object = $event->getObject();
		}

		if (is_array($object) === true) {
			return $object;
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
