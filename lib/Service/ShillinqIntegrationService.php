<?php

/**
 * Dossiq Shillinq Integration Service
 *
 * Hands a tenant's invoiced month to shillinq. Shillinq defines the command
 * (`OCA\Shillinq\Event\InvoiceIngestRequestedEvent`, ADR-041) and its
 * listener drafts a BillableInvoice for the one CustomerMaster that carries
 * the dossiq tenant id as its external reference (decision 174). The answer
 * comes back on the same event object: the drafted invoice, or a refusal that
 * this service records on the tenant's audit trail.
 *
 * This replaces a bearer-token POST to a `/invoices` URL that shillinq never
 * had, with a blocking sleep() retry loop around it
 * (dossiq-delivers-nothing phase 5).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/dossiq-delivers-nothing/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises shillinq's invoice command for a tenant month and reads its answer.
 *
 * @spec openspec/changes/dossiq-delivers-nothing/tasks.md
 */
class ShillinqIntegrationService {
	/**
	 * Shillinq's command, resolved by name so dossiq installs without shillinq.
	 */
	public const INGEST_EVENT = 'OCA\\Shillinq\\Event\\InvoiceIngestRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatches shillinq's command in-process.
	 * @param TenantAuditTrailService $auditTrail Records a refusal on the tenant's trail.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly TenantAuditTrailService $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Group events by tenant + month for invoicing.
	 *
	 * @param array<int, array<string,mixed>> $events Events.
	 *
	 * @return array<string, array<int, array<string,mixed>>> Keyed by `<tenantId>:<month>`.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
	 */
	public function groupForInvoicing(array $events): array {
		$grouped = [];
		foreach ($events as $event) {
			$tenantId = (string)($event['tenantRef'] ?? '');
			$month = substr((string)($event['occurredAt'] ?? ''), 0, 7);
			if ($tenantId === '' || $month === '' || ($event['invoiceRef'] ?? null) !== null) {
				continue;
			}

			$key = $tenantId . ':' . $month;
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = [];
			}

			$grouped[$key][] = $event;
		}

		return $grouped;
	}//end groupForInvoicing()

	/**
	 * Build the command's content from a tenant's month of events.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 * @param array<int, array<string,mixed>> $events Events.
	 *
	 * @return array{tenantId:string, period:string, lines:array<int, array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When an event carries no readable quantity or unit price.
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/tasks.md
	 */
	public function buildInvoicePayload(string $tenantId, string $month, array $events): array {
		$lines = [];
		foreach ($events as $event) {
			// 🔴 NO LINE IS PRICED BY DEFAULT. `quantity ?? 1` and
			// `unitPrice ?? 0` were two different guesses about the same event,
			// so the payload and TenantBillingService::aggregate() answered
			// different amounts for one month. An event that cannot be priced
			// throws here, and runInvoicing() already refuses before this.
			$quantity = ($event['quantity'] ?? null);
			$unitPrice = ($event['unitPrice'] ?? null);
			if (is_numeric($quantity) === false || is_numeric($unitPrice) === false) {
				throw new InvalidArgumentException(
					'A usage event carries no readable quantity or unit price, so no invoice line was built for it.'
				);
			}

			$lines[] = [
				'description' => (string)($event['eventType'] ?? 'usage'),
				'quantity' => (float)$quantity,
				'unitPrice' => (float)$unitPrice,
				'currency' => (string)($event['currency'] ?? 'EUR'),
				'occurredAt' => (string)($event['occurredAt'] ?? ''),
				'sourceId' => (string)($event['id'] ?? ($event['uuid'] ?? '')),
			];
		}

		return ['tenantId' => $tenantId, 'period' => $month, 'lines' => $lines];
	}//end buildInvoicePayload()

	/**
	 * Ask shillinq to draft the month's invoice.
	 *
	 * A refusal (no customer carries the tenant, the month was drafted with
	 * other lines, a line shillinq cannot price) is recorded on the tenant's
	 * audit trail and returned as `lastError`, so the events stay unbilled and
	 * the month invoices once the cause is fixed.
	 *
	 * @param array<string,mixed> $payload The built payload (tenantId, period, lines).
	 *
	 * @return array{success:bool, invoiceRef?:string, invoiceNumber?:string, duplicated?:bool, attempts:int, lastError?:string}
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/tasks.md
	 */
	public function exportInvoice(array $payload): array {
		$tenantId = (string)($payload['tenantId'] ?? '');
		$period = (string)($payload['period'] ?? '');
		$eventClass = self::INGEST_EVENT;
		if (class_exists($eventClass) === false) {
			return $this->refused(tenantId: $tenantId, period: $period, attempts: 0, reason: 'Shillinq is not installed, so no invoice was drafted.');
		}

		try {
			// Shillinq's contract: sourceApp, externalReference, period, lines, correlationId.
			$event = new $eventClass(Application::APP_ID, $tenantId, $period, (array)($payload['lines'] ?? []), $tenantId . ':' . $period);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return $this->refused(tenantId: $tenantId, period: $period, attempts: 1, reason: 'Shillinq could not be asked: ' . $e->getMessage());
		}

		if ((bool)$event->isHandled() === false) {
			$reason = (string)($event->getError() ?? '');
			if ($reason === '') {
				$reason = 'No shillinq listener answered the invoice request.';
			}

			return $this->refused(tenantId: $tenantId, period: $period, attempts: 1, reason: $reason);
		}

		$result = (array)$event->getResult();
		return [
			'success' => true,
			'invoiceRef' => (string)($result['invoiceId'] ?? ''),
			'invoiceNumber' => (string)($result['invoiceNumber'] ?? ''),
			'duplicated' => (bool)($result['duplicated'] ?? false),
			'attempts' => 1,
		];
	}//end exportInvoice()

	/**
	 * Record why no invoice exists for the month, and answer with it.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $period YYYY-MM.
	 * @param int $attempts 0 when shillinq was never asked, 1 when it was.
	 * @param string $reason Why.
	 *
	 * @return array{success:bool, attempts:int, lastError:string}
	 */
	private function refused(string $tenantId, string $period, int $attempts, string $reason): array {
		$this->logger->warning('Dossiq: shillinq drafted no invoice', ['tenantId' => $tenantId, 'period' => $period, 'reason' => $reason]);
		$this->auditTrail->emit(
			payload: [
				// The trail prefixes `procest.tenant.` and keeps only a few bio
				// keys, so the reason travels in `resource`, where it is read.
				'action' => 'invoice.refused',
				'actor' => 'system',
				'resource' => 'tenantBilling/' . $period . ': ' . $reason,
				'tenantId' => $tenantId,
			]
		);

		return ['success' => false, 'attempts' => $attempts, 'lastError' => $reason];
	}//end refused()
}//end class
