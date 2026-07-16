<?php

/**
 * Procest KPI Aggregation Service
 *
 * Computes dashboard KPI metrics via direct DB-side aggregation queries
 * on the OpenRegister objects table. All counts use COUNT(*) with
 * JSON_EXTRACT predicates — no PHP-side array iteration.
 *
 * Workflow invariant relied on by this service: a case is considered "open"
 * if and only if its `endDate` JSON field is NULL or empty. The Procest
 * workflow engine MUST set `endDate` whenever a case transitions to a final
 * status (i.e. any status whose `statusType.isFinal` is `true`). If a case
 * has `endDate IS NULL` but a `currentStatus` with `statusType.isFinal = true`,
 * the invariant is violated and the open-case counts become inaccurate.
 * See design.md § "Workflow Invariants" and verification task V10 for the
 * operational check procedure.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service that aggregates dashboard KPI data via DB-side COUNT(*) queries.
 *
 * All public methods return data suitable for JSON serialisation. Integer
 * fields default to 0 on error; nullable fields (avgProcessingDays) return
 * null when no matching rows exist.
 *
 * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T01
 */
class KpiAggregationService
{
    /**
     * Constructor.
     *
     * @param IDBConnection   $db     Database connection
     * @param LoggerInterface $logger Logger
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute all dashboard KPIs for the given user.
     *
     * Returns an array with keys: openCount, newToday, overdueCount,
     * completedCount, taskCount, tasksDueToday, statusBreakdown,
     * avgProcessingDays.
     *
     * @param string $userId The Nextcloud user ID to scope task queries
     *
     * @return array{
     *     openCount: int,
     *     newToday: int,
     *     overdueCount: int,
     *     completedCount: int,
     *     taskCount: int,
     *     tasksDueToday: int,
     *     statusBreakdown: array<int, array{status: string, count: int}>,
     *     typeBreakdown: array<int, array{type: string, count: int}>,
     *     avgProcessingDays: float|null
     * } Aggregated KPI data
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T01
     */
    public function computeKpis(string $userId): array
    {
        return [
            'openCount'         => $this->getOpenCount(),
            'newToday'          => $this->getNewTodayCount(),
            'overdueCount'      => $this->getOverdueCount(),
            'completedCount'    => $this->getCompletedCount(),
            'taskCount'         => $this->getTaskCount(userId: $userId),
            'tasksDueToday'     => $this->getTasksDueToday(userId: $userId),
            'statusBreakdown'   => $this->getStatusBreakdown(),
            'typeBreakdown'     => $this->getTypeBreakdown(),
            'avgProcessingDays' => $this->getAvgProcessingDays(),
        ];
    }//end computeKpis()

    /**
     * Count open cases — cases where endDate IS NULL or is an empty string.
     *
     * A case is considered "open" iff endDate IS NULL (workflow invariant).
     *
     * @return int Number of open cases
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T02
     */
    private function getOpenCount(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull($qb->createFunction("JSON_EXTRACT(o.object, '$.endDate')")),
                        $qb->expr()->eq(
                            $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate'))"),
                            $qb->createNamedParameter('')
                        )
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get open count', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getOpenCount()

    /**
     * Count cases created today — cases where startDate starts with today's date.
     *
     * @return int Number of cases created today
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T03
     */
    private function getNewTodayCount(): int
    {
        try {
            $today = (new DateTime())->format('Y-m-d');
            $qb    = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
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
            $this->logger->warning('[KpiAggregationService] Failed to get new today count', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getNewTodayCount()

    /**
     * Count overdue open cases — open cases where deadline is in the past.
     *
     * Only cases with endDate IS NULL (open) and a deadline earlier than
     * today are counted as overdue.
     *
     * @return int Number of overdue cases
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T04
     */
    private function getOverdueCount(): int
    {
        try {
            $today = (new DateTime())->format('Y-m-d');
            $qb    = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull($qb->createFunction("JSON_EXTRACT(o.object, '$.endDate')")),
                        $qb->expr()->eq(
                            $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate'))"),
                            $qb->createNamedParameter('')
                        )
                    )
                )
                ->andWhere(
                    $qb->expr()->isNotNull($qb->createFunction("JSON_EXTRACT(o.object, '$.deadline')"))
                )
                ->andWhere(
                    $qb->expr()->lt(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.deadline'))"),
                        $qb->createNamedParameter($today)
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get overdue count', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getOverdueCount()

    /**
     * Count cases completed this month — cases where endDate starts with YYYY-MM.
     *
     * @return int Number of cases completed in the current calendar month
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T05
     */
    private function getCompletedCount(): int
    {
        try {
            $monthPrefix = (new DateTime())->format('Y-m');
            $qb          = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
                ->andWhere(
                    $qb->expr()->like(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate'))"),
                        $qb->createNamedParameter($monthPrefix.'%')
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get completed count', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getCompletedCount()

    /**
     * Count active/available tasks assigned to the given user.
     *
     * @param string $userId The user ID to scope tasks to
     *
     * @return int Number of active/available tasks for the user
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T06
     */
    private function getTaskCount(string $userId): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%taak%')))
                ->andWhere(
                    $qb->expr()->eq(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.assignee'))"),
                        $qb->createNamedParameter($userId)
                    )
                )
                ->andWhere(
                    $qb->expr()->in(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status'))"),
                        $qb->createNamedParameter(['available', 'active'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get task count', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getTaskCount()

    /**
     * Count active/available tasks for the user that are due today.
     *
     * @param string $userId The user ID to scope tasks to
     *
     * @return int Number of tasks due today for the user
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T07
     */
    private function getTasksDueToday(string $userId): int
    {
        try {
            $today = (new DateTime())->format('Y-m-d');
            $qb    = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%taak%')))
                ->andWhere(
                    $qb->expr()->eq(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.assignee'))"),
                        $qb->createNamedParameter($userId)
                    )
                )
                ->andWhere(
                    $qb->expr()->in(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status'))"),
                        $qb->createNamedParameter(['available', 'active'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    )
                )
                ->andWhere(
                    $qb->expr()->like(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.dueDate'))"),
                        $qb->createNamedParameter($today.'%')
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get tasks due today count', ['error' => $e->getMessage()]);
            return 0;
        }//end try
    }//end getTasksDueToday()

    /**
     * Get case count grouped by status for all open cases.
     *
     * Returns an array of arrays with 'status' and 'count' keys, ordered
     * by count descending.
     *
     * @return array<int, array{status: string, count: int}> Status breakdown
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T08
     */
    private function getStatusBreakdown(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull($qb->createFunction("JSON_EXTRACT(o.object, '$.endDate')")),
                        $qb->expr()->eq(
                            $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate'))"),
                            $qb->createNamedParameter('')
                        )
                    )
                )
                ->groupBy('status')
                ->orderBy('cnt', 'DESC');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return array_map(
                static function (array $row): array {
                    return [
                        'status' => (string) ($row['status'] ?? ''),
                        'count'  => (int) ($row['cnt'] ?? 0),
                    ];
                },
                $rows
            );
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get status breakdown', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end getStatusBreakdown()

    /**
     * Group open cases by case type and return counts ordered descending.
     *
     * Returns an array of arrays with 'type' and 'count' keys, ordered by
     * count descending. Only counts open cases (endDate IS NULL or empty),
     * matching the same open-case definition used by getStatusBreakdown().
     *
     * @return array<int, array{type: string, count: int}> Type breakdown
     *
     * @spec openspec/specs/dashboard/spec.md
     */
    private function getTypeBreakdown(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.caseType')) AS case_type"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull($qb->createFunction("JSON_EXTRACT(o.object, '$.endDate')")),
                        $qb->expr()->eq(
                            $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate'))"),
                            $qb->createNamedParameter('')
                        )
                    )
                )
                ->groupBy('case_type')
                ->orderBy('cnt', 'DESC');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return array_map(
                static function (array $row): array {
                    return [
                        'type'  => (string) ($row['case_type'] ?? ''),
                        'count' => (int) ($row['cnt'] ?? 0),
                    ];
                },
                $rows
            );
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get type breakdown', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end getTypeBreakdown()

    /**
     * Compute the average processing time in days for cases completed this month.
     *
     * Uses SQL AVG(DATEDIFF(endDate, startDate)) filtered to cases where both
     * dates are present and endDate falls within the current calendar month.
     * Returns null (not 0) when no completed cases match the filter.
     *
     * @return float|null Average processing days, or null if no data available
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T08a
     */
    private function getAvgProcessingDays(): ?float
    {
        try {
            $monthPrefix = (new DateTime())->format('Y-m');
            $qb          = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction(
                    "AVG(DATEDIFF("
                    ."JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate')), "
                    ."JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.startDate'))"
                    .")) AS avg_days"
                )
            )
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%zaak%')))
                ->andWhere(
                    $qb->expr()->isNotNull($qb->createFunction("JSON_EXTRACT(o.object, '$.endDate')"))
                )
                ->andWhere(
                    $qb->expr()->isNotNull($qb->createFunction("JSON_EXTRACT(o.object, '$.startDate')"))
                )
                ->andWhere(
                    $qb->expr()->like(
                        $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.endDate'))"),
                        $qb->createNamedParameter($monthPrefix.'%')
                    )
                );

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false || $row['avg_days'] === null) {
                return null;
            }

            return (float) $row['avg_days'];
        } catch (\Exception $e) {
            $this->logger->warning('[KpiAggregationService] Failed to get avg processing days', ['error' => $e->getMessage()]);
            return null;
        }//end try
    }//end getAvgProcessingDays()
}//end class
