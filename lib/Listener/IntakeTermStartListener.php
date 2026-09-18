<?php

/**
 * Stamping the two moments on a case the moment it is created.
 *
 * 🔴 WRITTEN ONCE, AT CREATION, AND NEVER RECOMPUTED (REQ-TERM-040). A case
 * that already carries the stamp is left exactly as it is, including one filed
 * on a day the calendar has since declared a holiday: what the citizen was
 * told in January is a fact about January, and a recomputed answer is not that
 * answer, it is today's.
 *
 * 🔴 THE SCHEMA IS RESOLVED THROUGH THE SHARED RESOLVER. The payload carries
 * the schema as an id and never as a slug, and reading `@self.schemaSlug`
 * answers an empty string for every object — which is how the sibling termijn
 * listener spent months never firing at all.
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
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Terms\IntakeTermStart;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes `receivedAt`, `termStartsAt` and the flag onto a new case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStartListener implements IEventListener {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart $termStart Computes the two moments.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves the schema id to a slug.
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 * @param ITimeFactory $time The clock, for a payload that names no arrival.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IntakeTermStart $termStart,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly SettingsService $settingsService,
		private readonly ITimeFactory $time,
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
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$stamp = $this->stampFor(case: $payload);
		if ($stamp === []) {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['@self']['id'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: $stamp,
			);
		} catch (Throwable $e) {
			// Logged rather than thrown: a case that was created and could not
			// be stamped is still a case, and throwing here would fail the
			// creation the citizen has already been told succeeded. The
			// missing stamp is visible in the confirmation, which says nothing
			// about a start it does not have.
			$this->logger->warning(
				'Dossiq intake: the term start could not be stamped on a new case',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
		}//end try
	}//end handle()

	/**
	 * The stamp to write, or an empty array when there is nothing to do.
	 *
	 * @param array<string, mixed> $case The created case.
	 *
	 * @return array<string, mixed> The fields to write.
	 */
	private function stampFor(array $case): array {
		// Already stamped: leave it exactly as it is. A case filed on a day
		// the calendar has since declared a holiday keeps the start it was
		// given, because that is what the citizen was told.
		if (trim((string)($case[IntakeTermStart::TERM_STARTS_AT] ?? '')) !== '') {
			return [];
		}

		$declared = trim((string)($case[IntakeTermStart::RECEIVED_AT] ?? ''));
		if ($declared !== '') {
			// An intake channel that knows when the submission arrived wins
			// over the moment this listener happened to run: a form posted at
			// 23:58 and processed at 00:02 arrived on the earlier day.
			try {
				$receivedAt = new DateTimeImmutable($declared);
			} catch (Throwable $e) {
				$receivedAt = DateTimeImmutable::createFromInterface($this->time->getDateTime());
			}
		} else {
			$receivedAt = DateTimeImmutable::createFromInterface($this->time->getDateTime());
		}

		return $this->termStart->stampFor(receivedAt: $receivedAt);
	}//end stampFor()

	/**
	 * Extract the OpenRegister object array from an event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
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
