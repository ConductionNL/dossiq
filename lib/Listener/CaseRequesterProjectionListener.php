<?php

/**
 * Dossiq case requester projection listener.
 *
 * Writes a case's requester projection (`initiatorType`, `initiatorSourceId`,
 * `initiatorDisplayName`) in the same save as the `requester` uuid it comes
 * from. A form writes the uuid alone, and the case list reads the projection,
 * so a projection made later (on the case page) left the list naming the
 * previous requester, or nobody, until somebody opened the case.
 *
 * It runs on OpenRegister's PRE-persist events, as the priority derivation
 * does, so there is no second write. It acts only when the uuid is set and
 * either changed or still has no projection: a save that leaves the requester
 * alone leaves the projection alone, and a requester the register sets cannot
 * resolve leaves whatever was written. A case without a requester uuid (a
 * Nextcloud contact as requester) keeps the projection the picker wrote.
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
 * @spec openspec/specs/initiator-display/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\People\RequesterProjection;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project a case's requester in the save that sets it.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
class CaseRequesterProjectionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Schema slug bridge.
	 * @param RequesterProjection $projection      The projection of a requester uuid.
	 * @param LoggerInterface     $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly RequesterProjection $projection,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Project the requester of a case about to be written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/initiator-display/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->apply(event: $event, entity: $event->getObject(), previous: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->apply(event: $event, entity: $event->getNewObject(), previous: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Write the projection onto the case being saved, when its requester asks for one.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-persist event.
	 * @param ObjectEntity                            $entity   The case being written.
	 * @param ObjectEntity|null                       $previous The case as it was.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity, ?ObjectEntity $previous): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$modified = $event->getModifiedData();
		$payload = array_merge($payload, $modified);
		$requester = trim((string)($payload['requester'] ?? ''));
		if ($requester === '') {
			return;
		}

		$before = '';
		if ($previous !== null) {
			$before = trim((string)(($this->payload(entity: $previous) ?? [])['requester'] ?? ''));
		}

		$unprojected = trim((string)($payload['initiatorDisplayName'] ?? '')) === '';
		if ($requester === $before && $unprojected === false) {
			return;
		}

		$projection = $this->projection->forRequester(uuid: $requester);
		if ($projection === []) {
			return;
		}

		$event->setModifiedData(array_merge($modified, $projection));
	}//end apply()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: requester projection could not read the payload: ' . $e->getMessage());
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return boolean True when this is a case.
	 */
	private function isCaseSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && ($candidate === $expected || str_ends_with($candidate, '/' . $expected));
	}//end isCaseSchema()
}//end class
