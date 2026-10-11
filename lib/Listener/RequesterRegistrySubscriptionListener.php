<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Registry\RequesterRegistrySubscription;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * When a case names a BRP person or KvK company as its requester, keep that
 * row current from the source (contacts-domain 4.3, Tier B B22).
 *
 * After the save, never before it: the subscription is OpenRegister's and
 * integriq's work, and a case is filed whatever they answer. Only a requester
 * that is NEW on this save is asked about, so editing a case's title does not
 * reach the BRP.
 *
 * @spec openspec/changes/contacts-domain/tasks.md#4-the-contact-reference-on-a-contact-moment
 *
 * @template-implements IEventListener<Event>
 */
class RequesterRegistrySubscriptionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolver       $slugResolver  Tells a case from any other object.
	 * @param RequesterRegistrySubscription  $subscriptions Requests the subscription.
	 * @param LoggerInterface                $logger        Logs a failure without failing the save.
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly RequesterRegistrySubscription $subscriptions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case created or updated.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contacts-domain/tasks.md#4-the-contact-reference-on-a-contact-moment
	 */
	public function handle(Event $event): void {
		try {
			if (($event instanceof ObjectCreatedEvent) === true) {
				$this->consider(new: $event->getObject(), old: null);
				return;
			}

			if (($event instanceof ObjectUpdatedEvent) === true) {
				$this->consider(new: $event->getNewObject(), old: $event->getOldObject());
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the requester subscription of a case failed; the case itself is saved',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Subscribe the requester when this save put a new one on a case.
	 *
	 * @param ObjectEntity|null $new The object as saved.
	 * @param ObjectEntity|null $old The object before, null on a create.
	 *
	 * @return void
	 */
	private function consider(?ObjectEntity $new, ?ObjectEntity $old): void {
		if ($new === null) {
			return;
		}

		// The serialised form, which carries `@self.schema`; the bare object
		// data does not say which schema it belongs to.
		$case = $new->jsonSerialize();
		if ($this->slugResolver->resolveFromPayload(payload: $case) !== 'case') {
			return;
		}

		$requester = self::reference(value: ($case['requester'] ?? ''));
		if ($requester === '') {
			return;
		}

		if ($old !== null && self::reference(value: ($old->jsonSerialize()['requester'] ?? '')) === $requester) {
			return;
		}

		$this->subscriptions->subscribe(objectUuid: $requester);
	}//end consider()

	/**
	 * A reference as a uuid string, whether stored bare or as an object.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The uuid, '' when none.
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end reference()
}//end class
