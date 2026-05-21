<?php

/**
 * Parafering Audit Trail Service
 *
 * Records and exports append-only audit entries for the parafeerroute lifecycle.
 * Manifest-first: persistence and listing go through OpenRegister via
 * ObjectService; this service only owns the transition semantics, the
 * SHA-256 tamper-detection hash, and the Archiefwet-aligned export envelope.
 *
 * Append-only enforcement is co-located here: assertAppendOnly() rejects any
 * UPDATE/DELETE attempt with OCSForbiddenException. The
 * ParaferingAuditAppendOnlyValidator listener delegates to this method.
 *
 * @category Service
 * @package  OCA\Procest\Service\Parafering
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parafering-audit-trail/tasks.md
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Parafering;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * AuditTrailService — records parafering audit entries and exports them
 * for Archiefwet handover. Append-only enforced server-side.
 */
class AuditTrailService
{
    /**
     * Allowed transition action values.
     */
    public const ACTIONS = [
        'started',
        'paraferd',
        'terugsturen',
        'advised',
        'route-changed',
        'completed',
    ];

    /**
     * Allowed actor role values.
     */
    public const ACTOR_ROLES = [
        'steller',
        'adviseur',
        'parafeerder',
        'accorderend',
        'beheerder',
        'secretariaat',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Procest settings bridge (provides ObjectService + config keys)
     * @param IRequest        $request         Incoming HTTP request (for IP capture; redacted on write)
     * @param LoggerInterface $logger          PSR-3 logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IRequest $request,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record one append-only audit entry for a parafeerroute transition.
     *
     * @param string               $voorstelId      Voorstel UUID/slug
     * @param string|null          $step            Step identifier (order or UUID), nullable for started/completed
     * @param string               $action          Transition type (see ACTIONS)
     * @param string               $actor           Nextcloud user UID
     * @param string               $actorRole       Role at action moment (see ACTOR_ROLES)
     * @param string|null          $reason          Reason text (mandatory for terugsturen, route-changed)
     * @param array<string, mixed> $contentSnapshot Snapshot of voorstel content fields
     *
     * @return array<string, mixed>|null The persisted audit entry, or null when audit write failed (swallowed)
     */
    public function record(
        string $voorstelId,
        ?string $step,
        string $action,
        string $actor,
        string $actorRole,
        ?string $reason,
        array $contentSnapshot,
    ): ?array {
        try {
            if (in_array($action, self::ACTIONS, true) === false) {
                throw new RuntimeException('Invalid action');
            }

            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                throw new RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('parafering_audit_entry_schema');
            if ($register === '' || $schema === '') {
                throw new RuntimeException('paraferingAuditEntry configuration is missing');
            }

            $timestamp = (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');

            $entry = [
                'voorstel'        => $voorstelId,
                'action'          => $action,
                'actor'           => $actor,
                'actorRole'       => $actorRole,
                'timestamp'       => $timestamp,
                'contentSnapshot' => $contentSnapshot,
                'ipAddress'       => $this->redactIp(ip: (string) $this->request->getRemoteAddress()),
            ];

            if ($step !== null && $step !== '') {
                $entry['step'] = $step;
            }

            if ($reason !== null && $reason !== '') {
                $entry['reason'] = $reason;
            }

            $entry['auditEntryHash'] = $this->computeHash(entry: $entry);

            $saved = $objectService->saveObject($register, $schema, $entry);

            return $this->toArray(value: $saved);
        } catch (Throwable $e) {
            // Audit-write failure MUST NOT propagate back to the routing
            // service — operational transitions must not be blocked by audit
            // outages. The failure is detectable via OR's audit-trail-immutable
            // mutation log and via this error log entry.
            $this->logger->error(
                'Procest: paraferingAuditEntry write failed',
                [
                    'voorstel'  => $voorstelId,
                    'action'    => $action,
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ],
            );

            return null;
        }//end try
    }//end record()

    /**
     * Assert that a write operation on paraferingAuditEntry is an INSERT.
     *
     * Called by ParaferingAuditAppendOnlyValidator on the OR pre-save hook.
     * Throws OCSForbiddenException with the static message
     * "Audit entries are append-only" for any UPDATE or DELETE attempt, and
     * additionally validates INSERT payload shape (enums + hash format).
     *
     * @param array<string, mixed> $entry    The pending entry
     * @param bool                 $isUpdate True when this is an UPDATE/DELETE (existing id present)
     *
     * @return void
     *
     * @throws OCSForbiddenException When append-only is violated
     */
    public function assertAppendOnly(array $entry, bool $isUpdate): void
    {
        if ($isUpdate === true) {
            throw new OCSForbiddenException('Audit entries are append-only');
        }

        $action = (string) ($entry['action'] ?? '');
        if (in_array($action, self::ACTIONS, true) === false) {
            throw new OCSForbiddenException('Invalid action');
        }

        $actorRole = (string) ($entry['actorRole'] ?? '');
        if ($actorRole !== '' && in_array($actorRole, self::ACTOR_ROLES, true) === false) {
            throw new OCSForbiddenException('Invalid actorRole');
        }

        $timestamp = (string) ($entry['timestamp'] ?? '');
        if ($timestamp === '' || str_ends_with($timestamp, 'Z') === false) {
            throw new OCSForbiddenException('Timestamp must be UTC ISO 8601');
        }

        $hash = (string) ($entry['auditEntryHash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new OCSForbiddenException('Invalid audit hash');
        }
    }//end assertAppendOnly()

    /**
     * Export the full audit trail for a voorstel as an Archiefwet-aligned envelope.
     *
     * @param string $voorstelId        The voorstel UUID/slug
     * @param string $voorstelOnderwerp Voorstel onderwerp (for the metadata block)
     * @param string $exportedBy        UID of the auditor performing the export
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When configuration is missing
     */
    public function export(string $voorstelId, string $voorstelOnderwerp, string $exportedBy): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('parafering_audit_entry_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('paraferingAuditEntry configuration is missing');
        }

        $results = $objectService->findObjects(
            $register,
            $schema,
            ['voorstel' => $voorstelId],
            [],
            5000,
        );

        $entries = [];
        if (is_array($results) === true) {
            foreach ($results as $row) {
                $entries[] = $this->toArray(value: $row);
            }
        }

        usort(
            $entries,
            static function (array $a, array $b): int {
                return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
            },
        );

        $completed = null;
        foreach ($entries as $entry) {
            if (($entry['action'] ?? '') === 'completed') {
                $completed = $entry;
                break;
            }
        }

        $retentionUntil = $this->computeRetentionUntil(completedEntry: $completed);

        $selectielijstCategory = 'Algemene administratieve correspondentie — bewaartermijn 7 jaar';
        if ($completed !== null) {
            $selectielijstCategory = 'Bestuurlijke besluitvorming — bewaartermijn 20 jaar';
        }

        return [
            'metadata' => [
                'schema'                => 'MDTO 1.0',
                'exportedAt'            => (new DateTimeImmutable('now'))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
                'voorstel'              => $voorstelId,
                'voorstelOnderwerp'     => $voorstelOnderwerp,
                'retentionUntil'        => $retentionUntil,
                'selectielijstCategory' => $selectielijstCategory,
                'exportedBy'            => $exportedBy,
                'entryCount'            => count($entries),
            ],
            'entries'  => $entries,
        ];
    }//end export()

    /**
     * Build a content snapshot from the voorstel array (canonical 6 fields).
     *
     * @param array<string, mixed> $voorstel The voorstel data
     *
     * @return array<string, mixed>
     */
    public function buildContentSnapshot(array $voorstel): array
    {
        $snapshot = [];
        foreach (['onderwerp', 'document', 'bijlagen', 'routeSnapshot', 'currentStep', 'status'] as $field) {
            if (array_key_exists($field, $voorstel) === true) {
                $snapshot[$field] = $voorstel[$field];
            }
        }

        return $snapshot;
    }//end buildContentSnapshot()

    /**
     * Compute the canonical SHA-256 hash of an audit entry (excluding the hash field itself).
     *
     * @param array<string, mixed> $entry The entry without auditEntryHash
     *
     * @return string 64 lowercase hex chars
     */
    private function computeHash(array $entry): string
    {
        unset($entry['auditEntryHash']);
        ksort($entry);
        $canonical = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            $canonical = '';
        }

        return hash('sha256', $canonical);
    }//end computeHash()

    /**
     * Redact an IP address to /24 (IPv4) or /48 (IPv6) per AVG minimisation.
     *
     * @param string $ip The raw IP
     *
     * @return string
     */
    private function redactIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0';
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                $packed = '';
            }

            $expanded = inet_ntop($packed);
            if (is_string($expanded) === true) {
                $packedExpanded = inet_pton($expanded);
                if ($packedExpanded === false) {
                    $packedExpanded = '';
                }

                $hex = bin2hex($packedExpanded);
                if (strlen($hex) === 32) {
                    return implode(
                        ':',
                        [
                            substr($hex, 0, 4),
                            substr($hex, 4, 4),
                            substr($hex, 8, 4),
                            '0',
                            '0',
                            '0',
                            '0',
                            '0',
                        ],
                    );
                }
            }//end if
        }//end if

        return '';
    }//end redactIp()

    /**
     * Compute the retentionUntil date.
     *
     * @param array<string, mixed>|null $completedEntry The completed audit entry (or null)
     *
     * @return string ISO 8601 date
     */
    private function computeRetentionUntil(?array $completedEntry): string
    {
        try {
            if ($completedEntry !== null && isset($completedEntry['timestamp']) === true) {
                $base = new DateTimeImmutable((string) $completedEntry['timestamp']);
                return $base->modify('+20 years')->format('Y-m-d');
            }

            return (new DateTimeImmutable('now'))->modify('+7 years')->format('Y-m-d');
        } catch (Throwable $e) {
            return (new DateTimeImmutable('now'))->modify('+7 years')->format('Y-m-d');
        }
    }//end computeRetentionUntil()

    /**
     * Best-effort conversion of any ObjectService return to a plain array.
     *
     * @param mixed $value The returned object/array
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialized = $value->jsonSerialize();
                if (is_array($serialized) === true) {
                    return $serialized;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $value;
        }

        return [];
    }//end toArray()
}//end class
