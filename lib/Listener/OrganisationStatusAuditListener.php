<?php

/**
 * Organisation Status Audit Listener
 *
 * A tenant's status is OpenRegister's now. When `TenantLifecycleService`
 * moves an Organisation, `OrganisationMapper::update()` dispatches
 * `OrganisationUpdatedEvent` with the organisation before and after. This
 * listener writes the change to the tenant's audit trail, the row dossiq's
 * own tenant admin store used to write before it retired, and on termination
 * it records the billing events nobody invoiced yet.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes an Organisation's status change to its tenant audit trail.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */
class OrganisationStatusAuditListener implements IEventListener {
	/**
	 * The statuses that end a tenant's access. After either, nobody invoices
	 * the month that just ran, so the unsettled billing count is taken.
	 */
	private const ENDING_STATUSES = ['deprovisioning', 'retained'];

	/**
	 * Constructor.
	 *
	 * @param TenantAuditTrailService $auditTrail  Writes the audit row on the tenant anchor.
	 * @param TenantBillingService    $billing     Reads this month's billing events.
	 * @param IUserSession            $userSession The acting user, when there is one.
	 * @param LoggerInterface         $logger      Logger.
	 */
	public function __construct(
		private readonly TenantAuditTrailService $auditTrail,
		private readonly TenantBillingService $billing,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a status change; ignore every other update.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof OrganisationUpdatedEvent) === false) {
			return;
		}

		$before = (string) ($event->getOldOrganisation()->getStatus() ?? '');
		$after = (string) ($event->getNewOrganisation()->getStatus() ?? '');
		$uuid = (string) ($event->getNewOrganisation()->getUuid() ?? '');
		if ($before === $after || $uuid === '') {
			return;
		}

		$resource = 'tenant:'.$uuid.' '.$before.'->'.$after;
		if (in_array($after, self::ENDING_STATUSES, true) === true) {
			$resource .= $this->unsettledBillingNote(tenantId: $uuid);
		}

		$this->auditTrail->emit(
			[
				'action' => 'tenant.status_changed',
				'actor' => $this->actor(),
				'role' => 'tenant-admin',
				'resource' => $resource,
				'tenantId' => $uuid,
			]
		);
	}//end handle()

	/**
	 * What to add to the audit line when a tenant ends owing money.
	 *
	 * 🔴 IT GOES IN THE AUDIT TRAIL, NOT ONLY IN THE LOG. Ending a tenant is
	 * the moment after which nobody invoices the month that just ran. The
	 * audit trail is the record consulted afterwards when somebody asks where
	 * the money went. It does not refuse anything: OpenRegister already made
	 * the change, and an operator winding up a tenant must be able to finish.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string The note, or an empty string when there is nothing to say.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	private function unsettledBillingNote(string $tenantId): string {
		try {
			$events = $this->billing->fetchEventsForMonth(
				tenantId: $tenantId,
				month: (new DateTimeImmutable('now'))->format('Y-m'),
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not count unsettled billing events', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
			return ' (unsettled billing events: unknown)';
		}

		$unsettled = 0;
		foreach ($events as $event) {
			if (($event['invoiceRef'] ?? null) === null) {
				$unsettled++;
			}
		}

		if ($unsettled === 0) {
			return '';
		}

		$this->logger->warning(
			'Dossiq: a tenant ended with unsettled billing events; the Shillinq export has to run',
			['tenantId' => $tenantId, 'unsettledEvents' => $unsettled]
		);

		return ' (unsettled billing events: '.$unsettled.')';
	}//end unsettledBillingNote()

	/**
	 * The acting user, or `system` for a background change.
	 *
	 * @return string The actor.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end actor()
}//end class
