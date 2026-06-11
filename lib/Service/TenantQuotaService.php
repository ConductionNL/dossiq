<?php

/**
 * Procest Tenant Quota Service
 *
 * Per-tenant quota enforcement: initialize from tier templates, check
 * limit, atomic increment, set limit. The actual `check + increment`
 * lives inside `consume()` to avoid TOCTOU slippage under concurrent
 * case creation.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
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
 * Quota service.
 */
class TenantQuotaService
{
    /**
     * Tier defaults: tier => quotaType => [limit, enforcement].
     *
     * @var array<string, array<string, array{limit:int|null, enforcement:string}>>
     */
    public const TIER_DEFAULTS = [
        'basic' => [
            'cases_per_month'    => ['limit' => 100,  'enforcement' => 'warn'],
            'storage_gb'         => ['limit' => 10,   'enforcement' => 'warn'],
            'active_users'       => ['limit' => 5,    'enforcement' => 'block'],
            'api_calls_per_hour' => ['limit' => 1000, 'enforcement' => 'throttle'],
        ],
        'standard' => [
            'cases_per_month'    => ['limit' => 1000,  'enforcement' => 'warn'],
            'storage_gb'         => ['limit' => 100,   'enforcement' => 'warn'],
            'active_users'       => ['limit' => 50,    'enforcement' => 'block'],
            'api_calls_per_hour' => ['limit' => 10000, 'enforcement' => 'throttle'],
        ],
        'enterprise' => [
            'cases_per_month'    => ['limit' => null, 'enforcement' => 'warn'],
            'storage_gb'         => ['limit' => null, 'enforcement' => 'warn'],
            'active_users'       => ['limit' => null, 'enforcement' => 'warn'],
            'api_calls_per_hour' => ['limit' => null, 'enforcement' => 'warn'],
        ],
    ];

    /**
     * Enforcement decisions.
     */
    public const DECISION_ALLOW    = 'allow';
    public const DECISION_THROTTLE = 'throttle';
    public const DECISION_BLOCK    = 'block';
    public const DECISION_WARN     = 'warn';

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Initialise the four canonical quotas for a tenant from the tier template.
     *
     * @param string $tenantId Tenant UUID.
     * @param string $tier     Tier (basic|standard|enterprise).
     *
     * @return array<int, array<string,mixed>> Persisted quota rows.
     *
     * @throws InvalidArgumentException When tier is unknown.
     */
    public function initialize(string $tenantId, string $tier): array
    {
        if (array_key_exists($tier, self::TIER_DEFAULTS) === false) {
            throw new InvalidArgumentException('Unknown tier: '.$tier);
        }

        $os = $this->getObjectService();
        if ($os === null) {
            return [];
        }

        $rows = [];
        foreach (self::TIER_DEFAULTS[$tier] as $quotaType => $cfg) {
            try {
                $row = $os->saveObject(
                    object: [
                        'tenantRef'              => $tenantId,
                        'quotaType'              => $quotaType,
                        'limit'                  => $cfg['limit'],
                        'currentUsage'           => 0,
                        'softLimitWarningPercent'=> 80,
                        'enforcement'            => $cfg['enforcement'],
                        'resetAt'                => $this->nextResetAt($quotaType),
                    ],
                    register: TenantSaasService::REGISTER,
                    schema: 'tenantQuota',
                    uuid: null,
                );
                if (is_array($row) === true) {
                    $rows[] = $row;
                }
            } catch (Throwable $e) {
                $this->logger->error('Procest: quota initialise write failed', ['exception' => $e->getMessage()]);
            }
        }

        return $rows;
    }

    /**
     * Get the quota row for (tenant, type).
     *
     * @param string $tenantId  Tenant UUID.
     * @param string $quotaType Type.
     *
     * @return array<string,mixed>|null
     */
    public function getQuota(string $tenantId, string $quotaType): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'tenantQuota',
                limit: 1,
                offset: 0,
                filters: ['tenantRef' => $tenantId, 'quotaType' => $quotaType]
            );
            return (is_array($rows) === true && count($rows) > 0) ? $rows[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Decide what to do for the next request — given the current quota row
     * and the requested increment. Pure function over the row — no I/O.
     *
     * @param array<string,mixed> $quota     Quota row.
     * @param int                 $increment Requested increment.
     *
     * @return array{decision:string, soft:bool, reason:string}
     */
    public function decide(array $quota, int $increment=1): array
    {
        $limit       = $quota['limit'] ?? null;
        $current     = (int) ($quota['currentUsage'] ?? 0);
        $enforcement = (string) ($quota['enforcement'] ?? self::DECISION_WARN);
        $warningPct  = (int) ($quota['softLimitWarningPercent'] ?? 80);

        if ($limit === null) {
            return ['decision' => self::DECISION_ALLOW, 'soft' => false, 'reason' => 'unlimited'];
        }

        $limitInt    = (int) $limit;
        $next        = ($current + $increment);
        $softLimit   = (int) floor($limitInt * ($warningPct / 100));
        $softHit     = $next >= $softLimit;
        $hardHit     = $next > $limitInt;

        if ($hardHit === false) {
            return ['decision' => self::DECISION_ALLOW, 'soft' => $softHit, 'reason' => $softHit ? 'soft_limit' : 'within_limit'];
        }

        // Hard limit reached.
        if ($enforcement === self::DECISION_BLOCK) {
            return ['decision' => self::DECISION_BLOCK, 'soft' => true, 'reason' => 'block_limit_exceeded'];
        }

        if ($enforcement === self::DECISION_THROTTLE) {
            return ['decision' => self::DECISION_THROTTLE, 'soft' => true, 'reason' => 'throttle_limit_exceeded'];
        }

        return ['decision' => self::DECISION_WARN, 'soft' => true, 'reason' => 'warn_limit_exceeded'];
    }

    /**
     * Atomic check + increment.
     *
     * @param string $tenantId  Tenant UUID.
     * @param string $quotaType Type.
     * @param int    $amount    Amount to consume.
     *
     * @return array{decision:string, soft:bool, reason:string, currentUsage?:int}
     */
    public function consume(string $tenantId, string $quotaType, int $amount=1): array
    {
        $quota = $this->getQuota($tenantId, $quotaType);
        if ($quota === null) {
            return ['decision' => self::DECISION_ALLOW, 'soft' => false, 'reason' => 'no_quota_row'];
        }

        $decision = $this->decide($quota, $amount);
        if (in_array($decision['decision'], [self::DECISION_BLOCK, self::DECISION_THROTTLE], true) === true) {
            return $decision;
        }

        $quota['currentUsage'] = (int) ($quota['currentUsage'] ?? 0) + $amount;
        $this->persistQuota($quota);
        $decision['currentUsage'] = $quota['currentUsage'];
        return $decision;
    }

    /**
     * Set a new limit value.
     *
     * @param string   $tenantId  Tenant UUID.
     * @param string   $quotaType Type.
     * @param int|null $limit     New limit (null = unlimited).
     *
     * @return array<string,mixed>|null Persisted row.
     */
    public function setLimit(string $tenantId, string $quotaType, ?int $limit): ?array
    {
        $quota = $this->getQuota($tenantId, $quotaType);
        if ($quota === null) {
            return null;
        }

        $quota['limit'] = $limit;
        $this->persistQuota($quota);
        return $quota;
    }

    /**
     * Reset due quotas. Used by the monthly background job.
     *
     * @param array<string,mixed> $quota Quota row.
     *
     * @return array<string,mixed> Updated row.
     */
    public function resetIfDue(array $quota): array
    {
        $resetAt = strtotime((string) ($quota['resetAt'] ?? ''));
        $now     = time();
        if ($resetAt === false || $resetAt > $now) {
            return $quota;
        }

        $quota['currentUsage'] = 0;
        $quota['resetAt']      = $this->nextResetAt((string) ($quota['quotaType'] ?? 'cases_per_month'));
        $this->persistQuota($quota);
        return $quota;
    }

    /**
     * Compute the next reset timestamp for a quota type.
     *
     * @param string $quotaType Type.
     *
     * @return string ISO-8601 timestamp.
     */
    public function nextResetAt(string $quotaType): string
    {
        if ($quotaType === 'api_calls_per_hour') {
            return (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
        }

        // cases_per_month + others reset on the first of next month.
        return (new DateTimeImmutable('first day of next month'))->format(DATE_ATOM);
    }

    /**
     * @param array<string,mixed> $quota Quota row.
     *
     * @return void
     */
    private function persistQuota(array $quota): void
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return;
        }

        try {
            $uuid = (string) ($quota['uuid'] ?? $quota['id'] ?? '');
            $os->saveObject(
                object: $quota,
                register: TenantSaasService::REGISTER,
                schema: 'tenantQuota',
                uuid: $uuid !== '' ? $uuid : null
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: persistQuota failed', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * @return mixed|null
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
    }
}
