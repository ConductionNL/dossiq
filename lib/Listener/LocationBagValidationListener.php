<?php

/**
 * Dossiq Location BAG Validation Listener
 *
 * Enforces the `location` schema's `source: bag` contract at save time.
 * When a `location` payload claims `source = bag`, its nummeraanduiding id
 * MUST be present and be a 16-digit BAG identificatie. Whether the BAG holds
 * that nummeraanduiding is asked of integriq, and only where an administrator
 * switched the check on (`integration.bag.check = on`). Where the check is on
 * and no clear answer comes, the save is refused (decision 224: a guard
 * refuses when it is unsure). Where it is off, the location is saved and
 * marked `not-checked`, so a screen can say nobody verified the address.
 *
 * Hooks into OpenRegister's pre-persist `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` (`StoppableEventInterface`) — the same generic
 * object-save event pipeline every dossiq schema save goes through (no
 * dedicated `LocationService` write path exists at HEAD — see
 * `openspec/changes/bag-location-save-validation/design.md` §2). Unlike the
 * post-persist `ObjectCreatedEvent`/`ObjectUpdatedEvent` pair
 * `ChecklistRunImmutabilityListener` uses, stopping propagation here
 * prevents the invalid row from ever reaching the database.
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
 * @spec openspec/specs/bag-location-save-validation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCA\Dossiq\Service\Registry\BagObjectReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reject `location` saves whose `source = bag` claim is malformed, unknown to
 * the BAG, or could not be checked while the check is switched on.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
 */
class LocationBagValidationListener implements IEventListener {
	/**
	 * 16-digit BAG nummeraanduiding identificatie shape.
	 */
	private const NUMMERAANDUIDING_PATTERN = '/^\d{16}$/';

	/**
	 * The value of `integration.bag.check` that switches the check on.
	 */
	public const CHECK_ON = 'on';

	/**
	 * The field on the location that says whether the BAG confirmed it.
	 */
	public const CHECK_FIELD = 'bagCheck';

	/**
	 * The BAG confirmed the nummeraanduiding.
	 */
	public const CHECKED = 'checked';

	/**
	 * The check is switched off, so nobody asked the BAG.
	 */
	public const NOT_CHECKED = 'not-checked';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param IntegrationMode $integrationMode Reads `integration.bag.check`.
	 * @param BagObjectReader $bagReader Asks integriq whether the BAG holds an object.
	 * @param IL10N $l10n Translation service.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntegrationMode $integrationMode,
		private readonly BagObjectReader $bagReader,
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
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
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
	 * Inspect the entity being saved: refuse it, or record how it was checked.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The entity being saved.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		try {
			$payload = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: location BAG validation could not read the object payload: ' . $e->getMessage()
			);
			return;
		}//end try

		if ($this->isLocationSchema(object: $payload) === false
			|| (string)($payload['source'] ?? '') !== 'bag'
		) {
			return;
		}

		$outcome = $this->validate(payload: $payload);
		if (in_array($outcome, [self::CHECKED, self::NOT_CHECKED], true) === true) {
			$entity->setObject(array_merge($entity->getObject(), [self::CHECK_FIELD => $outcome]));
			return;
		}

		$event->setErrors(
			[
				'message' => $this->buildMessage(code: $outcome),
				'codes' => [$outcome],
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
	 * The outcome for a `source = bag` location: `checked` or `not-checked`
	 * to save it, or an error code to refuse it.
	 *
	 * @param array<string, mixed> $payload Location payload.
	 *
	 * @return string
	 */
	private function validate(array $payload): string {
		$id = trim((string)($payload['addressDesignationId'] ?? ''));
		if ($id === '') {
			return 'nummeraanduidingId.required';
		}

		if (preg_match(self::NUMMERAANDUIDING_PATTERN, $id) !== 1) {
			return 'nummeraanduidingId.invalid';
		}

		if ($this->integrationMode->setting(integration: 'bag', key: 'check') !== self::CHECK_ON) {
			return self::NOT_CHECKED;
		}

		$answer = $this->bagReader->read('nummeraanduiding', $id);
		if ($answer['available'] === false || $answer['simulated'] === true) {
			$this->logger->warning(
				'Dossiq: the BAG check gave no clear answer, so the location save is refused',
				['reason' => $answer['reason'], 'simulated' => $answer['simulated']]
			);
			return self::uncheckedCode(reason: $answer['reason']);
		}

		if ($answer['found'] === false) {
			return 'nummeraanduidingId.unknown';
		}

		return self::CHECKED;
	}//end validate()

	/**
	 * The refusal code for a check that got no clear answer.
	 *
	 * Where integriq is absent or too old the administrator has one thing to
	 * do, so the message names it; every other reason reads "try again later".
	 *
	 * @param string $reason Why the BAG read gave no answer.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
	 */
	private static function uncheckedCode(string $reason): string {
		return match ($reason) {
			BagObjectReader::REASON_NO_INTEGRIQ => 'nummeraanduidingId.integriq-missing',
			BagObjectReader::REASON_NO_READER => 'nummeraanduidingId.integriq-outdated',
			default => 'nummeraanduidingId.unchecked',
		};
	}//end uncheckedCode()

	/**
	 * The translated, user-facing refusal for an error code.
	 *
	 * @param string $code The error code.
	 *
	 * @return string
	 */
	private function buildMessage(string $code): string {
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
			'nummeraanduidingId.unchecked' => $this->l10n->t(
				'The address could not be checked in the BAG, so the location was not saved. Try again later, or ask an administrator.'
			),
			'nummeraanduidingId.integriq-missing' => $this->l10n->t(
				'The BAG check is on, and integriq must be installed for it, so the location was not saved. Ask an administrator.'
			),
			'nummeraanduidingId.integriq-outdated' => $this->l10n->t(
				'The BAG check is on, and integriq must be updated to read the BAG, so the location was not saved. Ask an administrator.'
			),
		];

		return ($messages[$code] ?? $this->l10n->t('The location could not be saved: the BAG reference is invalid.'));
	}//end buildMessage()
}//end class
