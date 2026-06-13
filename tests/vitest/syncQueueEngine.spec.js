/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Exhaustive unit tests for the pure sync-queue engine
 * (src/utils/syncQueueEngine.js): queue ordering, exponential backoff,
 * conflict classification, the replay state-transition function, and the
 * conflict-resolution → next-status mapping.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */

import { describe, it, expect } from 'vitest'
import {
	BACKOFF_SCHEDULE_MS,
	MAX_ATTEMPTS,
	orderForReplay,
	delayForAttempt,
	canRetry,
	classifyConflict,
	isConflictRetryable,
	nextState,
	resolveConflictChoice,
	diffVersions,
} from '../../src/utils/syncQueueEngine.js'

describe('orderForReplay', () => {
	it('orders pending operations FIFO by queuedAt', () => {
		const ops = [
			{ id: 'c', queuedAt: '2026-05-22T09:03:00Z', status: 'pending' },
			{ id: 'a', queuedAt: '2026-05-22T09:01:00Z', status: 'pending' },
			{ id: 'b', queuedAt: '2026-05-22T09:02:00Z', status: 'pending' },
		]
		expect(orderForReplay(ops).map((o) => o.id)).toEqual(['a', 'b', 'c'])
	})

	it('breaks queuedAt ties deterministically on id', () => {
		const ops = [
			{ id: 'z', queuedAt: '2026-05-22T09:00:00Z', status: 'pending' },
			{ id: 'a', queuedAt: '2026-05-22T09:00:00Z', status: 'pending' },
		]
		expect(orderForReplay(ops).map((o) => o.id)).toEqual(['a', 'z'])
	})

	it('excludes terminal (synced / failed) operations', () => {
		const ops = [
			{ id: 'a', queuedAt: '1', status: 'synced' },
			{ id: 'b', queuedAt: '2', status: 'failed' },
			{ id: 'c', queuedAt: '3', status: 'pending' },
			{ id: 'd', queuedAt: '4', status: 'conflict' },
		]
		expect(orderForReplay(ops).map((o) => o.id)).toEqual(['c', 'd'])
	})

	it('tolerates non-array input', () => {
		expect(orderForReplay(null)).toEqual([])
		expect(orderForReplay(undefined)).toEqual([])
	})

	it('does not mutate the input array', () => {
		const ops = [
			{ id: 'b', queuedAt: '2', status: 'pending' },
			{ id: 'a', queuedAt: '1', status: 'pending' },
		]
		orderForReplay(ops)
		expect(ops.map((o) => o.id)).toEqual(['b', 'a'])
	})
})

describe('delayForAttempt / backoff schedule', () => {
	it('follows the 1s/5s/30s/5min/30min schedule', () => {
		expect(BACKOFF_SCHEDULE_MS).toEqual([1000, 5000, 30000, 300000, 1800000])
		expect(delayForAttempt(0)).toBe(1000)
		expect(delayForAttempt(1)).toBe(5000)
		expect(delayForAttempt(2)).toBe(30000)
		expect(delayForAttempt(3)).toBe(300000)
		expect(delayForAttempt(4)).toBe(1800000)
	})

	it('clamps beyond the schedule to the final delay', () => {
		expect(delayForAttempt(99)).toBe(1800000)
	})

	it('clamps negative attempts to the first delay', () => {
		expect(delayForAttempt(-3)).toBe(1000)
	})
})

describe('canRetry', () => {
	it('allows retries while under MAX_ATTEMPTS', () => {
		expect(canRetry(0)).toBe(true)
		expect(canRetry(MAX_ATTEMPTS - 1)).toBe(true)
	})

	it('refuses retries at or beyond MAX_ATTEMPTS', () => {
		expect(canRetry(MAX_ATTEMPTS)).toBe(false)
		expect(canRetry(MAX_ATTEMPTS + 1)).toBe(false)
	})
})

describe('classifyConflict', () => {
	it('maps 409 with a server body to concurrent_edit', () => {
		expect(classifyConflict(409, { id: 'x', status: 'afgekeurd' })).toBe('concurrent_edit')
	})

	it('maps 409 with no body to deleted_remote (matches the server)', () => {
		expect(classifyConflict(409, null)).toBe('deleted_remote')
		expect(classifyConflict(409, {})).toBe('deleted_remote')
	})

	it('maps 404 to deleted_remote', () => {
		expect(classifyConflict(404)).toBe('deleted_remote')
	})

	it('maps 403 to permission_lost', () => {
		expect(classifyConflict(403)).toBe('permission_lost')
	})

	it('returns null for success and transient errors', () => {
		expect(classifyConflict(200)).toBeNull()
		expect(classifyConflict(503)).toBeNull()
		expect(classifyConflict(0)).toBeNull()
	})
})

describe('isConflictRetryable', () => {
	it('marks permission_lost as terminal', () => {
		expect(isConflictRetryable('permission_lost')).toBe(false)
	})

	it('marks concurrent_edit and deleted_remote as retryable', () => {
		expect(isConflictRetryable('concurrent_edit')).toBe(true)
		expect(isConflictRetryable('deleted_remote')).toBe(true)
	})
})

describe('nextState', () => {
	const now = new Date('2026-05-22T12:00:00Z')

	it('marks a 2xx as synced with a syncedAt timestamp', () => {
		const r = nextState({ attemptCount: 0 }, { statusCode: 201, now })
		expect(r.patch.status).toBe('synced')
		expect(r.patch.attemptCount).toBe(1)
		expect(r.patch.lastError).toBeNull()
		expect(r.patch.syncedAt).toBe(now.toISOString())
		expect(r.conflictType).toBeNull()
		expect(r.nextDelayMs).toBeNull()
	})

	it('marks a 409 as conflict with conflictType, no auto-retry', () => {
		const r = nextState({ attemptCount: 0 }, { statusCode: 409, serverObject: { x: 1 }, now })
		expect(r.patch.status).toBe('conflict')
		expect(r.conflictType).toBe('concurrent_edit')
		expect(r.nextDelayMs).toBeNull()
	})

	it('marks a 404 as conflict deleted_remote', () => {
		const r = nextState({ attemptCount: 0 }, { statusCode: 404, now })
		expect(r.patch.status).toBe('conflict')
		expect(r.conflictType).toBe('deleted_remote')
	})

	it('marks a 403 as failed permission_lost (terminal)', () => {
		const r = nextState({ attemptCount: 0 }, { statusCode: 403, now })
		expect(r.patch.status).toBe('failed')
		expect(r.patch.lastError).toBe('permission_lost')
		expect(r.conflictType).toBe('permission_lost')
		expect(r.nextDelayMs).toBeNull()
	})

	it('retries a 503 with backoff while attempts remain', () => {
		const r = nextState({ attemptCount: 0 }, { statusCode: 503, now })
		expect(r.patch.status).toBe('pending')
		expect(r.patch.attemptCount).toBe(1)
		expect(r.patch.lastError).toBe('503 error')
		expect(r.nextDelayMs).toBe(5000) // delay for attempt #1 (second try)
	})

	it('retries a network failure (statusCode 0) with backoff', () => {
		const r = nextState({ attemptCount: 1 }, { statusCode: 0, now })
		expect(r.patch.status).toBe('pending')
		expect(r.patch.lastError).toBe('network error')
		expect(r.nextDelayMs).toBe(30000) // delay for attempt #2
	})

	it('moves to failed once attempts are exhausted', () => {
		const r = nextState({ attemptCount: MAX_ATTEMPTS }, { statusCode: 503, now })
		expect(r.patch.status).toBe('failed')
		expect(r.patch.lastError).toBe('503 error')
		expect(r.nextDelayMs).toBeNull()
	})

	it('does not mutate the input operation', () => {
		const op = { attemptCount: 2, status: 'pending' }
		nextState(op, { statusCode: 200, now })
		expect(op).toEqual({ attemptCount: 2, status: 'pending' })
	})
})

describe('resolveConflictChoice', () => {
	it('client_wins re-queues with a force-update flag', () => {
		const r = resolveConflictChoice('client_wins')
		expect(r.requeue).toBe(true)
		expect(r.patch.status).toBe('pending')
		expect(r.patch.forceUpdate).toBe(true)
		expect(r.patch.attemptCount).toBe(0)
	})

	it('manual_merge re-queues with the merged payload', () => {
		const merged = { answer: 'goedgekeurd onder voorwaarden' }
		const r = resolveConflictChoice('manual_merge', merged)
		expect(r.requeue).toBe(true)
		expect(r.patch.payload).toEqual(merged)
		expect(r.patch.forceUpdate).toBe(true)
	})

	it('server_wins discards locally and marks synced', () => {
		const r = resolveConflictChoice('server_wins')
		expect(r.requeue).toBe(false)
		expect(r.patch.status).toBe('synced')
		expect(r.patch.forceUpdate).toBe(false)
	})

	it('throws on an unknown choice', () => {
		expect(() => resolveConflictChoice('bogus')).toThrow(/Unknown conflict resolution/)
	})
})

describe('diffVersions', () => {
	it('lists only the fields that differ, sorted by field', () => {
		const client = { status: 'goedgekeurd', notes: 'ok', shared: 1 }
		const server = { status: 'afgekeurd', extra: 'x', shared: 1 }
		expect(diffVersions(client, server)).toEqual([
			{ field: 'extra', client: null, server: 'x' },
			{ field: 'notes', client: 'ok', server: null },
			{ field: 'status', client: 'goedgekeurd', server: 'afgekeurd' },
		])
	})

	it('returns empty when versions are identical', () => {
		expect(diffVersions({ a: 1 }, { a: 1 })).toEqual([])
	})

	it('treats deep-equal objects as unchanged', () => {
		expect(diffVersions({ gps: { lat: 1, lon: 2 } }, { gps: { lat: 1, lon: 2 } })).toEqual([])
	})

	it('tolerates null inputs', () => {
		expect(diffVersions(null, { a: 1 })).toEqual([{ field: 'a', client: null, server: 1 }])
	})
})
