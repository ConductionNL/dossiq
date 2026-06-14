/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IndexedDB (Dexie.js) local store for the mobiel-inspectie-offline PWA.
 *
 * Defines the six offline tables that mirror the OpenRegister schemas in
 * `lib/Settings/register.d/40-mobiel-inspectie-offline.json`. The Service
 * Worker and the Vue views read/write through this store while the device is
 * offline; the sync-queue engine (`syncQueueEngine.js`) replays the queued
 * mutations against the server when the device reconnects.
 *
 * Dexie is loaded lazily (`getDb()`) so the pure engine/helper modules — which
 * carry the mandatory unit-test surface — stay importable in a Node/vitest
 * environment that has no IndexedDB. Tests never touch this module.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */

import Dexie from 'dexie'

/**
 * Schema version 1 of the local offline database.
 *
 * Index notes (Dexie `&`=unique primary key, plain=indexed):
 *  - fieldInspection: by caseRef (open a case) and status (filter the planning)
 *  - checklistResult / fieldEvidence: by inspectionRef (load a case's work)
 *  - syncQueue: by status + queuedAt (replay ordering) and deviceId (IDOR scope)
 *  - conflictRecord: by syncQueueRef (badge + merge UI lookup)
 */
const DB_NAME = 'procest-mobiel-inspectie'

let dbInstance = null

/**
 * Open (and memoise) the Dexie database.
 *
 * @return {Dexie} The opened database handle.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
export function getDb() {
	if (dbInstance !== null) {
		return dbInstance
	}

	const db = new Dexie(DB_NAME)
	db.version(1).stores({
		fieldInspection: 'id, caseRef, status, scheduledAt, deviceId',
		checklistResult: 'id, inspectionRef, checklistTemplateRef',
		fieldEvidence: 'id, inspectionRef, type, transcriptionStatus',
		checklistTemplate: 'id, domain, version',
		syncQueue: 'id, deviceId, status, queuedAt, targetEntity',
		conflictRecord: 'id, syncQueueRef, conflictType, resolution',
		// Singleton meta rows (planning expiry, consent, sync checkpoint).
		meta: 'key',
	})

	dbInstance = db
	return db
}

/**
 * Persist a downloaded daily-planning payload into the local store.
 *
 * Atomic: every table write happens in one Dexie transaction so a connection
 * drop mid-write never leaves the planning half-populated.
 *
 * @param {object} payload The `GET /api/sync/daily` response.
 * @param {Array}  payload.cases        FieldInspection records.
 * @param {Array}  payload.checklists   ChecklistTemplate records.
 * @param {object} [payload.manifest]   Map-tile + size manifest.
 *
 * @return {Promise<void>} Resolves when the planning is stored.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
 */
export async function storeDailyPlanning(payload) {
	const db = getDb()
	const cases = Array.isArray(payload?.cases) ? payload.cases : []
	const checklists = Array.isArray(payload?.checklists) ? payload.checklists : []
	const expiresAt = new Date(Date.now() + (24 * 60 * 60 * 1000)).toISOString()

	await db.transaction('rw', db.fieldInspection, db.checklistTemplate, db.meta, async () => {
		await db.fieldInspection.bulkPut(cases)
		await db.checklistTemplate.bulkPut(checklists)
		await db.meta.put({
			key: 'planning',
			status: 'ready_offline',
			expiresAt,
			syncedAt: new Date().toISOString(),
			manifest: payload?.manifest ?? null,
		})
	})
}

/**
 * Read the stored planning meta row (or null when never synced).
 *
 * @return {Promise<object|null>} The planning meta row.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
 */
export async function getPlanningMeta() {
	const db = getDb()
	return (await db.meta.get('planning')) ?? null
}

/**
 * Count all sync-queue operations not yet synced — drives the pending badge.
 *
 * @param {string} [deviceId] Optional device scope.
 *
 * @return {Promise<number>} The number of pending/conflict/failed operations.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
export async function countPending(deviceId) {
	const db = getDb()
	let collection = db.syncQueue.where('status').anyOf(['pending', 'conflict', 'syncing'])
	if (typeof deviceId === 'string' && deviceId !== '') {
		collection = collection.and((op) => op.deviceId === deviceId)
	}
	return await collection.count()
}

/**
 * Reset the memoised handle — test/teardown only.
 *
 * @return {void}
 *
 * @spec exclude test/teardown helper, no behaviour
 */
export function __resetDbForTests() {
	dbInstance = null
}
