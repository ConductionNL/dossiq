<?php

/**
 * Terminating a tenant says what it was still owed.
 *
 * `TenantLifecycleControlService` wrapped `TenantSaasService::updateStatus()`
 * with a log line, and nothing ever called it. So the one behaviour in it that
 * was NOT a copy of the live path — counting usage events that have not been
 * invoiced before flipping a tenant to terminated — had never run.
 *
 * Termination is irreversible, and it is the moment after which nobody can
 * invoice the month that just ran: the organisation simply does not bill it.
 * That check now lives in the live path, and the wrapper is gone.
 *
 * 🔴 THE COUNT GOES IN THE AUDIT TRAIL, NOT ONLY IN THE LOG. A warning in a log
 * file is a line nobody reads at the one moment it matters. The audit trail is
 * what gets consulted afterwards when somebody asks where the money went, and
 * `TenantAuditTrailService` hash-chains it.
 *
 * 🔴 IT DOES NOT REFUSE. An operator winding up a tenant that has stopped
 * paying has to be able to finish, and blocking them on a billing export would
 * be a worse failure than recording the number.
 *
 * MUTATION-CHECKED 2026-09-18, three of them, each restored after:
 *   - not appending the note to the audit resource reddens
 *     testTerminatingWithUnsettledBillingRecordsTheCount;
 *   - counting events that already carry an invoiceRef reddens that test AND
 *     testTerminatingWithNothingOutstandingSaysNothing;
 *   - taking the count on every status change reddens
 *     testSuspendingTakesNoBillingCount.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantSaasService;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What the live termination path records about unsettled billing.
 *
 * @covers \OCA\Dossiq\Service\TenantSaasService::updateStatus
 * @uses \OCA\Dossiq\Service\TenantAuditTrailService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
 */
class TenantTerminationRecordsUnsettledBillingTest extends TestCase {

	/**
	 * A tenant terminated with uninvoiced usage says how much.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	public function testTerminatingWithUnsettledBillingRecordsTheCount(): void {
		$auditLogger = $this->createMock(originalClassName: LoggerInterface::class);
		$auditLogger->expects(self::once())
			->method('info')
			->with(
				'Dossiq AUDIT',
				self::callback(callback:
					static fn (array $entry): bool => str_contains(
						(string)$entry['resource'],
						'unsettled billing events: 2'
					)
				)
			);

		$this->terminate(
			auditLogger: $auditLogger,
			events: [
				['invoiceRef' => null],
				['invoiceRef' => 'INV-2026-09-001'],
				['invoiceRef' => null],
			],
		);
	}//end testTerminatingWithUnsettledBillingRecordsTheCount()

	/**
	 * A tenant whose month is fully invoiced says nothing extra.
	 *
	 * The control. A note on every termination is a note nobody reads, and one
	 * that appeared when there was nothing outstanding would be worse than
	 * none: it would make the real ones invisible.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	public function testTerminatingWithNothingOutstandingSaysNothing(): void {
		$auditLogger = $this->createMock(originalClassName: LoggerInterface::class);
		$auditLogger->expects(self::once())
			->method('info')
			->with(
				'Dossiq AUDIT',
				self::callback(callback:
					static fn (array $entry): bool => str_contains((string)$entry['resource'], 'unsettled') === false
						&& str_contains((string)$entry['resource'], 'active->terminated') === true
				)
			);

		$this->terminate(
			auditLogger: $auditLogger,
			events: [['invoiceRef' => 'INV-2026-09-001']],
		);
	}//end testTerminatingWithNothingOutstandingSaysNothing()

	/**
	 * A suspension is not a termination, and takes no billing count.
	 *
	 * The second control. Reading the month's usage on every status change
	 * would put a billing query behind a suspend and a reactivate, neither of
	 * which is the last chance to invoice anything.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	public function testSuspendingTakesNoBillingCount(): void {
		$billing = $this->createMock(originalClassName: TenantBillingService::class);
		$billing->expects(self::never())->method('fetchEventsForMonth');

		$this->serviceOver(
			auditLogger: $this->createMock(originalClassName: LoggerInterface::class),
			billing: $billing,
			from: 'active',
		)->updateStatus(tenantId: 't-1', newStatus: 'suspended');
	}//end testSuspendingTakesNoBillingCount()

	/**
	 * An instance with no billing service behaves exactly as before.
	 *
	 * The third control, and the reason the parameter is nullable and last:
	 * every existing construction of this service keeps working unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	public function testNoBillingServiceChangesNothing(): void {
		$auditLogger = $this->createMock(originalClassName: LoggerInterface::class);
		$auditLogger->expects(self::once())
			->method('info')
			->with(
				'Dossiq AUDIT',
				self::callback(callback:
					static fn (array $entry): bool => str_contains((string)$entry['resource'], 'unsettled') === false
				)
			);

		$this->serviceOver(auditLogger: $auditLogger, billing: null, from: 'active')
			->updateStatus(tenantId: 't-1', newStatus: 'terminated');
	}//end testNoBillingServiceChangesNothing()

	/**
	 * Terminate one tenant whose month holds these usage events.
	 *
	 * @param LoggerInterface&MockObject  $auditLogger The logger the audit trail writes to.
	 * @param array<int, array<string, mixed>> $events The month's usage events.
	 *
	 * @return void
	 */
	private function terminate(LoggerInterface $auditLogger, array $events): void {
		$billing = $this->createMock(originalClassName: TenantBillingService::class);
		$billing->method('fetchEventsForMonth')->willReturn($events);

		$this->serviceOver(auditLogger: $auditLogger, billing: $billing, from: 'active')
			->updateStatus(tenantId: 't-1', newStatus: 'terminated');
	}//end terminate()

	/**
	 * The service under test, with the REAL audit trail over a doubled logger.
	 *
	 * The audit service is not doubled: the assertion is that the count reaches
	 * the audit entry, and a doubled emitter would let a service that never
	 * built one pass.
	 *
	 * @param LoggerInterface           $auditLogger Where the audit entry lands.
	 * @param TenantBillingService|null $billing     The billing service, or null.
	 * @param string                    $from        The tenant's current status.
	 *
	 * @return TenantSaasService&MockObject The service, with persistence stubbed.
	 */
	private function serviceOver(
		LoggerInterface $auditLogger,
		?TenantBillingService $billing,
		string $from,
	): TenantSaasService {
		$audit = new TenantAuditTrailService(
			logger: $auditLogger,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
		);

		$service = $this->getMockBuilder(className: TenantSaasService::class)
			->setConstructorArgs(
				[
					$this->createMock(originalClassName: IAppManager::class),
					$this->createMock(originalClassName: ContainerInterface::class),
					$this->createMock(originalClassName: LoggerInterface::class),
					$audit,
					$this->createMock(originalClassName: IUserSession::class),
					$billing,
				]
			)
			->onlyMethods(['getById', 'saveTenant'])
			->getMock();
		$service->method('getById')->willReturn(['id' => 't-1', 'status' => $from]);
		$service->method('saveTenant')->willReturn(['id' => 't-1', 'status' => 'terminated']);

		return $service;
	}//end serviceOver()
}//end class
