<?php

/**
 * Procest Tenant Billing Service
 *
 * Emits insert-only `tenantBillingEvent` rows and aggregates them for the
 * billing dashboard / Shillinq export.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Billing event service.
 */
class TenantBillingService
{
    /**
     * Allowed event types (mirrors the schema enum).
     *
     * @var array<int, string>
     */
    public const ALLOWED_EVENT_TYPES = [
        'case_created',
        'case_closed',
        'user_activated',
        'storage_increment',
        'api_burst',
        'quota_exceeded',
        'case_refund',
    ];

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager App manager.
     * @param ContainerInterface $container  Service container.
     * @param LoggerInterface    $logger     Logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Emit a billing event. Insert-only — invoiceRef stays NULL until the
     * Shillinq exporter sets it.
     *
     * @param string $tenantId  Tenant UUID.
     * @param string $eventType Event type (must be in ALLOWED_EVENT_TYPES).
     * @param float  $quantity  Quantity (default 1; negative for refunds).
     * @param float  $unitPrice Unit price.
     * @param string $currency  Currency.
     *
     * @return array<string,mixed>|null Persisted event row.
     *
     * @throws InvalidArgumentException On invalid event type.
     */
    public function emitEvent(string $tenantId, string $eventType, float $quantity=1.0, float $unitPrice=0.0, string $currency='EUR'): ?array
    {
        if (in_array($eventType, self::ALLOWED_EVENT_TYPES, true) === false) {
            throw new InvalidArgumentException('Unknown billing event type: '.$eventType);
        }

        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        $event = [
            'tenantRef'  => $tenantId,
            'eventType'  => $eventType,
            'quantity'   => $quantity,
            'unitPrice'  => $unitPrice,
            'currency'   => $currency,
            'occurredAt' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'invoiceRef' => null,
        ];

        try {
            return $os->saveObject(
                object: $event,
                register: TenantSaasService::REGISTER,
                schema: 'tenantBillingEvent',
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: emitEvent failed', ['eventType' => $eventType, 'exception' => $e->getMessage()]);
            return null;
        }
    }//end emitEvent()

    /**
     * Aggregate billing for a month.
     *
     * @param string $tenantId Tenant UUID.
     * @param string $month    YYYY-MM.
     *
     * @return array{eventCount:int, totalAmount:float, byType:array<string, array{count:float, amount:float}>}
     *
     * @throws InvalidArgumentException When month is malformed.
     */
    public function getMonthBilling(string $tenantId, string $month): array
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('Month must be YYYY-MM: '.$month);
        }

        $events = $this->fetchEventsForMonth(tenantId: $tenantId, month: $month);
        return $this->aggregate(events: $events);
    }//end getMonthBilling()

    /**
     * Compute the net effect across events (refunds reduce totals).
     *
     * @param array<int, array<string,mixed>> $events Event rows.
     *
     * @return array{eventCount:int, totalAmount:float, byType:array<string, array{count:float, amount:float}>}
     */
    public function aggregate(array $events): array
    {
        $byType      = [];
        $totalAmount = 0.0;
        foreach ($events as $event) {
            $type     = (string) ($event['eventType'] ?? 'unknown');
            $quantity = (float) ($event['quantity'] ?? 0);
            $unit     = (float) ($event['unitPrice'] ?? 0);
            $amount   = ($quantity * $unit);

            if (isset($byType[$type]) === false) {
                $byType[$type] = ['count' => 0.0, 'amount' => 0.0];
            }

            $byType[$type]['count']  += $quantity;
            $byType[$type]['amount'] += $amount;
            $totalAmount += $amount;
        }

        return ['eventCount' => count($events), 'totalAmount' => round($totalAmount, 2), 'byType' => $byType];
    }//end aggregate()

    /**
     * Mark a batch of events as exported under a single invoice reference.
     *
     * @param array<int, array<string,mixed>> $events     Event rows.
     * @param string                          $invoiceRef Shillinq invoice ref.
     *
     * @return int Number of events updated.
     */
    public function markExported(array $events, string $invoiceRef): int
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return 0;
        }

        $updated = 0;
        foreach ($events as $event) {
            if (($event['invoiceRef'] ?? null) !== null) {
                // Already exported — idempotent skip.
                continue;
            }

            $event['invoiceRef'] = $invoiceRef;
            try {
                $uuid = (string) ($event['uuid'] ?? $event['id'] ?? '');
                if ($uuid !== '') {
                    $uuidArg = $uuid;
                } else {
                    $uuidArg = null;
                }

                $os->saveObject(
                    object: $event,
                    register: TenantSaasService::REGISTER,
                    schema: 'tenantBillingEvent',
                    uuid: $uuidArg,
                );
                $updated++;
            } catch (Throwable $e) {
                $this->logger->error('Procest: markExported write failed', ['exception' => $e->getMessage()]);
            }
        }//end foreach

        return $updated;
    }//end markExported()

    /**
     * Fetch all events for a given month for a tenant.
     *
     * @param string $tenantId Tenant UUID.
     * @param string $month    YYYY-MM.
     *
     * @return array<int, array<string,mixed>>
     */
    public function fetchEventsForMonth(string $tenantId, string $month): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return [];
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'tenantBillingEvent',
                limit: 5000,
                offset: 0,
                filters: ['tenantRef' => $tenantId],
            );
        } catch (Throwable $e) {
            return [];
        }

        if (is_array($rows) === false) {
            $rows = [];
        }

        return array_values(array_filter($rows, fn ($r) => str_starts_with((string) ($r['occurredAt'] ?? ''), $month)));
    }//end fetchEventsForMonth()

    /**
     * Resolve the OpenRegister ObjectService when available.
     *
     * @return mixed|null The ObjectService instance, or null when unavailable.
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }//end getObjectService()
}//end class
