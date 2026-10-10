<?php

/**
 * An Organisation's status change reaches the tenant audit trail, with what it still owed.
 *
 * Moved here from `TenantSaasService::updateStatus()`, which retired with the
 * tenant admin store (tenancy-onto-openregister-organisation 6.9). The event
 * is the real shape OpenRegister's `OrganisationMapper::update()` dispatches;
 * the audit writer is the real `TenantAuditTrailService` over an in-memory
 * OpenRegister, so the row is asserted where it lands.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\OrganisationStatusChangeListener;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Tests\Support\MakesTenantAnchors;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\OrganisationStatusChangeListener
 */
class OrganisationStatusChangeListenerTest extends TestCase {
	use MakesTenantAnchors;

	/**
	 * The tenant.
	 */
	private const ORG = '6b7c8d9e-0f1a-4b2c-8d3e-4f5a6b7c8d9e';

	/**
	 * Fresh store with the tenant's anchor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startAnchorStore();
		$this->anchorStore->seed(schema: 'tenant', uuid: self::ORG, row: ['slug' => 'zuiddrecht', 'displayName' => 'Gemeente Zuiddrecht']);
	}//end setUp()

	/**
	 * The listener over the real audit writer.
	 *
	 * @param TenantBillingService $billing The billing reader.
	 *
	 * @return OrganisationStatusChangeListener The listener.
	 */
	private function listener(TenantBillingService $billing): OrganisationStatusChangeListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OrganisationStatusChangeListener(
			auditTrail: $this->realTenantAuditTrail(),
			billing: $billing,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * The update event for a status move.
	 *
	 * @param string $from The status before.
	 * @param string $to   The status after.
	 *
	 * @return OrganisationUpdatedEvent The event.
	 */
	private function moved(string $from, string $to): OrganisationUpdatedEvent {
		$old = new Organisation();
		$old->setUuid(self::ORG);
		$old->setStatus($from);
		$new = new Organisation();
		$new->setUuid(self::ORG);
		$new->setStatus($to);

		return new OrganisationUpdatedEvent($new, $old);
	}//end moved()

	/**
	 * Ending a tenant with uninvoiced events records the count on its trail.
	 *
	 * @return void
	 */
	public function testEndingATenantWithUnsettledBillingRecordsTheCount(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->method('fetchEventsForMonth')->willReturn([['invoiceRef' => null], ['invoiceRef' => null], ['invoiceRef' => 'INV-1']]);

		$this->listener(billing: $billing)->handle($this->moved(from: 'active', to: 'deprovisioning'));

		$this->assertCount(1, $this->anchorTrail->rows);
		$row = $this->anchorTrail->rows[0];
		$this->assertSame(self::ORG, $row['object']);
		$this->assertSame('procest.tenant.tenant.status_changed', $row['action']);
		$this->assertSame('tenant:'.self::ORG.' active->deprovisioning (unsettled billing events: 2)', $row['context']['resource']);
		$this->assertSame('beheerder', $row['context']['actor']);
	}//end testEndingATenantWithUnsettledBillingRecordsTheCount()

	/**
	 * Retaining a tenant with nothing outstanding records the move and no note.
	 *
	 * @return void
	 */
	public function testEndingWithNothingOutstandingSaysNothingMore(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->method('fetchEventsForMonth')->willReturn([['invoiceRef' => 'INV-1']]);

		$this->listener(billing: $billing)->handle($this->moved(from: 'active', to: 'retained'));

		$this->assertSame('tenant:'.self::ORG.' active->retained', $this->anchorTrail->rows[0]['context']['resource']);
	}//end testEndingWithNothingOutstandingSaysNothingMore()

	/**
	 * A suspension is recorded and takes no billing count.
	 *
	 * @return void
	 */
	public function testSuspendingTakesNoBillingCount(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->expects($this->never())->method('fetchEventsForMonth');

		$this->listener(billing: $billing)->handle($this->moved(from: 'active', to: 'suspended'));

		$this->assertSame('tenant:'.self::ORG.' active->suspended', $this->anchorTrail->rows[0]['context']['resource']);
	}//end testSuspendingTakesNoBillingCount()

	/**
	 * An update that leaves the status alone, or another event, writes nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAStatusChangeWritesNothing(): void {
		$listener = $this->listener(billing: $this->createMock(TenantBillingService::class));

		$listener->handle($this->moved(from: 'active', to: 'active'));
		$listener->handle(new Event());

		$this->assertSame([], $this->anchorTrail->rows);
	}//end testAnUpdateWithoutAStatusChangeWritesNothing()

	/**
	 * A billing read that fails still records the move, saying the count is unknown.
	 *
	 * @return void
	 */
	public function testAFailedBillingReadStillRecordsTheMove(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->method('fetchEventsForMonth')->willThrowException(new \RuntimeException('register down'));

		$this->listener(billing: $billing)->handle($this->moved(from: 'suspended', to: 'retained'));

		$this->assertStringEndsWith('(unsettled billing events: unknown)', $this->anchorTrail->rows[0]['context']['resource']);
	}//end testAFailedBillingReadStillRecordsTheMove()
}//end class
