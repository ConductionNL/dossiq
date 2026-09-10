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
export const TERMINAL_STATES = Object.freeze(['completed', 'terminated', 'disabled'])

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

/**
 * The signed distance to a task's deadline, in whole days.
 *
 * The engine reports the two directions in two fields and never both.
 * `TaskInboxService::row()` attaches a projection per row: `daysUntilDue`
 * counts down and is null once the deadline has passed, `daysOverdue`
 * counts up and is null before it. A "days left" column reads one signed
 * number, with an overdue task carrying a negative one, so the two are
 * folded here rather than in each widget.
 *
 * Derived on the server, never stored, so this reads the projection and
 * does not recompute it from `dueAt`. A second clock in the client would
 * disagree with the badge the engine already decided.
 *
 * @param {object|null|undefined} task The engine row.
 * @return {number|null} Days left, negative when overdue, null with no deadline.
 * @spec openspec/changes/remove-casetask/tasks.md
 */
export function signedDaysUntilDue(task) {
	if (!task || typeof task !== 'object') {
		return null
	}

	if (typeof task.daysOverdue === 'number') {
		// `-0` prints as "0" but fails a `< 0` test, so a task overdue by
		// less than a day stays a plain zero rather than a negative one.
		return task.daysOverdue === 0 ? 0 : -task.daysOverdue
	}

	if (typeof task.daysUntilDue === 'number') {
		return task.daysUntilDue
	}

	return null
}

/**
 * One engine row in the vocabulary dossiq's components read.
 *
 * 🔴 THE WRITE PATH MAPPED AND THE READ PATH DID NOT, and the result was a
 * task due today rendering "No due date" on the case pane. `create()` has
 * translated `dueDate` to the engine's `dueAt` since the first day; rows
 * came back raw, so every consumer that asked for `row.dueDate` got
 * `undefined` and rendered its empty state. Nothing failed: an absent
 * deadline is a legitimate value, so the surfaces looked correct.
 *
 * Mapped HERE rather than in each component, for the same reason
 * `EngineTaskInbox::asArray()` does it in one place on the server: five
 * surfaces read these rows, and a sixth is coming.
 *
 * The engine's own keys are KEPT alongside, not replaced. `isTerminal()`
 * reads `state`, the row-click handlers read `uuid`, and the task page
 * needs both spellings while it still talks to two stores.
 *
 * @param {object} row The engine row.
 * @return {object} The row, plus the register's names for the same values.
 * @spec openspec/changes/remove-casetask/tasks.md
 */
export function asTaskRow(row) {
	if (!row || typeof row !== 'object') {
		return row
	}

	// Only keys that resolve to something are added. Writing
	// `dueDate: undefined` onto every row would make a task with no deadline
	// carry the key anyway, which reads as "we looked and there is one" to
	// anything doing `'dueDate' in row` and shows up in every diff.
	// 🔴 `uuid` WINS OVER `id`, AND THE OTHER THREE TAKE THE REGISTER NAME
	// FIRST. Every engine row carries BOTH: `Task::jsonSerialize()` emits
	// `id` (the database primary key) beside `uuid`, so `row.id ?? row.uuid`
	// never once reached the uuid and this mapping was a no-op on real data.
	// No route or verb accepts the numeric key: `/tasks/:id` and every
	// `/api/flow-tasks/{uuid}/…` verb take the uuid, so three surfaces
	// (MyTasksWidget, TaskRemindersWidget, CaseTasksTab) built a deep link
	// like `/apps/dossiq/tasks/153` that resolves to nothing. It answered no
	// error, it just went nowhere. `taskIdOf()` in `caseTaskPaneHelpers.js`
	// already reads uuid first, and this is the same precedence in the one
	// place every reader goes through.
	//
	// The other three keep `row.<registerName> ?? row.<engineName>`: unlike
	// `id`, none of them is emitted by the engine at all, so the register
	// name is only ever present on a row that already carries it.
	const mapped = { ...row }
	for (const [name, value] of [
		['id', row.uuid ?? row.id],
		['status', row.status ?? row.state],
		['dueDate', row.dueDate ?? row.dueAt],
		['case', row.case ?? row.objectUuid],
	]) {
		if (value !== undefined && value !== null) {
			mapped[name] = value
		}
	}

	return mapped
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
				const response = await axios.get(generateUrl(FLOW_TASKS_URL), {
					params,
				})
				this.tasks = (response.data?.results ?? []).map(asTaskRow)
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
				const response = await axios.get(
					generateUrl(`${FLOW_TASKS_URL}/${encodeURIComponent(id)}`),
				)
				this.task = asTaskRow(
					response.data?.results ?? response.data ?? null,
				)
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
		 * Create a task.
		 *
		 * Takes the dossiq shape callers already write (`case`, `status`,
		 * `dueDate`) and maps it, so the four call sites do not each learn
		 * the engine's vocabulary. The mapping is the same one
		 * `EngineTaskGateway::toEnginePayload()` does server-side.
		 *
		 * 🔴 `status` DEFAULTS TO `available`, AND CALLERS PASSING `'open'`
		 * ARE CORRECTED HERE. Four call sites wrote `status: 'open'`, which
		 * is out of enum on BOTH stores: `caseTask` declared
		 * available|active|completed|terminated|disabled, and `Task::STATES`
		 * declares the same five. Every task they created was born in a state
		 * no transition could advance. `CreateTaskHandler` had the same bug
		 * and was fixed in #1326; these four were missed, which is precisely
		 * the cost of a concept duplicated across five call sites.
		 *
		 * The engine would refuse `'open'` outright (`TaskState::normalise()`
		 * refuses an unmapped status naming itself), so without this
		 * correction the migration would turn four silently-broken writes
		 * into four loud failures. Correcting is right: `available` is what
		 * they meant, and it is what #1326 chose.
		 *
		 * @param {object} task The task, in the dossiq shape.
		 * @return {Promise<object|null>} The created task, or null.
		 * @spec openspec/changes/remove-casetask/tasks.md
		 */
		async create(task = {}) {
			const state = String(task.status ?? '').trim()
			const payload = {
				title: String(task.title ?? ''),
				appId: 'dossiq',
				state:
					TERMINAL_STATES.includes(state) || state === 'active'
						? state
						: 'available',
			}

			const caseId = String(task.case ?? task.objectUuid ?? '').trim()
			if (caseId !== '') {
				payload.objectUuid = caseId
			}

			for (const [from, to] of [
				['description', 'description'],
				['assignee', 'assignee'],
				['dueDate', 'dueAt'],
				['priority', 'priority'],
			]) {
				const value = String(task[from] ?? '').trim()
				if (value !== '') {
					payload[to] = value
				}
			}

			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(FLOW_TASKS_URL),
					payload,
				)
				this.task = response.data?.results ?? response.data ?? null
				return this.task
			} catch (error) {
				this.error =
					error?.response?.data?.message || error?.message || String(error)
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
				const url = generateUrl(
					`${FLOW_TASKS_URL}/${encodeURIComponent(id)}/${encodeURIComponent(action)}`,
				)
				const response = await axios.post(url, body)
				this.task = response.data?.results ?? response.data ?? null
				return this.task
			} catch (error) {
				// The engine's refusal message is the useful part: it names the
				// verb and the reason. Keep it rather than a generic failure.
				this.error =
					error?.response?.data?.message || error?.message || String(error)
				return null
			} finally {
				this.loading = false
			}
		},
	},
})
