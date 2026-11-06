<?php

/**
 * Dossiq quota consumption listener.
 *
 * Creating a case or adding a user to a tenant consumes the tenant's quota.
 * Before the save the create is refused when it would pass a blocking limit;
 * after the save it is counted.
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
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\QuotaGate;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuse a create past the tenant's quota, count the ones that are saved.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */
class QuotaConsumptionListener implements IEventListener {

	use SavedObjectPayload;

	/**
	 * Constructor.
	 *
	 * @param QuotaGate          $gate         Checks and counts the quota.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves the created object's schema slug.
	 */
	public function __construct(
		private readonly QuotaGate $gate,
		private readonly ObjectSchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Refuse before the save, count after it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$object = $this->savedObject(event: $event);
		if ($object === null) {
			return;
		}

		$quotaType = $this->gate->quotaTypeFor(schemaSlug: $this->slugResolver->resolveFromPayload(payload: $object));
		if ($quotaType === null) {
			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			$this->gate->record(quotaType: $quotaType);
			return;
		}

		$refusal = $this->gate->refusal(quotaType: $quotaType);
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end handle()
}//end class
