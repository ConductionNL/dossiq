<?php

/**
 * Procest Conflict Detection Service.
 *
 * Pure-logic helper that classifies a failed sync-queue replay response into
 * a conflict type and builds the corresponding conflictRecord payload. Used by
 * the replay engine when an operation returns 409 (concurrent edit / deleted
 * remote) or 403 (permission lost) so the inspector can resolve the conflict.
 *
 * Contains no I/O; persistence of conflictRecord objects to OpenRegister is the
 * caller's responsibility.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Classifies replay failures into conflict types and builds conflict records.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-13
 *
 * @psalm-suppress UnusedClass
 */
class ConflictDetectionService
{
    /**
     * A colleague edited the same case concurrently (409 with server body).
     */
    public const TYPE_CONCURRENT_EDIT = 'concurrent_edit';

    /**
     * The target object was deleted on the server (409/404 with no body).
     */
    public const TYPE_DELETED_REMOTE = 'deleted_remote';

    /**
     * The inspector lost permission to the object while offline (403).
     */
    public const TYPE_PERMISSION_LOST = 'permission_lost';

    /**
     * Classify an HTTP replay response into a conflict type.
     *
     * Returns null when the response is not a conflict (i.e. should be handled
     * as success or as a transient/retryable error by the caller).
     *
     * @param int                       $statusCode   The HTTP status code returned by OpenRegister.
     * @param array<string, mixed>|null $serverObject The server-side object body, when present.
     *
     * @return string|null One of the TYPE_* constants, or null when not a conflict.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-13
     */
    public function classify(int $statusCode, ?array $serverObject=null): ?string
    {
        if ($statusCode === 403) {
            return self::TYPE_PERMISSION_LOST;
        }

        if ($statusCode === 404) {
            return self::TYPE_DELETED_REMOTE;
        }

        if ($statusCode === 409) {
            if ($serverObject === null || $serverObject === []) {
                return self::TYPE_DELETED_REMOTE;
            }

            return self::TYPE_CONCURRENT_EDIT;
        }

        return null;
    }//end classify()

    /**
     * Whether a conflict of the given type may be retried automatically.
     *
     * Permission-lost conflicts are terminal; the others require a user
     * resolution choice before any retry.
     *
     * @param string $conflictType One of the TYPE_* constants.
     *
     * @return bool True when an automatic retry is ever permitted.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-13
     */
    public function isRetryable(string $conflictType): bool
    {
        return $conflictType !== self::TYPE_PERMISSION_LOST;
    }//end isRetryable()

    /**
     * Build a conflictRecord payload for persistence.
     *
     * @param string                    $syncQueueRef  Reference to the queued operation.
     * @param string                    $conflictType  One of the TYPE_* constants.
     * @param array<string, mixed>      $clientVersion Snapshot of the offline (client) object.
     * @param array<string, mixed>|null $serverVersion Snapshot of the server object, when available.
     * @param string|null               $now           ISO-8601 timestamp (defaults to now).
     *
     * @return array<string, mixed> The conflictRecord payload (resolution null until resolved).
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-13
     */
    public function buildConflictRecord(
        string $syncQueueRef,
        string $conflictType,
        array $clientVersion,
        ?array $serverVersion=null,
        ?string $now=null
    ): array {
        $timestamp = ($now ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM));

        return [
            'syncQueueRef'  => $syncQueueRef,
            'conflictType'  => $conflictType,
            'clientVersion' => $clientVersion,
            'serverVersion' => ($serverVersion ?? []),
            'resolution'    => null,
            'resolvedBy'    => null,
            'resolvedAt'    => null,
            'detectedAt'    => $timestamp,
        ];
    }//end buildConflictRecord()

    /**
     * Compute the field-level differences between client and server versions.
     *
     * Returns a map of fieldName => {client, server} for every scalar field
     * whose values differ, so the merge UI can render a side-by-side diff.
     * Nested arrays/objects are compared by canonical JSON encoding.
     *
     * @param array<string, mixed> $clientVersion Snapshot of the client object.
     * @param array<string, mixed> $serverVersion Snapshot of the server object.
     *
     * @return array<string, array{client: mixed, server: mixed}> The differing fields.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-14
     */
    public function diffVersions(array $clientVersion, array $serverVersion): array
    {
        $diff = [];
        $keys = array_unique(array_merge(array_keys($clientVersion), array_keys($serverVersion)));

        foreach ($keys as $key) {
            $clientValue = ($clientVersion[$key] ?? null);
            $serverValue = ($serverVersion[$key] ?? null);

            if ($this->valuesEqual(a: $clientValue, b: $serverValue) === false) {
                $diff[$key] = [
                    'client' => $clientValue,
                    'server' => $serverValue,
                ];
            }
        }

        return $diff;
    }//end diffVersions()

    /**
     * Compare two values for equality, handling arrays via canonical encoding.
     *
     * @param mixed $a First value.
     * @param mixed $b Second value.
     *
     * @return bool True when the values are considered equal.
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) === true || is_array($b) === true) {
            return json_encode($a) === json_encode($b);
        }

        return $a === $b;
    }//end valuesEqual()

    /**
     * Apply a resolution choice to a conflictRecord.
     *
     * @param array<string, mixed> $conflictRecord The existing conflict record.
     * @param string               $resolution     One of: client_wins, server_wins, manual_merge.
     * @param string               $resolvedBy     User UID who resolved the conflict.
     * @param string|null          $now            ISO-8601 timestamp (defaults to now).
     *
     * @return array<string, mixed> The updated conflict record.
     *
     * @throws \InvalidArgumentException When the resolution choice is invalid.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-14
     */
    public function applyResolution(
        array $conflictRecord,
        string $resolution,
        string $resolvedBy,
        ?string $now=null
    ): array {
        $allowed = ['client_wins', 'server_wins', 'manual_merge'];
        if (in_array($resolution, $allowed, true) === false) {
            throw new InvalidArgumentException('Invalid conflict resolution choice');
        }

        $conflictRecord['resolution'] = $resolution;
        $conflictRecord['resolvedBy'] = $resolvedBy;
        $conflictRecord['resolvedAt'] = ($now ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM));

        return $conflictRecord;
    }//end applyResolution()
}//end class
