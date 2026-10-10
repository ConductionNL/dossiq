<?php

/**
 * Dossiq Woo start-from listener
 *
 * A new Woo case that names an earlier one in `wooStartFrom` starts from that
 * request's configuration: its custodians, systems and terms land in a draft
 * search plan the handler completes and records (woo-request-corpus-collection,
 * REQ-WRC-005). It listens on OpenRegister's case creation, which is the one
 * path every case takes, whether the case page made it or WooRequestIntake
 * did, so there is no second create path.
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
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies an earlier Woo request's configuration into a new case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
 */
class WooStartFromListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param WooSearchPlans           $plans        Copies the configuration and writes the draft plan.
	 * @param ObjectSchemaSlugResolver $slugResolver Tells a case from any other object.
	 * @param LoggerInterface          $logger       Logs a copy that could not be made.
	 */
	public function __construct(
		private readonly WooSearchPlans $plans,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Copy the configuration when a new case names an earlier request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return;
		}

		$from = trim((string)($object['wooStartFrom'] ?? ''));
		$self = (array)($object['@self'] ?? []);
		$caseId = trim((string)($object['id'] ?? ($object['uuid'] ?? ($self['id'] ?? ''))));
		if ($from === '' || $caseId === '' || $this->slugResolver->resolveFromPayload(payload: $object) !== 'case') {
			return;
		}

		try {
			if ($this->plans->startFrom(caseId: $caseId, from: $from) === null) {
				$this->logger->warning('Dossiq Woo: the earlier request has no configuration to start from', ['case' => $caseId, 'from' => $from]);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq Woo: the earlier request could not be copied', ['case' => $caseId, 'from' => $from, 'error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
