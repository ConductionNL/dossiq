<?php

/**
 * Dossiq acknowledgement-on-create listener.
 *
 * The trigger the ontvangstbevestiging never had. It observes the same
 * OpenRegister `ObjectCreatedEvent` on the `case` schema that
 * {@see DeadlineCaseCreatedListener} already watches, and queues the
 * acknowledgement of receipt for a case that owes one.
 *
 * WHY A LISTENER AND NOT A WORKFLOW STEP. Awb 4:3a owes a confirmation to every
 * electronically submitted message. Drawn as a step in a process, every case
 * type has to remember it, and a case type that forgets breaks a statutory duty
 * with nothing to say so. Hung off creation, the duty is a property of
 * receiving something, which is what it is.
 *
 * WHY IT QUEUES AND SENDS NOTHING ITSELF. Creating a case must not fail because
 * a mail server is down. A duty deferred by four minutes is met; a case that
 * failed to be created is not. So this decides only that a case was created and
 * hands the rest to {@see AcknowledgementDispatchJob}, which runs off the
 * request and records both outcomes on the case.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Queues the acknowledgement of receipt for a freshly created case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */
class AcknowledgementOnCreateListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IJobList                 $jobList      The background job list.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param LoggerInterface          $logger       The logger.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		// 🔴 THE SCHEMA GUARD RESOLVES, IT DOES NOT READ. The payload carries
		// the schema as an ID and `@self` has no `schemaSlug` key, so reading
		// either straight off the payload short-circuits this guard on EVERY
		// object. That is precisely how the sibling deadline listener bound no
		// term for months, quietly. Resolution goes through the shared
		// resolver, which is also what the unit tests drive.
		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		if ($caseId === '') {
			$this->logger->warning(
				'Dossiq acknowledgement: a case was created without an id, so receipt cannot be confirmed'
			);

			return;
		}

		$this->jobList->add(
			AcknowledgementDispatchJob::class,
			['caseId' => $caseId, 'attempt' => 1]
		);
	}//end handle()

	/**
	 * Extract the OpenRegister object array from an event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function extractObject(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
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
	}//end extractObject()
}//end class
