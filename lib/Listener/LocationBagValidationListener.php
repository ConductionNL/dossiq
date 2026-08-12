<?php

/**
 * Procest Location BAG Validation Listener
 *
 * Enforces the `location` schema's documented `source: bag` ⇒
 * `nummeraanduidingId` contract at save time (closes the
 * `bag-register-adapter` tasks.md item 4.1 follow-up). When a `location`
 * payload claims `source = bag`, `nummeraanduidingId` MUST be present and
 * syntactically valid (16-digit BAG identificatie). When the BAG adapter
 * (`bag-register-adapter`) resolves to a live/test tier, this listener
 * additionally attempts a best-effort existence check via
 * `BagAdapterInterface::lookupObject('nummeraanduiding', ...)` and rejects
 * only on a definitive `NOT_FOUND` — every other outcome (dormant/log-mode
 * adapter, transport error, `LOOKUP_DEFERRED`) accepts the save with a
 * logged warning rather than rejecting it, mirroring the adapter's own
 * dormant/fail-soft contract instead of turning it into a hard dependency
 * for every location save.
 *
 * Hooks into OpenRegister's pre-persist `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` (`StoppableEventInterface`) — the same generic
 * object-save event pipeline every procest schema save goes through (no
 * dedicated `LocationService` write path exists at HEAD — see
 * `openspec/changes/bag-location-save-validation/design.md` §2). Unlike the
 * post-persist `ObjectCreatedEvent`/`ObjectUpdatedEvent` pair
 * `ChecklistRunImmutabilityListener` uses, stopping propagation here
 * prevents the invalid row from ever reaching the database.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Service\External\Bag\BagAdapterInterface;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reject `location` saves whose `source = bag` claim lacks a valid
 * `nummeraanduidingId`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md
 */
class LocationBagValidationListener implements IEventListener {
	/**
	 * 16-digit BAG nummeraanduiding identificatie shape.
	 */
	private const NUMMERAANDUIDING_PATTERN = '/^\d{16}$/';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param BagAdapterInterface $bagAdapter BAG lookup port (dormant
	 *                                        `LogBagAdapter` by
	 *                                        default).
	 * @param IL10N $l10n Translation service.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BagAdapterInterface $bagAdapter,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist location save and reject an invalid BAG claim.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getNewObject());
			return;
		}
	}//end handle()

	/**
	 * Inspect the entity being created/updated and reject the save on the
	 * (narrowed-type) event when the `location` BAG rule matrix fails.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-persist
	 *                                                       event
	 *                                                       (`StoppableEventInterface`).
	 * @param ObjectEntity $entity The entity
	 *                             being
	 *                             created/updated.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		try {
			$payload = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Procest: location BAG validation could not read the object payload: ' . $e->getMessage()
			);
			return;
		}//end try

		if ($this->isLocationSchema(object: $payload) === false) {
			return;
		}

		$errors = $this->validate(payload: $payload);
		if ($errors === []) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->buildMessage(codes: $errors),
				'codes' => $errors,
			]
		);
		$event->stopPropagation();
	}//end inspect()

	/**
	 * Whether the supplied payload belongs to the `location` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool
	 */
	private function isLocationSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('location_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isLocationSchema()

	/**
	 * Validate the `source = bag` rule matrix, returning error codes (empty
	 * array = valid).
	 *
	 * @param array<string, mixed> $payload Location payload.
	 *
	 * @return string[]
	 */
	private function validate(array $payload): array {
		$source = (string)($payload['source'] ?? '');
		if ($source !== 'bag') {
			return [];
		}

		$id = trim((string)($payload['nummeraanduidingId'] ?? ''));
		if ($id === '') {
			return ['nummeraanduidingId.required'];
		}

		if (preg_match(self::NUMMERAANDUIDING_PATTERN, $id) !== 1) {
			return ['nummeraanduidingId.invalid'];
		}

		if ($this->existenceCheckFails(id: $id) === true) {
			return ['nummeraanduidingId.unknown'];
		}

		return [];
	}//end validate()

	/**
	 * Best-effort BAG existence verification. Fail-open on every outcome
	 * except a definitive NOT_FOUND — a dormant adapter, a transport error,
	 * or an inconclusive lookup status all accept the save with a logged
	 * warning rather than rejecting it.
	 *
	 * @param string $id BAG nummeraanduiding identificatie (already
	 *                   format-validated).
	 *
	 * @return bool TRUE only when the adapter definitively could not find
	 *              the id.
	 */
	private function existenceCheckFails(string $id): bool {
		try {
			if ($this->bagAdapter->isDormant() === true) {
				// Log-mode (the default): skip remote verification entirely.
				return false;
			}

			$result = $this->bagAdapter->lookupObject('nummeraanduiding', $id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: BAG existence check for nummeraanduidingId failed, accepting with warning',
				['nummeraanduidingId' => $id, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		if ($result->lookupStatus === 'NOT_FOUND') {
			return true;
		}

		if ($result->lookupStatus !== 'FOUND') {
			$this->logger->info(
				'Procest: BAG existence check for nummeraanduidingId inconclusive, accepting with warning',
				['nummeraanduidingId' => $id, 'lookupStatus' => $result->lookupStatus]
			);
		}

		return false;
	}//end existenceCheckFails()

	/**
	 * Build the translated, user-facing rejection message for the first
	 * applicable error code (priority: required, then invalid, then
	 * unknown).
	 *
	 * @param string[] $codes Emitted error codes.
	 *
	 * @return string
	 */
	private function buildMessage(array $codes): string {
		$messages = [
			'nummeraanduidingId.required' => $this->l10n->t(
				'A BAG nummeraanduiding ID is required when the location source is "bag".'
			),
			'nummeraanduidingId.invalid' => $this->l10n->t(
				'The BAG nummeraanduiding ID must be a 16-digit number.'
			),
			'nummeraanduidingId.unknown' => $this->l10n->t(
				'The BAG nummeraanduiding ID could not be found in the BAG register.'
			),
		];

		foreach ($codes as $code) {
			if (isset($messages[$code]) === true) {
				return $messages[$code];
			}
		}

		return $this->l10n->t('The location could not be saved: the BAG reference is invalid.');
	}//end buildMessage()
}//end class
