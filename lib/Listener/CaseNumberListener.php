<?php

/**
 * Dossiq case-number listener.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseNumberService;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Backfills the case number when the register did not generate one.
 *
 * A pure observer (ADR-022): every rule about what a number looks like lives
 * in {@see CaseNumberService}, and the service is a no-op on an instance whose
 * OpenRegister already filled the field.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseNumberListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CaseNumberService        $caseNumbers  The case-number service.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 */
	public function __construct(
		private readonly CaseNumberService $caseNumbers,
		private readonly ObjectSchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		// The payload carries the schema as an ID, never as a slug — reading
		// `@self.schemaSlug` answers an empty string for every object, which is
		// how the sibling termijn listener spent months never firing. The
		// shared resolver is the one place that converts.
		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$this->caseNumbers->assign(case: $payload);
	}//end handle()

	/**
	 * Extract the OpenRegister object array from an event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload, or null when the event
	 *                                   carries none.
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
