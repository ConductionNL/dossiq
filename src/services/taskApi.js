/**
 * Task API wrapper for fetching CalDAV tasks via OpenRegister's convenience API.
 *
 * Uses the per-object endpoint: GET /apps/openregister/api/objects/{register}/{schema}/{id}/tasks
 * Since there is no register-level endpoint, we fetch user's cases first,
 * then batch-fetch tasks per case (DD-01 fallback strategy).
 */

import { useObjectStore } from '../store/modules/object.js'

/**
 * Build standard headers for OpenRegister API calls.
 *
 * @return {object} Headers object with requesttoken
 */
function getHeaders() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * The app priority per range of iCalendar priorities, as `[min, max, priority]`.
 */
const PRIORITY_RANGES = [
	[1, 3, 'urgent'],
	[4, 4, 'high'],
	[5, 6, 'normal'],
	[7, 9, 'low'],
]

/**
 * Map iCalendar priority (0-9) to app priority string.
 * 1-3 → 'urgent', 4 → 'high', 5-6 → 'normal', 7-9 → 'low', 0 → 'normal'
 *
 * @param {number} icalPriority iCalendar priority value (0-9)
 * @return {string} App priority: 'urgent' | 'high' | 'normal' | 'low'
 */
function mapCalDavPriority(icalPriority) {
	const p = Number(icalPriority) || 0
	const range = PRIORITY_RANGES.find(([min, max]) => p >= min && p <= max)
	return range ? range[2] : 'normal'
}

/**
 * Normalize a CalDAV task JSON object (from OpenRegister tasks API) to the
 * standard work item shape used by dashboardHelpers.
 *
 * @param {object} task CalDAV task object from API
 * @return {object} Normalized work item
 * @spec openspec/specs/task-management/spec.md
 */
export function normalizeCalDavTask(task) {
	return {
		type: 'task',
		...taskIdentity(task),
		...taskTiming(task),
		priority: mapCalDavPriority(task.priority),
		status: task.status || 'needs-action',
	}
}

/**
 * Which task this is, and the object it belongs to.
 *
 * @param {object} task CalDAV task object from API
 * @return {object} The id, calendar, uri, title, reference and object fields.
 */
function taskIdentity(task) {
	return {
		id: task.uid || task.id,
		calendarId: task.calendarId || null,
		taskUri: task.id || null,
		title: task.summary || '—',
		reference: objectReference(task.objectUuid),
		objectUuid: task.objectUuid || null,
	}
}

/**
 * When the task is due, how many days it has left, and whether it is done.
 * A finished or cancelled task says nothing about its days.
 *
 * @param {object} task CalDAV task object from API
 * @return {{deadline: string|null, daysText: string, isOverdue: boolean, isCompleted: boolean}} The timing fields.
 */
function taskTiming(task) {
	const due = task.due || null
	const isCompleted = task.status === 'completed'
	const isCancelled = task.status === 'cancelled'
	const isTerminal = isCompleted || isCancelled
	const diffDays = due && !isTerminal ? daysUntil(due) : null
	const isOverdue = diffDays !== null && diffDays < 0

	return {
		deadline: due,
		daysText: diffDays === null ? '—' : daysText(diffDays),
		isOverdue,
		isCompleted,
	}
}

/**
 * The line naming the object a task belongs to, '' without one.
 *
 * @param {string|undefined} objectUuid The object's uuid.
 * @return {string} The reference.
 */
function objectReference(objectUuid) {
	return objectUuid ? `Object: ${objectUuid}` : ''
}

/**
 * Whole days from today to a due date, negative when it has passed.
 *
 * @param {string} due The due date.
 * @return {number} The days.
 */
function daysUntil(due) {
	const dueDate = new Date(due)
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	dueDate.setHours(0, 0, 0, 0)
	return Math.floor((dueDate - today) / (1000 * 60 * 60 * 24))
}

/**
 * How many days a task has left, in words.
 *
 * @param {number} diffDays The days left, negative when overdue.
 * @return {string} The text.
 */
function daysText(diffDays) {
	if (diffDays < 0) {
		const absDays = Math.abs(diffDays)
		return absDays === 1
			? t('dossiq', '1 day overdue')
			: t('dossiq', '{days} days overdue', { days: absDays })
	}
	if (diffDays === 0) {
		return t('dossiq', 'Due today')
	}
	return t('dossiq', '{days} days', { days: diffDays })
}

/**
 * Fetch CalDAV tasks for a specific OpenRegister object.
 *
 * @param {string|number} registerId The register ID
 * @param {string|number} schemaId The schema (schema ID)
 * @param {string} objectId The object UUID
 * @return {Promise<object[]>} Array of normalized task work items
 * @spec openspec/specs/task-management/spec.md
 */
export async function fetchTasksForObject(registerId, schemaId, objectId) {
	const url = `/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/tasks`

	try {
		const response = await fetch(url, { headers: getHeaders() })
		if (!response.ok) {
			if (response.status === 404) return []
			console.warn(
				`Failed to fetch tasks for object ${objectId}: ${response.status}`,
			)
			return []
		}

		const data = await response.json()
		const tasks = data.results || data || []
		return tasks.map(normalizeCalDavTask)
	} catch (error) {
		console.warn('Error fetching tasks for object:', objectId, error)
		return []
	}
}

/**
 * Fetch all CalDAV tasks linked to the Dossiq register by fetching tasks
 * for each of the user's assigned cases.
 *
 * Strategy (DD-01 fallback): Fetch user's cases first, then batch-fetch
 * tasks per case. Limits to 20 most recent cases for performance.
 *
 * @param {object[]} cases Array of case objects (must have id property)
 * @return {Promise<object[]>} Array of normalized task work items
 * @spec openspec/specs/task-management/spec.md
 */
export async function fetchTasksForCases(cases) {
	const objectStore = useObjectStore()
	const caseConfig = objectStore.objectTypeRegistry.case
	if (!caseConfig) {
		console.warn('Case object type not registered')
		return []
	}

	const casesToFetch = cases.slice(0, 20)

	const taskPromises = casesToFetch.map((c) =>
		fetchTasksForObject(caseConfig.register, caseConfig.schema, c.id),
	)

	const results = await Promise.allSettled(taskPromises)

	const allTasks = []
	for (const result of results) {
		if (result.status === 'fulfilled' && Array.isArray(result.value)) {
			allTasks.push(...result.value)
		}
	}

	return allTasks
}
