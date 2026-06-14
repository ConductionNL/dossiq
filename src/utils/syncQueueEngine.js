/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure sync-queue engine for the mobiel-inspectie-offline PWA.
 *
 * This module holds the connectivity-independent decision logic that drives
 * the offline replay loop: queue ordering, the exponential-backoff schedule,
 * per-operation status transitions, conflict classification from an HTTP
 * outcome, and conflict-resolution → next-status mapping.
 *
 * It is deliberately FREE of IndexedDB, the Service Worker, `fetch`, and the
 * DOM so it can be unit-tested exhaustively in a Node/vitest environment. The
 * glue that actually reads the queue from Dexie, performs the network request,
 * and writes the result back lives in `src/services/syncReplayService.js`;
 * this engine only decides *what* should happen.
 *
 * The backoff schedule and status transitions mirror the server-side
 * `SyncBackoffService` / `ConflictDetectionService` exactly so the client and
 * server agree on outcomes.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */

/**
 * Exponential backoff delays in milliseconds, indexed by attempt number.
 * Attempt 1 waits 1s, 2 → 5s, 3 → 30s, 4 → 5min, 5 → 30min. After the final
 * entry the operation is terminal (moved to `failed`).
 *
 * @type {number[]}
 */
export const BACKOFF_SCHEDULE_MS = [1000, 5000, 30000, 300000, 1800000]

/** Maximum replay attempts before an operation is moved to `failed`. */
export const MAX_ATTEMPTS = BACKOFF_SCHEDULE_MS.length

/** Terminal operation statuses — never re-attempted by the replay loop. */
export const TERMINAL_STATUSES = ['synced', 'failed']

/**
 * Order a set of queued operations for replay.
 *
 * Operations replay in ascending `queuedAt` (FIFO) so dependent records (e.g.
 * a ChecklistResult that references a FieldEvidence created earlier) replay in
 * causal order. Ties break on `id` for a stable, deterministic order. Already
 * terminal operations are filtered out.
 *
 * @param {Array<object>} operations The raw queue rows.
 *
 * @return {Array<object>} A new array of replayable operations, FIFO ordered.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */
export function orderForReplay(operations) {
	const replayable = (Array.isArray(operations) ? operations : [])
		.filter((op) => op && TERMINAL_STATUSES.includes(op.status) === false)

	return replayable.slice().sort((a, b) => {
		const qa = String(a.queuedAt ?? '')
		const qb = String(b.queuedAt ?? '')
		if (qa < qb) {
			return -1
		}
		if (qa > qb) {
			return 1
		}
		return String(a.id ?? '').localeCompare(String(b.id ?? ''))
	})
}

/**
 * The backoff delay (ms) to wait before the given attempt number.
 *
 * @param {number} attemptCount Attempts already made (0 → first retry waits 1s).
 *
 * @return {number} Delay in milliseconds; the final schedule entry for any
 *                  attempt at or beyond the schedule length.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */
export function delayForAttempt(attemptCount) {
	const index = Math.max(0, Math.min(attemptCount, BACKOFF_SCHEDULE_MS.length - 1))
	return BACKOFF_SCHEDULE_MS[index]
}

/**
 * Whether an operation may still be retried given its attempt count.
 *
 * @param {number} attemptCount Attempts already made.
 *
 * @return {boolean} True while more attempts remain.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */
export function canRetry(attemptCount) {
	return attemptCount < MAX_ATTEMPTS
}

/**
 * Classify an HTTP replay outcome into a conflict type, or null when there is
 * no conflict.
 *
 *  - 409 with a server object body → `concurrent_edit` (ETag/version mismatch)
 *  - 409 with no body              → `deleted_remote`   (target gone server-side)
 *  - 404                           → `deleted_remote`   (target gone server-side)
 *  - 403                           → `permission_lost`  (not retryable)
 *
 * Mirrors the server-side `ConflictDetectionService::classify()` exactly so the
 * client and server agree on the conflict type.
 *
 * @param {number}      statusCode   The HTTP status from the replay attempt.
 * @param {object|null} serverObject The server object returned with a 409.
 *
 * @return {('concurrent_edit'|'deleted_remote'|'permission_lost'|null)} Type.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
 */
export function classifyConflict(statusCode, serverObject = null) {
	if (statusCode === 403) {
		return 'permission_lost'
	}
	if (statusCode === 404) {
		return 'deleted_remote'
	}
	if (statusCode === 409) {
		const empty = serverObject === null || serverObject === undefined
			|| (typeof serverObject === 'object' && Object.keys(serverObject).length === 0)
		return empty ? 'deleted_remote' : 'concurrent_edit'
	}
	return null
}

/**
 * Whether a conflict type can be retried after resolution.
 *
 * `permission_lost` is terminal — the inspector genuinely lost access and no
 * client choice can re-grant it. Other conflict types are resolvable.
 *
 * @param {string} conflictType The conflict type.
 *
 * @return {boolean} True when retryable.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
 */
export function isConflictRetryable(conflictType) {
	return conflictType !== 'permission_lost'
}

/**
 * Compute the next operation state from a replay attempt outcome.
 *
 * Pure transition function — given the current operation and the HTTP result,
 * it returns the patch to apply (status, attemptCount, lastError) plus an
 * optional `conflictType` and a `nextDelayMs` (when the result is a transient
 * error that should be retried after a backoff). It never mutates the input.
 *
 * Outcomes:
 *  - 2xx                       → `synced`
 *  - 409 / 404                 → `conflict`        (+ conflictType, surfaced to UI)
 *  - 403                       → `failed`          (permission_lost, terminal)
 *  - other (5xx/timeout=0) and attempts remain → `pending` (+ nextDelayMs)
 *  - other and attempts exhausted               → `failed`
 *
 * @param {object} operation      The current queue operation.
 * @param {number} operation.attemptCount Attempts already made.
 * @param {object} result         The replay result.
 * @param {number} result.statusCode      HTTP status (0 → network failure).
 * @param {object} [result.serverObject]  Server object on a 409.
 *
 * @return {{ patch: object, conflictType: (string|null), nextDelayMs: (number|null) }}
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */
export function nextState(operation, result) {
	const attemptCount = Number(operation?.attemptCount ?? 0) + 1
	const statusCode = Number(result?.statusCode ?? 0)
	const serverObject = result?.serverObject ?? null
	const now = (result?.now instanceof Date ? result.now : new Date()).toISOString()

	const base = { attemptCount, lastAttemptAt: now }

	// Success.
	if (statusCode >= 200 && statusCode < 300) {
		return {
			patch: { ...base, status: 'synced', lastError: null, syncedAt: now },
			conflictType: null,
			nextDelayMs: null,
		}
	}

	// Conflict (409 / 404 / 403).
	const conflictType = classifyConflict(statusCode, serverObject)
	if (conflictType !== null) {
		if (isConflictRetryable(conflictType) === false) {
			return {
				patch: { ...base, status: 'failed', lastError: 'permission_lost' },
				conflictType,
				nextDelayMs: null,
			}
		}
		return {
			patch: { ...base, status: 'conflict', lastError: `${statusCode} conflict` },
			conflictType,
			nextDelayMs: null,
		}
	}

	// Transient error (5xx, 0/network). Retry with backoff while attempts remain.
	const errorLabel = statusCode === 0 ? 'network error' : `${statusCode} error`
	if (canRetry(operation?.attemptCount ?? 0) === true && attemptCount < MAX_ATTEMPTS + 1) {
		if (attemptCount > MAX_ATTEMPTS) {
			return {
				patch: { ...base, status: 'failed', lastError: errorLabel },
				conflictType: null,
				nextDelayMs: null,
			}
		}
		return {
			patch: { ...base, status: 'pending', lastError: errorLabel },
			conflictType: null,
			nextDelayMs: delayForAttempt(attemptCount),
		}
	}

	return {
		patch: { ...base, status: 'failed', lastError: errorLabel },
		conflictType: null,
		nextDelayMs: null,
	}
}

/**
 * Compute the operation patch for an inspector's conflict-resolution choice.
 *
 *  - `client_wins`  → re-queue (`pending`) for a forced retry; the server is
 *                     told to accept the client version.
 *  - `manual_merge` → re-queue (`pending`) with the merged payload applied.
 *  - `server_wins`  → discard the local change; mark `synced` (nothing to push).
 *
 * @param {string} resolution    One of client_wins/server_wins/manual_merge.
 * @param {object} [mergedPayload] Payload to use for a manual merge.
 *
 * @return {{ patch: object, requeue: boolean }} The patch and whether to replay.
 *
 * @throws {Error} On an unknown resolution choice.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
 */
export function resolveConflictChoice(resolution, mergedPayload = null) {
	switch (resolution) {
	case 'client_wins':
		return { patch: { status: 'pending', forceUpdate: true, attemptCount: 0 }, requeue: true }
	case 'manual_merge':
		return {
			patch: {
				status: 'pending',
				forceUpdate: true,
				attemptCount: 0,
				payload: mergedPayload,
			},
			requeue: true,
		}
	case 'server_wins':
		return { patch: { status: 'synced', forceUpdate: false }, requeue: false }
	default:
		throw new Error(`Unknown conflict resolution: ${resolution}`)
	}
}

/**
 * Build a field-level diff between the client and server versions of a record,
 * for the side-by-side merge UI. Returns one entry per field that differs.
 *
 * @param {object} clientVersion The inspector's offline version.
 * @param {object} serverVersion The server's current version.
 *
 * @return {Array<{ field: string, client: *, server: * }>} The differing fields.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
 */
export function diffVersions(clientVersion, serverVersion) {
	const client = clientVersion ?? {}
	const server = serverVersion ?? {}
	const fields = new Set([...Object.keys(client), ...Object.keys(server)])
	const diff = []

	for (const field of fields) {
		const cv = client[field]
		const sv = server[field]
		if (JSON.stringify(cv) !== JSON.stringify(sv)) {
			diff.push({ field, client: cv ?? null, server: sv ?? null })
		}
	}

	return diff.sort((a, b) => a.field.localeCompare(b.field))
}
