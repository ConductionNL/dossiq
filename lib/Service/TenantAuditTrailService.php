<?php

/**
 * Procest Tenant Audit Trail Service
 *
 * Emits tenant-stamped audit-trail entries for every mutating action so
 * REQ-010 (compliance — tenant-stamped audit log on data access) is met.
 *
 * Entries carry: action, actor (NC user ID), role, resource, ts, ip, ua,
 * tenant_id, and (when available) enterprise BIO context (deviceId,
 * geoLocation, mfaVerified, sessionDuration). Persistence is intentionally
 * delegated to NC's central logger — every entry is INFO-level so a SIEM
 * pipeline can ingest it.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-12-isolation-tests-compliance/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Tenant-stamped audit-trail emitter.
 */
class TenantAuditTrailService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Emit an audit-trail entry. Pure function over the input — returns the
     * entry array as well as logging it, so callers can serialise it into
     * the response or a downstream sink.
     *
     * @param array{
     *   action: string,
     *   actor: string,
     *   role?: string,
     *   resource?: string,
     *   tenantId: string,
     *   ip?: string,
     *   ua?: string,
     *   bio?: array<string, mixed>,
     * } $payload Audit payload.
     *
     * @return array<string,mixed> Normalised entry.
     */
    public function emit(array $payload): array
    {
        $entry = [
            'ts'        => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'action'    => (string) ($payload['action'] ?? ''),
            'actor'     => (string) ($payload['actor'] ?? ''),
            'role'      => (string) ($payload['role'] ?? ''),
            'resource'  => (string) ($payload['resource'] ?? ''),
            'tenantId'  => (string) ($payload['tenantId'] ?? ''),
            'ip'        => (string) ($payload['ip'] ?? ''),
            'ua'        => (string) ($payload['ua'] ?? ''),
            'bio'       => $this->sanitiseBio((array) ($payload['bio'] ?? [])),
        ];

        $this->logger->info('Procest AUDIT', $entry);
        return $entry;
    }

    /**
     * Whitelist enterprise BIO context fields. Drops anything we don't
     * recognise to keep the audit shape stable.
     *
     * @param array<string, mixed> $bio Raw BIO context.
     *
     * @return array<string, mixed>
     */
    public function sanitiseBio(array $bio): array
    {
        $out = [];
        foreach (['deviceId', 'geoLocation', 'mfaVerified', 'sessionDuration'] as $field) {
            if (array_key_exists($field, $bio) === true) {
                $out[$field] = $bio[$field];
            }
        }

        return $out;
    }

    /**
     * Compile a static security-hardening checklist used by the chain-member-12
     * compliance audit. Each entry has a key + human description + automated
     * proof location (test/code path).
     *
     * @return array<int, array{key:string, description:string, evidence:string}>
     */
    public function hardeningChecklist(): array
    {
        return [
            ['key' => 'tenant_scoped_queries', 'description' => 'Every query carries the request-scoped tenant filter', 'evidence' => 'TenantIsolationMiddleware sets the Postgres search_path; TenantContext carries the active tenant'],
            ['key' => 'claim_validation', 'description' => 'JWT tenant_id claim is cross-checked against the request tenant', 'evidence' => 'TenantClaimValidationMiddleware'],
            ['key' => 'audit_logged_mutations', 'description' => 'All mandate, status, and provisioning mutations emit an audit entry', 'evidence' => 'TenantAuditTrailService::emit + MandateValidationMiddleware decision log'],
            ['key' => 'no_hardcoded_secrets', 'description' => 'JWT signing secret + Shillinq credentials resolved from app config', 'evidence' => 'Application.php registerService factory for TenantJwtService'],
            ['key' => 'no_tenant_info_leak', 'description' => 'Cross-tenant queries return 404 (not 403) to prevent existence leak', 'evidence' => 'TenantIsolationMiddleware search_path scoping + controller-level 404 responses'],
            ['key' => 'composer_audit', 'description' => 'composer audit passes with zero high-severity CVEs', 'evidence' => 'hydra-gate-composer-audit (Hydra gate 4)'],
            ['key' => 'isolation_pen_test', 'description' => 'Cross-tenant pen-test asserts schema isolation under DDL + DQL', 'evidence' => 'TenantIsolationTest (deferred to live-OR fixture)'],
        ];
    }
}
