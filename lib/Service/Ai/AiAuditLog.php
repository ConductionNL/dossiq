<?php

/**
 * Procest AI audit log.
 *
 * The Algoritmeregister oversight trail: every AI suggestion, every human
 * accept/reject/modify, and every conversational assistant exchange is written
 * as an `ai_audit_entry_schema` object in OpenRegister, and read back — newest
 * first — for the oversight page and the CSV export.
 *
 * Split out of {@see \OCA\Procest\Service\AiService} so the write and the read
 * resolve the same register/schema config in one class, and so a misconfigured
 * or unavailable audit sink degrades in exactly one place (warning + empty
 * result, never a throw that would 500 the oversight surface).
 *
 * @category Service
 * @package  OCA\Procest\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Ai;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the AI oversight audit trail.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 */
class AiAuditLog
{

    use SearchesObjects;

    /**
     * Default audit-listing page size.
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * Maximum audit-listing page size.
     */
    private const MAX_LIMIT = 200;

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app configuration service.
     * @param ContainerInterface $container The DI container.
     * @param LoggerInterface    $logger    The logger interface.
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record an AI audit trail entry in OpenRegister.
     *
     * @param array $entry The audit entry data.
     *
     * @return void
     *
     * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
     */
    public function record(array $entry): void
    {
        try {
            $storage = $this->storage();
            if ($storage === null) {
                return;
            }

            $this->container->get('OCA\OpenRegister\Service\ObjectService')->saveObject(
                register: $storage['register'],
                schema: $storage['schema'],
                object: $entry,
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to record AI audit entry',
                ['error' => $e->getMessage()]
            );
        }//end try
    }//end record()

    /**
     * List recorded AI audit entries from OpenRegister, newest first.
     *
     * Degrades gracefully (empty result, warning logged, no throw) when AI audit
     * storage is not configured or the OpenRegister lookup fails, so a
     * misconfigured instance never 500s the oversight surface.
     *
     * @param array<string, mixed> $filters Optional filters: 'caseId', 'type'.
     * @param int                  $limit   Page size (clamped to 1-200, default 50).
     * @param int                  $offset  Paging offset (clamped to >= 0).
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int|null, limit: int, offset: int}
     *
     * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
     */
    public function list(array $filters=[], int $limit=self::DEFAULT_LIMIT, int $offset=0): array
    {
        $limit  = $this->clampLimit(limit: $limit);
        $offset = max(0, $offset);

        $empty = [
            'entries' => [],
            'total'   => null,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        try {
            $storage = $this->storage();
            if ($storage === null) {
                return $empty;
            }

            $entries = $this->searchObjectsAsArrays(
                objectService: $this->container->get('OCA\OpenRegister\Service\ObjectService'),
                register: $storage['register'],
                schema: $storage['schema'],
                filters: $this->query(filters: $filters, limit: $limit, offset: $offset)
            );

            return [
                'entries' => $entries,
                // The array-normalising search bridge (searchObjectsAsArrays)
                // does not expose a cheap row count for slug-resolved
                // register/schema — a real total would require a second,
                // uncapped fetch. Left null rather than faked; callers page
                // by whether a full page of `limit` rows came back.
                'total'   => null,
                'limit'   => $limit,
                'offset'  => $offset,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to list AI audit entries',
                ['error' => $e->getMessage()]
            );
            return $empty;
        }//end try
    }//end list()

    /**
     * Resolve the register + `ai_audit_entry_schema` the audit trail lives in.
     *
     * @return array{register: string, schema: string}|null The storage config,
     *                                                      or null when not configured.
     */
    private function storage(): ?array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'ai_audit_entry_schema', '');

        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('AI audit: register or schema ID not configured');
            return null;
        }

        return ['register' => $registerId, 'schema' => $schemaId];
    }//end storage()

    /**
     * Build the OpenRegister query for an audit listing.
     *
     * @param array<string, mixed> $filters Optional filters: 'caseId', 'type'.
     * @param int                  $limit   The clamped page size.
     * @param int                  $offset  The clamped offset.
     *
     * @return array<string, mixed> The query.
     */
    private function query(array $filters, int $limit, int $offset): array
    {
        $query = [
            '_limit'  => $limit,
            '_offset' => $offset,
            // Newest first — the schema's business timestamp, not OR's
            // system @self.created, is the ordering key the oversight
            // page needs (matches when the AI call actually happened).
            '_order'  => ['timestamp' => 'DESC'],
        ];

        foreach (['caseId', 'type'] as $key) {
            $value = ($filters[$key] ?? null);
            if (empty($value) === false) {
                $query[$key] = $value;
            }
        }

        return $query;
    }//end query()

    /**
     * Clamp a requested audit-listing page size to a safe range.
     *
     * @param int $limit The requested limit.
     *
     * @return int The clamped limit (1-200, default 50 for non-positive input).
     */
    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }//end clampLimit()
}//end class
