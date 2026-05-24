<?php

/**
 * Procest Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use DateTime;
use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for exposing Prometheus metrics.
 *
 * @psalm-suppress UnusedClass
 */
class MetricsController extends Controller
{
    /**
     * Default cache TTL for metric queries in seconds.
     */
    private const CACHE_TTL_DEFAULT = 30;

    /**
     * Cache TTL for overdue queries (change less frequently).
     */
    private const CACHE_TTL_OVERDUE = 60;

    /**
     * Constructor.
     *
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return Prometheus metrics in text exposition format.
     *
     * @NoCSRFRequired
     *
     * @return TextPlainResponse Prometheus-formatted metrics
     */
    public function index(): TextPlainResponse
    {
        $metrics  = $this->collectMetrics();
        $response = new TextPlainResponse($metrics);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()

    /**
     * Collect all metrics and format as Prometheus text.
     *
     * @return string Prometheus exposition format text
     */
    private function collectMetrics(): string
    {
        $lines = [];

        // App info gauge.
        $version          = $this->getAppVersion();
        $phpVersion       = PHP_VERSION;
        $nextcloudVersion = $this->getNextcloudVersion();

        $lines[] = '# HELP procest_info Application information';
        $lines[] = '# TYPE procest_info gauge';
        $lines[] = 'procest_info{version="'.$version.'",php_version="'.$phpVersion.'",nextcloud_version="'.$nextcloudVersion.'"} 1';
        $lines[] = '';

        // App up gauge.
        if ($this->checkDatabaseHealth() === true) {
            $isUp = 1;
        } else {
            $isUp = 0;
        }

        $lines[] = '# HELP procest_up Whether the application is healthy';
        $lines[] = '# TYPE procest_up gauge';
        $lines[] = 'procest_up '.$isUp;
        $lines[] = '';

        // Cases total by status and case_type.
        $lines[]    = '# HELP procest_cases_total Total cases by status and case_type';
        $lines[]    = '# TYPE procest_cases_total gauge';
        $caseCounts = $this->getCached(
                key: 'procest_metrics_case_counts',
                ttl: self::CACHE_TTL_DEFAULT,
                compute: function () {
                    return $this->getCaseCounts();
                }
                );
        foreach ($caseCounts as $row) {
            $status   = $this->sanitizeLabel(value: $row['status']);
            $caseType = $this->sanitizeLabel(value: $row['case_type']);
            $count    = (int) $row['cnt'];
            $lines[]  = 'procest_cases_total{status="'.$status.'",case_type="'.$caseType.'"} '.$count;
        }

        $lines[] = '';

        // Cases overdue total.
        $overdueCount = $this->getCached(
                key: 'procest_metrics_overdue_cases',
                ttl: self::CACHE_TTL_OVERDUE,
                compute: function () {
                    return $this->getOverdueCasesCount();
                }
                );
        $lines[]      = '# HELP procest_cases_overdue_total Cases past their deadline';
        $lines[]      = '# TYPE procest_cases_overdue_total gauge';
        $lines[]      = 'procest_cases_overdue_total '.$overdueCount;
        $lines[]      = '';

        // Cases created today.
        $createdToday = $this->getCached(
                key: 'procest_metrics_created_today',
                ttl: self::CACHE_TTL_DEFAULT,
                compute: function () {
                    return $this->getCasesCreatedTodayCount();
                }
                );
        $lines[]      = '# HELP procest_cases_created_today Cases created today';
        $lines[]      = '# TYPE procest_cases_created_today gauge';
        $lines[]      = 'procest_cases_created_today '.$createdToday;
        $lines[]      = '';

        // Tasks total by status.
        $lines[]    = '# HELP procest_tasks_total Total tasks by status';
        $lines[]    = '# TYPE procest_tasks_total gauge';
        $taskCounts = $this->getCached(
                key: 'procest_metrics_task_counts',
                ttl: self::CACHE_TTL_DEFAULT,
                compute: function () {
                    return $this->getTaskCounts();
                }
                );
        foreach ($taskCounts as $row) {
            $status  = $this->sanitizeLabel(value: $row['status']);
            $count   = (int) $row['cnt'];
            $lines[] = 'procest_tasks_total{status="'.$status.'"} '.$count;
        }

        $lines[] = '';

        // Tasks overdue total.
        $overdueTasksCount = $this->getCached(
                key: 'procest_metrics_overdue_tasks',
                ttl: self::CACHE_TTL_OVERDUE,
                compute: function () {
                    return $this->getOverdueTasksCount();
                }
                );
        $lines[]           = '# HELP procest_tasks_overdue_total Tasks past their deadline';
        $lines[]           = '# TYPE procest_tasks_overdue_total gauge';
        $lines[]           = 'procest_tasks_overdue_total '.$overdueTasksCount;
        $lines[]           = '';

        return implode("\n", $lines)."\n";
    }//end collectMetrics()

    /**
     * Get a cached value from APCu, computing it on cache miss.
     *
     * Falls back to direct computation if APCu is unavailable.
     *
     * @param string   $key     The cache key
     * @param int      $ttl     Cache TTL in seconds
     * @param callable $compute Callable that computes the value on cache miss
     *
     * @return mixed The cached or freshly computed value
     */
    private function getCached(string $key, int $ttl, callable $compute): mixed
    {
        if (function_exists('apcu_fetch') === true) {
            $success = false;
            $cached  = apcu_fetch($key, $success);
            if ($success === true) {
                return $cached;
            }

            $value = $compute();

            try {
                apcu_store($key, $value, $ttl);
            } catch (\Exception $e) {
                // Silently ignore APCu store failures.
                $this->logger->debug('[MetricsController] APCu store failed', ['key' => $key, 'error' => $e->getMessage()]);
            }

            return $value;
        }

        return $compute();
    }//end getCached()

    /**
     * Check basic database health.
     *
     * @return bool True if the database is reachable
     */
    private function checkDatabaseHealth(): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }//end checkDatabaseHealth()

    /**
     * Get case counts grouped by status and case type from OpenRegister objects.
     *
     * Procest stores cases as OpenRegister objects. We query the objects table
     * and extract status and case type from the JSON object column.
     *
     * @return array<array{status: string, case_type: string, cnt: string}> Grouped counts
     */
    private function getCaseCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.caseType')) AS case_type"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%aak%')))
                ->groupBy('status', 'case_type');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get case counts', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end getCaseCounts()

    /**
     * Get count of overdue cases (past deadline).
     *
     * @return int Overdue case count
     */
    private function getOverdueCasesCount(): int
    {
        try {
            $now = (new DateTime())->format('Y-m-d');
            $qb  = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%aak%')))
                ->andWhere($qb->expr()->isNotNull($qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.uiterlijkeEinddatumAfdoening'))")))
                ->andWhere(
                    $qb->expr()->lt(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.uiterlijkeEinddatumAfdoening'))"),
                        $qb->createNamedParameter($now)
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get overdue cases', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getOverdueCasesCount()

    /**
     * Get count of cases created today.
     *
     * @return int Cases created today count
     */
    private function getCasesCreatedTodayCount(): int
    {
        try {
            $today = (new DateTime())->format('Y-m-d');
            $qb    = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%aak%')))
                ->andWhere(
                    $qb->expr()->like(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.startDate'))"),
                        $qb->createNamedParameter($today.'%')
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get cases created today', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getCasesCreatedTodayCount()

    /**
     * Get task counts grouped by status.
     *
     * @return array<array{status: string, cnt: string}> Grouped counts
     */
    private function getTaskCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%taak%')))
                ->groupBy('status');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get task counts', ['error' => $e->getMessage()]);
            return [];
        }
    }//end getTaskCounts()

    /**
     * Get count of overdue tasks.
     *
     * @return int Overdue task count
     */
    private function getOverdueTasksCount(): int
    {
        try {
            $now = (new DateTime())->format('Y-m-d');
            $qb  = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%taak%')))
                ->andWhere($qb->expr()->isNotNull($qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.deadline'))")))
                ->andWhere(
                    $qb->expr()->lt(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.deadline'))"),
                        $qb->createNamedParameter($now)
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get overdue tasks', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getOverdueTasksCount()

    /**
     * Get the app version.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion(Application::APP_ID);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()

    /**
     * Get the Nextcloud version string.
     *
     * @return string The Nextcloud version
     */
    private function getNextcloudVersion(): string
    {
        try {
            if (class_exists('\OC_Util') === true && method_exists('\OC_Util', 'getVersionString') === true) {
                return \OC_Util::getVersionString();
            }

            return 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getNextcloudVersion()

    /**
     * Sanitize a label value for Prometheus format.
     *
     * @param string $value The label value
     *
     * @return string Sanitized label value
     */
    private function sanitizeLabel(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value
        );
    }//end sanitizeLabel()
}//end class
