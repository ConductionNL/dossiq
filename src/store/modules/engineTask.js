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
 * Dossiq's own task routes: the ones with a dossiq half to them.
 *
 * Completing a task runs what the case type declared completing it does, and
 * refuses when a required field is empty or a declared effect resolves to
 * nothing. Claiming and the rest are the engine's alone.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export const DOSSIQ_TASKS_URL = '/apps/dossiq/api/case-tasks'

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
 * @spec openspec/specs/task-management/spec.md
 */
export function signedDaysUntilDue(task) {
	if (!isRow(task)) {
		return null
	}

	if (typeof task.daysOverdue === 'number') {
		return negatedOverdue(task.daysOverdue)
	}

	if (typeof task.daysUntilDue === 'number') {
		return task.daysUntilDue
	}

	return null
}

/**
 * The register names a task row is given, as `[name, read first, read second]`.
 */
const REGISTER_NAMES = [
	['id', 'uuid', 'id'],
	['status', 'status', 'state'],
	['dueDate', 'dueDate', 'dueAt'],
	['case', 'case', 'objectUuid'],
]

/**
 * Whether a value is an engine row at all: a non-null object.
 *
 * @param {unknown} value The value.
 * @return {boolean} Whether it is.
 */
function isRow(value) {
	return Boolean(value) && typeof value === 'object'
}

/**
 * An overdue count as a negative number of days left.
 *
 * `-0` prints as "0" but fails a `< 0` test, so a task overdue by less than
 * a day stays a plain zero rather than a negative one.
 *
 * @param {number} daysOverdue The days the task is overdue.
 * @return {number} The days left.
 */
function negatedOverdue(daysOverdue) {
	return daysOverdue === 0 ? 0 : -daysOverdue
}

/**
 * A value as a trimmed string, '' for nothing.
 *
 * @param {unknown} value The value.
 * @return {string} The string.
 */
function trimmed(value) {
	return String(value ?? '').trim()
}

/**
 * The text of a refused task write: the engine's message, which names the
 * verb and the reason, else the error's own.
 *
 * @param {unknown} error What the request threw.
 * @return {string} The text.
 */
function refusal(error) {
	return error?.response?.data?.message || error?.message || String(error)
}

/**
 * The text of a refused leaf read or write: the controller's error first.
 *
 * @param {unknown} error What the request threw.
 * @return {string} The text.
 */
function leafRefusal(error) {
	return error?.response?.data?.error
		|| error?.response?.data?.message
		|| error?.message
		|| String(error)
}

/**
 * The rows a leaf read answered, unwrapped, or none when it is not a list.
 *
 * @param {object} response The leaf read's response.
 * @return {Array<object>} The rows.
 */
function leafRows(response) {
	const rows = response.data?.results ?? response.data ?? []
	return Array.isArray(rows) === true ? rows : []
}

/**
 * Run one task write with the store loading, keep the task it answers, or
 * keep the refusal and answer null.
 *
 * @param {object} store The engine task store.
 * @param {() => Promise<object>} request The write.
 * @return {Promise<object|null>} The answered task, or null.
 */
async function keepAnsweredTask(store, request) {
	store.loading = true
	store.error = null
	try {
		const response = await request()
		store.task = response.data?.results ?? response.data ?? null
		return store.task
	} catch (error) {
		store.error = refusal(error)
		return null
	} finally {
		store.loading = false
	}
}

/**
 * Where a lifecycle verb on a task is posted.
 *
 * 🔑 COMPLETE GOES THROUGH DOSSIQ, EVERY OTHER VERB GOES STRAIGHT TO THE
 * ENGINE. Completing a task is the one verb with a dossiq half: the case
 * type declared a form whose required fields must be answered and effects
 * that must resolve, and both are refusals dossiq can make while the
 * handler is still looking at the form. dossiq's endpoint makes them and
 * then hands the completion to the engine, so there is still exactly one
 * completion path and not two that have to agree.
 *
 * @param {string} id The engine task uuid.
 * @param {string} action The verb.
 * @return {string} The URL.
 */
function verbUrl(id, action) {
	return generateUrl(
		action === 'complete'
			? `${DOSSIQ_TASKS_URL}/${encodeURIComponent(id)}/complete`
			: `${FLOW_TASKS_URL}/${encodeURIComponent(id)}/${encodeURIComponent(action)}`,
	)
}

/**
 * The dossiq task fields the engine takes, as `[dossiq name, engine name]`,
 * each sent only when it is not blank.
 */
const OPTIONAL_TASK_FIELDS = [
	['description', 'description'],
	['assignee', 'assignee'],
	['dueDate', 'dueAt'],
	['priority', 'priority'],
	// What sort of work this is, as the surface that made it named
	// it. The engine carries and indexes it and attaches no
	// behaviour to any value, so `reminder` is a label the Tasks
	// index can facet on and nothing else treats specially.
	['kind', 'kind'],
]

/**
 * A dossiq-shaped task as the engine's create payload.
 *
 * @param {object} task The task, in the dossiq shape.
 * @return {object} The payload.
 */
function enginePayload(task) {
	const payload = {
		title: String(task.title ?? ''),
		appId: 'dossiq',
		state: engineState(trimmed(task.status)),
	}

	const caseId = trimmed(task.case ?? task.objectUuid)
	if (caseId !== '') {
		payload.objectUuid = caseId
	}

	copyFilledFields(task, payload)
	return payload
}

/**
 * Copy the optional task fields that are not blank onto the payload, under
 * the engine's names.
 *
 * @param {object} task The task, in the dossiq shape.
 * @param {object} payload The engine payload being built.
 */
function copyFilledFields(task, payload) {
	for (const [from, to] of OPTIONAL_TASK_FIELDS) {
		const value = trimmed(task[from])
		if (value !== '') {
			payload[to] = value
		}
	}
}

/**
 * The engine state for a dossiq status: a terminal state or `active` as
 * given, `available` for everything else (`open` included).
 *
 * @param {string} state The trimmed status.
 * @return {string} The engine state.
 */
function engineState(state) {
	return TERMINAL_STATES.includes(state) || state === 'active'
		? state
		: 'available'
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
 * @spec openspec/specs/task-management/spec.md
 */
export function asTaskRow(row) {
	if (!isRow(row)) {
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
	// `/api/flow-tasks/{uuid}/…` verb take the uuid, so the surfaces
	// (TaskRemindersWidget) built a deep link
	// like `/apps/dossiq/tasks/153` that resolves to nothing. It answered no
	// error, it just went nowhere. Uuid first is the precedence every reader
	// needs, kept here in the one place they all go through.
	//
	// The other three keep `row.<registerName> ?? row.<engineName>`: unlike
	// `id`, none of them is emitted by the engine at all, so the register
	// name is only ever present on a row that already carries it.
	return withRegisterNames(row)
}

/**
 * A copy of an engine row with the register's name added for every value
 * that resolves to something.
 *
 * @param {object} row The engine row.
 * @return {object} The copy.
 */
function withRegisterNames(row) {
	const mapped = { ...row }
	for (const [name, first, second] of REGISTER_NAMES) {
		const value = row[first] ?? row[second]
		if (value !== undefined && value !== null) {
			mapped[name] = value
		}
	}
	return mapped
}

/**
 * The text of a failed read: the error's own message.
 *
 * @param {unknown} error What the request threw.
 * @return {string} The text.
 */
function readFailure(error) {
	return error?.message || String(error)
}

/**
 * Keep the rows and the datastore total a list read answered.
 *
 * @param {object} store The engine task store.
 * @param {object|undefined} data The list response body.
 * @return {Array<object>} The rows kept.
 */
function keepListedRows(store, data) {
	store.tasks = (data?.results ?? []).map(asTaskRow)
	store.total = Number(data?.total ?? store.tasks.length) || 0
	return store.tasks
}

/**
 * Keep the failure of a list read and empty the list.
 *
 * @param {object} store The engine task store.
 * @param {unknown} error What the request threw.
 * @return {Array<object>} No rows.
 */
function clearListedRows(store, error) {
	store.error = readFailure(error)
	store.tasks = []
	store.total = 0
	return []
}

/**
 * Read one task with the store loading, keep it, or keep the failure and
 * answer null.
 *
 * @param {object} store The engine task store.
 * @param {string} id The engine task uuid, trimmed and not blank.
 * @return {Promise<object|null>} The task, or null.
 */
async function readOneTask(store, id) {
	store.loading = true
	store.error = null
	try {
		const response = await axios.get(
			generateUrl(`${FLOW_TASKS_URL}/${encodeURIComponent(id)}`),
		)
		return keepReadTask(store, response.data)
	} catch (error) {
		return clearReadTask(store, error)
	} finally {
		store.loading = false
	}
}

/**
 * Keep the task a read answered, unwrapped from its envelope.
 *
 * @param {object} store The engine task store.
 * @param {object|undefined} data The read response body.
 * @return {object|null} The task kept.
 */
function keepReadTask(store, data) {
	store.task = asTaskRow(data?.results ?? data ?? null)
	return store.task
}

/**
 * Keep the failure of a task read and forget the task.
 *
 * @param {object} store The engine task store.
 * @param {unknown} error What the request threw.
 * @return {null} No task.
 */
function clearReadTask(store, error) {
	store.error = readFailure(error)
	store.task = null
	return null
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
				return keepListedRows(this, response.data)
			} catch (error) {
				return clearListedRows(this, error)
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
			const id = trimmed(uuid)
			if (id === '') {
				return null
			}

			return readOneTask(this, id)
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
		 * @spec openspec/specs/task-management/spec.md
		 */
		async create(task = {}) {
			const payload = enginePayload(task)

			return keepAnsweredTask(this, () => axios.post(
				generateUrl(FLOW_TASKS_URL),
				payload,
			))
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
			const id = trimmed(uuid)
			const action = trimmed(verb)
			if (id === '' || action === '') {
				return null
			}

			// The engine's refusal message is the useful part: it names the
			// verb and the reason. keepAnsweredTask keeps it rather than a
			// generic failure.
			return keepAnsweredTask(this, () => axios.post(verbUrl(id, action), body))
		},

		/**
		 * Toggle one checklist item on a task.
		 *
		 * A PATCH rather than a verb POST, and the flag rides the query
		 * string, because that is the route the engine registers
		 * (`task#checkItem`, `PATCH /flow-tasks/{uuid}/checklist/{itemId}`).
		 * Kept beside `invoke()` rather than folded into it: `invoke` builds
		 * a two-segment verb URL and posts a body, and widening it to carry
		 * a third segment and a method would make every caller pass three
		 * arguments to say nothing.
		 *
		 * @param {string} uuid The engine task uuid.
		 * @param {string} itemId The checklist item id.
		 * @param {boolean} checked The new state.
		 * @return {Promise<object|null>} The updated task, or null on refusal.
		 * @spec openspec/specs/task-management/spec.md
		 */
		async checkItem(uuid, itemId, checked) {
			const id = trimmed(uuid)
			const item = trimmed(itemId)
			if (id === '' || item === '') {
				return null
			}

			return keepAnsweredTask(this, () => axios.patch(
				generateUrl(
					`${FLOW_TASKS_URL}/${encodeURIComponent(id)}/checklist/${encodeURIComponent(item)}`,
				),
				null,
				{ params: { checked: checked === true ? 'true' : 'false' } },
			))
		},

		/**
		 * Read one of a task's leaves.
		 *
		 * 🔴 THESE DO NOT TOUCH `task`, `tasks`, `loading` OR `error`. A leaf
		 * is read while the task itself is on screen, so writing the shared
		 * error would make an unreadable notes list look like an unreadable
		 * TASK, and the page would replace a perfectly good record with a
		 * failure. The outcome is returned instead, and each leaf reports its
		 * own.
		 *
		 * They live here rather than in a service module because this file is
		 * dossiq's whole seam onto the engine: a second module calling
		 * `/api/flow-tasks/...` is the duplicate read this migration exists to
		 * remove.
		 *
		 * @param {string} uuid The engine task uuid.
		 * @param {string} leaf The leaf path segment: `notes`, `events` or `audit`.
		 * @return {Promise<{results: Array<object>, error: string|null}>} The rows, or the failure.
		 * @spec openspec/specs/task-management/spec.md
		 */
		async readLeaf(uuid, leaf) {
			const id = trimmed(uuid)
			const name = trimmed(leaf)
			if (id === '' || name === '') {
				return { results: [], error: null }
			}

			try {
				const response = await axios.get(
					generateUrl(
						`${FLOW_TASKS_URL}/${encodeURIComponent(id)}/${encodeURIComponent(name)}`,
					),
				)
				return { results: leafRows(response), error: null }
			} catch (error) {
				return { results: [], error: leafRefusal(error) }
			}
		},

		/**
		 * Write a note onto a task.
		 *
		 * The endpoint takes one field, `message`, and answers with the
		 * created note bare rather than in a `results` envelope. Both are the
		 * controller's contract, not a guess:
		 * `TaskNotesController::create()` refuses an empty or whitespace-only
		 * message with a 400 naming the field.
		 *
		 * @param {string} uuid The engine task uuid.
		 * @param {string} message The note text.
		 * @return {Promise<{note: object|null, error: string|null}>} The note, or the failure.
		 * @spec openspec/specs/task-management/spec.md
		 */
		async writeNote(uuid, message) {
			const id = trimmed(uuid)
			const text = trimmed(message)
			if (id === '' || text === '') {
				return { note: null, error: null }
			}

			try {
				const response = await axios.post(
					generateUrl(`${FLOW_TASKS_URL}/${encodeURIComponent(id)}/notes`),
					{ message: text },
				)
				return { note: response.data ?? null, error: null }
			} catch (error) {
				return { note: null, error: leafRefusal(error) }
			}
		},

		/**
		 * Remove a note from a task.
		 *
		 * @param {string} uuid The engine task uuid.
		 * @param {string|number} noteId The note's id.
		 * @return {Promise<{removed: boolean, error: string|null}>} The outcome.
		 * @spec openspec/specs/task-management/spec.md
		 */
		async removeNote(uuid, noteId) {
			const id = trimmed(uuid)
			const note = trimmed(noteId)
			if (id === '' || note === '') {
				return { removed: false, error: null }
			}

			try {
				await axios.delete(
					generateUrl(
						`${FLOW_TASKS_URL}/${encodeURIComponent(id)}/notes/${encodeURIComponent(note)}`,
					),
				)
				return { removed: true, error: null }
			} catch (error) {
				return { removed: false, error: leafRefusal(error) }
			}
		},
	},
})
