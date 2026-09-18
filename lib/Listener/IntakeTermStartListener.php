<?php

/**
 * Stamps a new case with when it arrived and when its clock starts.
 *
 * 🔴 ON THE CREATE EVENT, SO EVERY INTAKE PATH IS COVERED. A case reaches
 * dossiq from the New case form, from the Mail sidebar, from an unmatched mail,
 * from a Forms submission, from the ZGW API and from an import. Only two of
 * those run a dossiq service, so a stamp written in a service would be missing
 * from the paths a citizen actually files through — which is every path that
 * matters here. `ObjectCreatedEvent` is crossed exactly once by all of them.
 *
 * 🔴 IT STAMPS ONCE AND NEVER RE-STAMPS. A case that already carries
 * `receivedAt` keeps it, including one imported with a `receivedAt` from
 * another system: the request arrived when it arrived, whatever day dossiq
 * first saw the record. Re-stamping would quietly move the date a citizen was
 * given.
 *
 * It never throws. A case that could not be stamped is a case with no clock
 * sentence on its confirmation, which is what happened before this existed; a
 * case that could not be CREATED is worse.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Service\IntakeTermStart;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the two intake moments onto a case as it is created.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStartListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart          $intake       The two moments and the flag.
	 * @param ObjectSchemaSlugResolver $slugResolver Whether this create is a case.
	 * @param SettingsService          $settings     Bridge to OpenRegister.
	 * @param LoggerInterface          $logger       Where a failed stamp is noted.
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp one newly created case.
	 *
	 * @param Event $event The create event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		// The payload carries the schema as an ID, never as a slug: reading
		// `@self.schemaSlug` answers an empty string for every object, which is
		// how a sibling listener spent months never firing.
		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		if ($this->intake->isStamped(case: $payload) === true) {
			return;
		}

		$caseId = trim((string)($payload['id'] ?? ($payload['uuid'] ?? '')));
		if ($caseId === '') {
			return;
		}

		$this->stamp(caseId: $caseId, payload: $payload);
	}//end handle()

	/**
	 * Write the stamp back onto the case.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $payload The created case.
	 *
	 * @return void
	 */
	private function stamp(string $caseId, array $payload): void {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		$stamp = $this->intake->stampFor(received: new DateTimeImmutable());

		try {
			$objectService->saveObject(
				object: array_merge($payload, $stamp),
				register: $register,
				schema: $schema,
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			// A case with no stamp is a case whose confirmation says only what
			// it said yesterday. A case that could not be created is worse, so
			// this is a warning and never a throw.
			$this->logger->warning(
				'Dossiq intake: could not record when case {case} arrived and when its term starts',
				['case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end stamp()

	/**
	 * Extract the OpenRegister object array from an event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload, or null when there is none.
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
