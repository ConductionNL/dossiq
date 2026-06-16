/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sync-replay glue for the mobiel-inspectie-offline PWA.
 *
 * Ties the three testable pieces together at runtime:
 *  - the Dexie store (`src/store/offlineDb.js`) holds the queued operations,
 *  - the pure `syncQueueEngine` decides ordering / next-state / backoff,
 *  - this service performs the actual replay against OpenRegister and reports
 *    the outcome to the server (`POST /api/sync/queue/{id}/outcome`) so the
 *    server re-authorizes the operation (the inspector may only push their own
 *    queued operations — IDOR is enforced server-side, never client-asserted).
 *
 * This module is browser-only (axios + IndexedDB + navigator.onLine) and is
 * exercised by Playwright. The decision logic it calls is unit-tested in
 * `tests/vitest/syncQueueEngine.spec.js`.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getDb } from '../store/offlineDb.js'
import { orderForReplay, nextState } from '../utils/syncQueueEngine.js'

const objectsUrl = (entity) => generateUrl(`/apps/openregister/api/objects/procest/${entity}`)
const outcomeUrl = (id) => generateUrl(`/apps/procest/api/sync/queue/${id}/outcome`)

/**
 * Replay one queued operation against OpenRegister and report the outcome.
 *
 * @param {object} operation The queue operation row.
 * @param {string} deviceId  The owning device (for the server IDOR scope).
 *
 * @return {Promise<object>} The applied patch from the engine.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */
export async function replayOperation(operation, deviceId) {
	const db = getDb()
	let statusCode = 0
	let serverObject = null

	try {
		const payload = operation.payload ?? {}
		let response
		if (operation.operationType === 'create' || operation.operationType === 'upload') {
			response = await axios.post(objectsUrl(operation.targetEntity), payload)
		} else if (operation.operationType === 'update') {
			response = await axios.put(`${objectsUrl(operation.targetEntity)}/${operation.targetId}`, payload)
		} else if (operation.operationType === 'delete') {
			response = await axios.delete(`${objectsUrl(operation.targetEntity)}/${operation.targetId}`)
		} else {
			response = await axios.post(objectsUrl(operation.targetEntity), payload)
		}
		statusCode = response.status
	} catch (error) {
		statusCode = error?.response?.status ?? 0
		serverObject = error?.response?.data ?? null
	}

	const { patch } = nextState(operation, { statusCode, serverObject })

	// Report the outcome so the server re-authorizes ownership and persists the
	// queue/conflict transition (the authoritative status lives server-side).
	try {
		await axios.post(outcomeUrl(operation.id), { deviceId, statusCode, serverObject })
	} catch (e) {
		// Server unreachable — the local patch still reflects the attempt; the
		// next drain re-reports it.
	}

	await db.syncQueue.update(operation.id, patch)
	return patch
}

/**
 * Drain the device's pending queue in FIFO order.
 *
 * @param {string} deviceId The owning device.
 *
 * @return {Promise<{ processed: number, synced: number, conflicts: number, failed: number }>}
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
 */
export async function drainQueue(deviceId) {
	if (typeof navigator !== 'undefined' && navigator.onLine === false) {
		return { processed: 0, synced: 0, conflicts: 0, failed: 0 }
	}

	const db = getDb()
	const rows = await db.syncQueue.where('deviceId').equals(deviceId).toArray()
	const ordered = orderForReplay(rows)

	const tally = { processed: 0, synced: 0, conflicts: 0, failed: 0 }
	for (const operation of ordered) {
		const patch = await replayOperation(operation, deviceId)
		tally.processed += 1
		if (patch.status === 'synced') {
			tally.synced += 1
		} else if (patch.status === 'conflict') {
			tally.conflicts += 1
		} else if (patch.status === 'failed') {
			tally.failed += 1
		}
	}

	return tally
}
