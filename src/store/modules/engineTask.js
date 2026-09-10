// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Dossiq's read side onto OpenRegister's task engine.
 *
 * WHY A DOSSIQ STORE AND NOT `useObjectStore`
 * -------------------------------------------
 * Every task surface in dossiq reads `register: dossiq, schema: caseTask`
 * through the generic object store. The engine is not an OpenRegister object
 * at all: it is a first-class entity behind `/api/flow-tasks`, with its own
 * lifecycle verbs. There is no register or schema to point the object store
 * at, so the read needs its own seam.
 *
 * WHY NOT `useTaskInboxStore` FROM THE LIBRARY
 * --------------------------------------------
 * It is used, for the LIST. `@conduction/nextcloud-vue` ships
 * `useTaskInboxStore`, which wraps `GET /api/flow-tasks` and already accepts
 * the `objectUuid` filter the case surfaces need, so listing is not
 * reimplemented here.
 *
 * What it does not carry is a read of ONE task, or any of the lifecycle
 * verbs. Both are plain endpoints on the same controller
 * (`appinfo/routes.php`: `task#show`, `task#complete`, `task#claim`, …), so
 * they are called directly rather than waited for. When the library grows
 * them, those methods here are deleted and the calls move.
 *
 * 🔴 ONE VOCABULARY, NOT TWO. The engine's `Task::STATES` is the same CMMN
 * vocabulary `caseTask` used (available / active / completed / terminated /
 * disabled) and `TaskPriority`'s canonical four are the same four. Nothing in
 * this file translates a state or a priority, and adding a mapping table
 * would be a defect rather than a convenience: it would silently diverge the
 * two the first time one side gained a value.
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/** The engine's task collection. */
export const FLOW_TASKS_URL = '/apps/openregister/api/flow-tasks'

/**
 * The states the engine treats as terminal.
 *
 * The same three `caseTask`'s lifecycle declared final, because it is the
 * same vocabulary. Kept as a named constant rather than inlined so the one
 * place that has to change if the engine ever widens it is findable.
 *
 * @type {string[]}
 */
export const TERMINAL_STATES = Object.freeze([
	'completed',
	'terminated',
	'disabled',
])

/**
 * Whether a task is finished.
 *
 * Prefers the engine's own `isTerminal`, which it materialises on write, and
 * falls back to the state only when the row does not carry it. Reading the
 * state first would re-derive a fact the engine already decided, and the two
 * can disagree the moment the engine adds a state.
 *
 * @param {object|null|undefined} task The task row.
 * @return {boolean} True when the task is in a terminal state.
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */
export function isTerminal(task) {
	if (!task || typeof task !== 'object') {
		return false
	}

	if (typeof task.isTerminal === 'boolean') {
		return task.isTerminal
	}

	return TERMINAL_STATES.includes(String(task.state ?? '').trim())
}

export const useEngineTaskStore = defineStore('dossiqEngineTask', {
	state: () => ({
		/** @type {Array<object>} The rows of the most recent list. */
		tasks: [],
		/** @type {number} The datastore total, independent of the page size. */
		total: 0,
		/** @type {object|null} The single task most recently read. */
		task: null,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/**
		 * @type {string|null} The last failure.
		 *
		 * Surfaced rather than swallowed. An empty task list with no trace of
		 * why is indistinguishable from a genuinely empty one, and that is the
		 * failure shape this whole migration keeps producing.
		 */
		error: null,
	}),

	actions: {
		/**
		 * List the engine's tasks.
		 *
		 * @param {object} params Query parameters (`objectUuid`, `state`, `scope`, `limit`, …).
		 * @return {Promise<Array<object>>} The rows.
		 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
		 */
		async list(params = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(generateUrl(FLOW_TASKS_URL), { params })
				this.tasks = response.data?.results ?? []
				this.total = Number(response.data?.total ?? this.tasks.length) || 0
				return this.tasks
			} catch (error) {
				this.error = error?.message || String(error)
				this.tasks = []
				this.total = 0
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The open tasks on one case, soonest due first.
		 *
		 * `scope: 'all'` on purpose: the case page shows the case's work, not
		 * the reader's. Scoping to the caller would hide a colleague's task
		 * and make the case look finished when it is not.
		 *
		 * @param {string} caseId The case (object) uuid.
		 * @return {Promise<Array<object>>} The open tasks.
		 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
		 */
		async openForCase(caseId) {
			const id = String(caseId ?? '').trim()
			if (id === '') {
				return []
			}

			const rows = await this.list({
				objectUuid: id,
				scope: 'all',
				sort: 'dueAt',
				limit: 100,
			})

			return rows.filter((row) => !isTerminal(row))
		},

		/**
		 * Read one task.
		 *
		 * @param {string} uuid The engine task uuid.
		 * @return {Promise<object|null>} The task, or null.
		 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
		 */
		async fetch(uuid) {
			const id = String(uuid ?? '').trim()
			if (id === '') {
				return null
			}

			this.loading = true
			this.error = null
			try {
				const response = await axios.get(generateUrl(`${FLOW_TASKS_URL}/${encodeURIComponent(id)}`))
				this.task = response.data?.results ?? response.data ?? null
				return this.task
			} catch (error) {
				this.error = error?.message || String(error)
				this.task = null
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Invoke a lifecycle verb on a task.
		 *
		 * The engine decides whether the verb is legal and whether the caller
		 * may invoke it, and refuses visibly. Nothing here pre-judges that:
		 * a client-side guess about which verbs are available is exactly the
		 * duplicated authorization the migration exists to remove.
		 *
		 * @param {string} uuid The engine task uuid.
		 * @param {string} verb One of claim / unclaim / complete / cancel / resolve / …
		 * @param {object} body The verb's payload.
		 * @return {Promise<object|null>} The updated task, or null on refusal.
		 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
		 */
		async invoke(uuid, verb, body = {}) {
			const id = String(uuid ?? '').trim()
			const action = String(verb ?? '').trim()
			if (id === '' || action === '') {
				return null
			}

			this.loading = true
			this.error = null
			try {
				const url = generateUrl(`${FLOW_TASKS_URL}/${encodeURIComponent(id)}/${encodeURIComponent(action)}`)
				const response = await axios.post(url, body)
				this.task = response.data?.results ?? response.data ?? null
				return this.task
			} catch (error) {
				// The engine's refusal message is the useful part: it names the
				// verb and the reason. Keep it rather than a generic failure.
				this.error = error?.response?.data?.message || error?.message || String(error)
				return null
			} finally {
				this.loading = false
			}
		},
	},
})
