<?php

/**
 * Stamp a new case with when it arrived and when its clock starts.
 *
 * A pure observer (ADR-022): the arithmetic is the engine calendar's, the
 * decision is {@see \OCA\Dossiq\Service\Intake\IntakeTermStart}'s, and this
 * class only carries the answer onto the record.
 *
 * 🔴 IT WRITES ONCE AND NEVER AGAIN. A case that already carries
 * `termStartsAt` is left alone, so a replayed create event cannot re-stamp it
 * against a calendar that has gained a holiday since. That is the whole of
 * "SHALL NOT be recomputed on read": the stamp is a record of what the citizen
 * was told, not a derivation of what today's calendar would say.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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

use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The two moments, written on the case the moment it exists.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class IntakeTermStartListener implements IEventListener {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart          $intake          The two moments and the flag between them.
	 * @param ObjectSchemaSlugResolver $slugResolver    Schema id-to-slug resolver.
	 * @param SettingsService          $settingsService Bridge to OpenRegister.
	 * @param LoggerInterface          $logger          Records a case that went unstamped.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly SettingsService $settingsService,
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
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$caseId = trim((string)($payload['id'] ?? ($payload['uuid'] ?? '')));
		if ($caseId === '' || $this->intake->isStamped(case: $payload) === true) {
			return;
		}

		$stamp = $this->intake->stampFor(receivedAt: $this->intake->arrivalOf(case: $payload));
		if ($stamp === []) {
			// Already logged by IntakeTermStart, naming the moment it could
			// not place. Nothing is written: a half stamp is a claim.
			return;
		}

		$this->write(case: $payload, caseId: $caseId, changes: $stamp);
	}//end handle()

	/**
	 * Write the stamp onto the stored case.
	 *
	 * @param array<string, mixed> $case    The case as the event carried it.
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $changes The three fields.
	 *
	 * @return void
	 */
	private function write(array $case, string $caseId, array $changes): void {
		$payload = array_merge($case, $changes);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			$objectService = $this->settingsService->getObjectService();
			$register = $this->settingsService->getConfigValue('register');
			$schema = $this->settingsService->getConfigValue('case_schema');
			if ($objectService === null || $register === '' || $schema === '') {
				return;
			}

			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq intake: the term start could not be written onto the case',
				['case' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end write()

	/**
	 * The created object as an array, however the event carries it.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload.
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
