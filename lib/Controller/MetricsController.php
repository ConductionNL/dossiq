<?php

/**
 * Procest Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
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
        $version    = $this->getAppVersion();
        $phpVersion = PHP_VERSION;

        $lines[] = '# HELP procest_info Application information';
        $lines[] = '# TYPE procest_info gauge';
        $lines[] = 'procest_info{version="'.$version.'",php_version="'.$phpVersion.'"} 1';
        $lines[] = '';

        // App up gauge.
        $lines[] = '# HELP procest_up Whether the application is healthy';
        $lines[] = '# TYPE procest_up gauge';
        $lines[] = 'procest_up 1';
        $lines[] = '';

        // Cases total by status and case_type.
        $lines[]    = '# HELP procest_cases_total Total cases by status and case_type';
        $lines[]    = '# TYPE procest_cases_total gauge';
        $caseCounts = $this->getCaseCounts();
        foreach ($caseCounts as $row) {
            $status   = $this->sanitizeLabel(value: $row['status']);
            $caseType = $this->sanitizeLabel(value: $row['case_type']);
            $count    = (int) $row['cnt'];
            $lines[]  = 'procest_cases_total{status="'.$status.'",case_type="'.$caseType.'"} '.$count;
        }

        $lines[] = '';

        // Cases overdue total.
        $overdueCount = $this->getOverdueCasesCount();
        $lines[]      = '# HELP procest_cases_overdue_total Cases past their deadline';
        $lines[]      = '# TYPE procest_cases_overdue_total gauge';
        $lines[]      = 'procest_cases_overdue_total '.$overdueCount;
        $lines[]      = '';

        // Tasks total by status.
        $lines[]    = '# HELP procest_tasks_total Total tasks by status';
        $lines[]    = '# TYPE procest_tasks_total gauge';
        $taskCounts = $this->getTaskCounts();
        foreach ($taskCounts as $row) {
            $status  = $this->sanitizeLabel(value: $row['status']);
            $count   = (int) $row['cnt'];
            $lines[] = 'procest_tasks_total{status="'.$status.'"} '.$count;
        }

        $lines[] = '';

        // Tasks overdue total.
        $overdueTasksCount = $this->getOverdueTasksCount();
        $lines[]           = '# HELP procest_tasks_overdue_total Tasks past their deadline';
        $lines[]           = '# TYPE procest_tasks_overdue_total gauge';
        $lines[]           = 'procest_tasks_overdue_total '.$overdueTasksCount;
        $lines[]           = '';

        return implode("\n", $lines)."\n";
    }//end collectMetrics()

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
