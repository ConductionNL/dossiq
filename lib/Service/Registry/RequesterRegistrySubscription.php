<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Registry
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

namespace OCA\Dossiq\Service\Registry;

use OCA\Dossiq\Kernel\Contracts\RequesterRegistrySubscriptionInterface;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ask OpenRegister to keep a case's requester current from its source register.
 *
 * Tier B item B22: a `brpPerson` or `kvkCompany` row was looked up once and
 * never refreshed. OpenRegister's registry-subscriptions (openregister#3656)
 * keeps such a row current IN PLACE: the schema declares
 * `x-openregister-registry` (the registry, the identity property, the
 * properties the registry owns), a subscription is requested per object, and
 * integriq's connector (`registry-subscription-connector`) subscribes at BRP
 * or KvK and posts each change back to OpenRegister, which applies only the
 * owned properties. Dossiq holds no copy and runs no poller.
 *
 * What dossiq decides is WHICH rows are worth keeping current: the ones a case
 * names as its requester. A row nobody filed a case for is not subscribed,
 * because a volgindicatie at the BRP is a processing act with a purpose, and
 * "it is in the register set" is not one.
 *
 * 🔴 A SUBSCRIPTION ALREADY REQUESTED OR ACTIVE IS LEFT ALONE. OpenRegister's
 * requestSubscription() resets the row to `requested` and notifies the
 * connector again, so calling it on every case save would re-subscribe the
 * same person at the BRP each time somebody edits the case.
 *
 * 🔴 BEST EFFORT. Everything here is answered, never thrown: a case save must
 * not fail because OpenRegister predates the capability or a row has no BSN.
 *
 * @spec openspec/changes/contacts-domain/tasks.md#4-the-contact-reference-on-a-contact-moment
 */
class RequesterRegistrySubscription implements RequesterRegistrySubscriptionInterface {

	/**
	 * OpenRegister's registry subscription service (openregister#3656).
	 */
	public const REGISTRY_SERVICE = 'OCA\\OpenRegister\\Service\\Registry\\RegistrySubscriptionService';

	/**
	 * OpenRegister's schema mapper, to turn the object's schema id into a Schema.
	 */
	public const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The subscription states that need no new request.
	 *
	 * @var array<int, string>
	 */
	private const LIVE_STATES = ['requested', 'active'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Resolves OpenRegister's services, null when absent.
	 * @param LoggerInterface $logger   Says why a requester was not subscribed.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Request a registry subscription for the object a case names as requester.
	 *
	 * @param string $objectUuid The requester's object uuid.
	 *
	 * @return array{requested: bool, reason: string} Whether a request went out, and why not.
	 *
	 * @spec openspec/changes/contacts-domain/tasks.md#4-the-contact-reference-on-a-contact-moment
	 */
	public function subscribe(string $objectUuid): array {
		$objectUuid = trim($objectUuid);
		if ($objectUuid === '') {
			return ['requested' => false, 'reason' => 'the case names no requester'];
		}

		$run = $this->subscriptionRun(objectUuid: $objectUuid);
		if ($run === null) {
			return ['requested' => false, 'reason' => 'this OpenRegister has no registry subscriptions'];
		}

		try {
			return $this->requestFor(run: $run);
		} catch (Throwable $e) {
			// No identity value, an unreachable row, a refused request: the
			// case is saved either way, and the reason is logged once here.
			$this->logger->info(
				'Dossiq: the requester of a case was not subscribed at its source register: ' . $e->getMessage(),
				['object' => $objectUuid]
			);

			return ['requested' => false, 'reason' => $e->getMessage()];
		}//end try
	}//end subscribe()

	/**
	 * OpenRegister's registry service, schema mapper and object service for one requester, or null when one is missing.
	 *
	 * @param string $objectUuid The requester's object UUID.
	 *
	 * @return SubscriptionRun|null The run.
	 */
	private function subscriptionRun(string $objectUuid): ?SubscriptionRun {
		$registry = $this->settings->getOpenRegisterClass(class: self::REGISTRY_SERVICE);
		$schemas = $this->settings->getOpenRegisterClass(class: self::SCHEMA_MAPPER);
		$objects = $this->settings->getObjectService();
		if ($registry === null || $schemas === null || $objects === null) {
			return null;
		}

		$run = new SubscriptionRun();
		$run->registry = $registry;
		$run->schemas = $schemas;
		$run->objects = $objects;
		$run->objectUuid = $objectUuid;

		return $run;
	}//end subscriptionRun()

	/**
	 * Ask the source register to keep the requester, unless no register owns it or it is already subscribed.
	 *
	 * @param SubscriptionRun $run The request.
	 *
	 * @return array{requested: bool, reason: string} The outcome.
	 */
	private function requestFor(SubscriptionRun $run): array {
		$object = $run->objects->find(id: $run->objectUuid);
		if (is_object($object) === false) {
			return ['requested' => false, 'reason' => 'the requester could not be read'];
		}

		$schema = $run->schemas->find($object->getSchema());
		if ($run->registry->annotationFor($schema) === null) {
			// A Nextcloud contact, or any record no registry owns.
			return ['requested' => false, 'reason' => 'the requester is not kept by a source register'];
		}

		if ($this->isLive(state: $run->registry->stateFor($run->objectUuid)) === true) {
			return ['requested' => false, 'reason' => 'already subscribed'];
		}

		$run->registry->requestSubscription($object, $schema);

		return ['requested' => true, 'reason' => ''];
	}//end requestFor()

	/**
	 * Whether a subscription state is one that needs no new request.
	 *
	 * @param mixed $state What the registry answered for the requester.
	 *
	 * @return bool True when a request is already requested or active.
	 *
	 * @spec openspec/changes/contacts-domain/tasks.md#4-the-contact-reference-on-a-contact-moment
	 */
	private function isLive(mixed $state): bool {
		return is_array($state) === true
			&& in_array((string)($state['state'] ?? ''), self::LIVE_STATES, true) === true;
	}//end isLive()
}//end class
