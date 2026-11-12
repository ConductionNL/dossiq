/**
 * Dashboard helper utilities for KPI calculations, status aggregation,
 * overdue extraction, activity aggregation, and my work item merging.
 */

import { translate as t } from '@nextcloud/l10n'
import {
	formatDeadlineCountdown,
	getDaysRemaining,
	isCaseOverdue,
} from './caseHelpers.js'
import { isOverdue } from './deadlineCountdown.js'
import { prioritySortWeight } from './taskHelpers.js'
import { isTerminalStatus } from './taskLifecycle.js'

/**
 * Get today's date as ISO string (YYYY-MM-DD).
 *
 * @return {string}
 */
function todayString() {
	return new Date().toISOString().slice(0, 10)
}

/**
 * Compute dashboard KPI values from raw data arrays.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} completedCases Cases completed this month
 * @param {object[]} myTasks Tasks assigned to current user (available/active)
 * @return {object} KPI values
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function computeKpis(openCases, completedCases, myTasks) {
	const today = todayString()

	return {
		openCount: openCases.length,
		newToday: countOnDay(openCases.map((c) => c.startDate), today),
		overdueCount: openCases.filter((c) => isCaseOverdue(c, false)).length,
		completedCount: completedCases.length,
		avgDays: averageLeadDays(completedCases),
		taskCount: myTasks.length,
		tasksDueToday: countOnDay(myTasks.map((task) => task.dueDate), today),
	}
}

/**
 * How many of the dates fall on the given day.
 *
 * @param {Array<string|undefined>} dates The dates, missing ones included.
 * @param {string} day The day, `YYYY-MM-DD`.
 * @return {number} The count.
 */
function countOnDay(dates, day) {
	return dates.filter((date) => date && date.slice(0, 10) === day).length
}

/**
 * The average whole days from start to end of the completed cases, rounded;
 * null without any.
 *
 * @param {object[]} completedCases Cases completed this month.
 * @return {number|null} The average.
 */
function averageLeadDays(completedCases) {
	if (completedCases.length === 0) {
		return null
	}
	const totalDays = completedCases.reduce((sum, c) => sum + leadDays(c), 0)
	return Math.round(totalDays / completedCases.length)
}

/**
 * Whole days from a case's start to its end, never negative; 0 when either
 * date is missing.
 *
 * @param {object} c A completed case.
 * @return {number} The days.
 */
function leadDays(c) {
	if (!c.startDate || !c.endDate) {
		return 0
	}
	const start = new Date(c.startDate)
	const end = new Date(c.endDate)
	start.setHours(0, 0, 0, 0)
	end.setHours(0, 0, 0, 0)
	return Math.max(0, Math.floor((end - start) / (1000 * 60 * 60 * 24)))
}

/**
 * Aggregate open cases by status name for the status chart.
 * Same-named statuses across case types are merged into one bar; every
 * underlying statusType id that contributes to a bar is collected in
 * `statusIds` so a bar click can deep-link to `/cases?status[]=<id>&…` (an IN
 * match across all case types that share the status name).
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} statusTypes All status types
 * @return {Array<{ name: string, count: number, statusIds: string[] }>} Sorted by status type order
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function aggregateByStatus(openCases, statusTypes) {
	const orderMap = firstOrderByName(statusTypes)

	const statusIdToName = new Map()
	for (const st of statusTypes) {
		statusIdToName.set(st.id, st.name)
	}

	const { statusMap, idsMap } = tallyStatuses(openCases, statusIdToName)

	return Array.from(statusMap.entries())
		.map(([name, count]) => ({
			name,
			count,
			statusIds: Array.from(idsMap.get(name) || []),
		}))
		.sort(
			(a, b) => (orderMap.get(a.name) ?? 999) - (orderMap.get(b.name) ?? 999),
		)
}

/**
 * The order of each status name: the first status type carrying the name
 * sets it, 999 when it has none.
 *
 * @param {object[]} statusTypes All status types.
 * @return {Map<string, number>} The order per name.
 */
function firstOrderByName(statusTypes) {
	const orderMap = new Map()
	for (const st of statusTypes) {
		if (!orderMap.has(st.name)) {
			orderMap.set(st.name, st.order ?? 999)
		}
	}
	return orderMap
}

/**
 * Count open cases per status name, and collect the status ids behind
 * each resolved name.
 *
 * @param {object[]} openCases Cases with non-final status.
 * @param {Map<string, string>} statusIdToName Status type id to name.
 * @return {{statusMap: Map<string, number>, idsMap: Map<string, Set<string>>}} The tally.
 */
function tallyStatuses(openCases, statusIdToName) {
	const statusMap = new Map()
	const idsMap = new Map()
	for (const c of openCases) {
		// A case's `status` is a statusType id. Only ids that resolve to a known
		// statusType get their real name; anything else (null, or a stale/junk
		// value that no longer references a statusType) is bucketed under
		// "Unknown" rather than leaking the raw value into the chart — this
		// mirrors aggregateByType's resolution. Only resolved ids feed statusIds,
		// so an "Unknown" bar click never deep-links on a junk/absent id.
		const resolvedName = statusIdToName.get(c.status)
		const name = resolvedName || t('dossiq', 'Unknown')
		statusMap.set(name, (statusMap.get(name) || 0) + 1)
		if (resolvedName) {
			idsFor(idsMap, name).add(c.status)
		}
	}
	return { statusMap, idsMap }
}

/**
 * The set of status ids behind a status name, started when it is new.
 *
 * @param {Map<string, Set<string>>} idsMap The ids per name.
 * @param {string} name The status name.
 * @return {Set<string>} The ids.
 */
function idsFor(idsMap, name) {
	if (!idsMap.has(name)) idsMap.set(name, new Set())
	return idsMap.get(name)
}

/**
 * Aggregate recent activity entries from case objects.
 *
 * @param {object[]} cases All visible cases (with activity arrays)
 * @param {number} limit Max entries to return
 * @return {Array<{ date, type, description, user, caseIdentifier }>}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getRecentActivity(cases, limit = 10) {
	const entries = []

	for (const c of cases) {
		for (const entry of caseActivityEntries(c)) {
			entries.push(entry)
		}
	}

	entries.sort((a, b) => new Date(b.date) - new Date(a.date))
	return entries.slice(0, limit)
}

/**
 * One case's activity as feed entries, none when it carries no activity list.
 *
 * @param {object} c A case.
 * @return {Array<object>} The entries, in the case's own order.
 */
function caseActivityEntries(c) {
	if (!Array.isArray(c.activity)) {
		return []
	}
	return c.activity.map((entry) => activityEntry(entry, c))
}

/**
 * One activity entry as a feed row.
 *
 * @param {object} entry The activity entry.
 * @param {object} c The case it belongs to.
 * @return {object} `{ date, type, description, user, caseIdentifier }`.
 */
function activityEntry(entry, c) {
	return {
		date: entry.date,
		type: entry.type,
		description: entry.description,
		user: entry.user || '—',
		caseIdentifier: c.identifier || '—',
	}
}

/**
 * Merge cases and tasks into unified My Work items, sorted by priority then deadline.
 *
 * @param {object[]} cases Cases assigned to current user (non-final)
 * @param {object[]} tasks Tasks assigned to current user (available/active)
 * @param {number} limit Max items to return
 * @return {Array<{ type, id, title, reference, deadline, daysText, isOverdue, priority }>}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getMyWorkItems(cases, tasks, limit = 5) {
	const items = [
		...cases.map(caseWorkItem),
		...tasks.map(taskWorkItem),
	]

	items.sort(byPriorityThenDeadline)

	return items.slice(0, limit)
}

/**
 * The fields every case work item carries.
 *
 * @param {object} c A case.
 * @return {object} The work item.
 */
function caseWorkItem(c) {
	const countdown = formatDeadlineCountdown(c, false)
	return {
		type: 'case',
		id: c.id,
		title: c.title || '—',
		reference: c.identifier ? `#${c.identifier}` : '',
		deadline: c.deadline || null,
		daysText: countdown.text,
		isOverdue: isCaseOverdue(c, false),
		priority: c.priority || 'normal',
	}
}

/**
 * A task as a work item.
 *
 * @param {object} task A task.
 * @return {object} The work item.
 */
function taskWorkItem(task) {
	const overdue = taskIsOverdue(task)
	const daysLeft = taskDaysLeft(task)

	return {
		type: 'task',
		id: task.id,
		title: task.title || '—',
		reference: taskReference(task),
		deadline: task.dueDate || null,
		daysText: daysLeftText(daysLeft),
		isOverdue: overdue,
		priority: task.priority || 'normal',
	}
}

/**
 * How many days a task has left, null for a task without a due date.
 *
 * @param {object} task A task.
 * @return {number|null} The days left, negative when overdue.
 */
function taskDaysLeft(task) {
	return task.dueDate ? getDaysRemaining(task.dueDate) : null
}

/**
 * The case a task is on, as the work item's reference.
 *
 * @param {object} task A task.
 * @return {string} `Case: <case>`, or '' when the task names no case.
 */
function taskReference(task) {
	return task.case ? `Case: ${task.case}` : ''
}

/**
 * Whether an unfinished task is past its due date. Answers the due date's
 * falsy value itself for a task without one, as the list always did.
 *
 * @param {object} task A task.
 * @return {boolean|string|null|undefined} Truthy when overdue.
 */
function taskIsOverdue(task) {
	return !isTerminalStatus(task.status) && task.dueDate && isOverdue(task.dueDate)
}

/**
 * How many days a task has left, in words.
 *
 * @param {number|null} daysLeft The days left, negative when overdue, null without a due date.
 * @return {string} The text.
 */
function daysLeftText(daysLeft) {
	if (daysLeft === null) {
		return '—'
	}
	if (daysLeft < 0) {
		return overdueText(Math.abs(daysLeft))
	}
	if (daysLeft === 0) {
		return t('dossiq', 'Due today')
	}
	return t('dossiq', '{days} days', { days: daysLeft })
}

/**
 * How many days a task is overdue, in words.
 *
 * @param {number} days The days overdue, at least one.
 * @return {string} The text.
 */
function overdueText(days) {
	return days === 1
		? t('dossiq', '1 day overdue')
		: t('dossiq', '{days} days overdue', {
				days,
			})
}

/**
 * Sort work items by priority first, then by deadline, undated last.
 *
 * @param {object} a A work item.
 * @param {object} b Another work item.
 * @return {number} The sort order.
 */
function byPriorityThenDeadline(a, b) {
	const pDiff = prioritySortWeight(a.priority) - prioritySortWeight(b.priority)
	if (pDiff !== 0) return pDiff

	return byDeadline(a, b)
}

/**
 * Sort work items by deadline, earliest first, undated last.
 *
 * @param {object} a A work item.
 * @param {object} b Another work item.
 * @return {number} The sort order.
 */
function byDeadline(a, b) {
	if (a.deadline && b.deadline)
		return new Date(a.deadline) - new Date(b.deadline)
	if (a.deadline) return -1
	if (b.deadline) return 1
	return 0
}

/**
 * Get the end-of-week date (Sunday 23:59:59) for grouping purposes.
 *
 * @return {Date}
 */
function endOfWeek() {
	const now = new Date()
	const day = now.getDay() // 0 = Sunday
	const daysUntilSunday = day === 0 ? 0 : 7 - day
	const sunday = new Date(now)
	sunday.setDate(now.getDate() + daysUntilSunday)
	sunday.setHours(23, 59, 59, 999)
	return sunday
}

/**
 * Group cases and normalized CalDAV tasks into urgency-based sections.
 *
 * Accepts OpenRegister case objects and already-normalized CalDAV task items
 * (from normalizeCalDavTask). Returns grouped sections: overdue, dueThisWeek,
 * upcoming, noDeadline.
 *
 * @param {object[]} cases Cases assigned to current user (non-final)
 * @param {object[]} normalizedTasks Already-normalized CalDAV task work items
 * @return {{ overdue: object[], dueThisWeek: object[], upcoming: object[], noDeadline: object[], totalCount: number }}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getGroupedMyWorkItems(cases, normalizedTasks) {
	const weekEnd = endOfWeek()

	// Case work items, then the already-normalized task items.
	const items = [
		...cases.map(groupedCaseWorkItem),
		...normalizedTasks,
	]

	// Classify into groups, then sort within each group: priority first, then deadline.
	const groups = { overdue: [], dueThisWeek: [], upcoming: [], noDeadline: [] }
	for (const item of items) {
		groups[urgencyGroup(item, weekEnd)].push(item)
	}
	for (const group of Object.values(groups)) {
		group.sort(byPriorityThenDeadline)
	}

	return { ...groups, totalCount: items.length }
}

/**
 * A case as a grouped work item: the plain case item with its completion,
 * status and case type.
 *
 * @param {object} c A case.
 * @return {object} The work item.
 */
function groupedCaseWorkItem(c) {
	const { priority, ...item } = caseWorkItem(c)
	return {
		...item,
		isCompleted: false,
		priority,
		status: c.status || null,
		caseType: c.caseType || null,
	}
}

/**
 * Which urgency group a work item belongs in.
 *
 * @param {object} item A work item.
 * @param {Date} weekEnd The end of this week.
 * @return {string} `overdue`, `dueThisWeek`, `upcoming` or `noDeadline`.
 */
function urgencyGroup(item, weekEnd) {
	if (!item.deadline) {
		return 'noDeadline'
	}
	const deadline = new Date(item.deadline)
	deadline.setHours(0, 0, 0, 0)

	if (item.isOverdue) {
		return 'overdue'
	}
	return deadline <= weekEnd ? 'dueThisWeek' : 'upcoming'
}

/**
 * Format a relative timestamp (e.g., "10 min ago", "2 hours ago", "yesterday").
 *
 * @param {string} dateString ISO date string
 * @return {string}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function formatRelativeTime(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	const now = new Date()
	const diffMs = now - date
	return withinADayWords(diffMs) ?? daysAgoWords(date, diffMs)
}

/**
 * A moment less than a day ago, in minutes or hours.
 *
 * @param {number} diffMs How long ago, in milliseconds.
 * @return {string|null} The words, or null when it is a day or more (or unreadable).
 */
function withinADayWords(diffMs) {
	const diffMin = Math.floor(diffMs / 60000)
	const diffHours = Math.floor(diffMs / 3600000)

	if (diffMin < 1) return t('dossiq', 'just now')
	if (diffMin < 60) return t('dossiq', '{min} min ago', { min: diffMin })
	if (diffHours < 24) return t('dossiq', '{hours} hours ago', { hours: diffHours })
	return null
}

/**
 * A moment a day or more ago: yesterday, some days ago, or its short date.
 *
 * @param {Date} date The moment.
 * @param {number} diffMs How long ago, in milliseconds.
 * @return {string} The words.
 */
function daysAgoWords(date, diffMs) {
	const diffDays = Math.floor(diffMs / 86400000)

	if (diffDays === 1) return t('dossiq', 'yesterday')
	if (diffDays < 7) return t('dossiq', '{days} days ago', { days: diffDays })
	return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * Aggregate open cases by case type for the "Cases by Type" bar chart.
 * Grouped by the caseType id (the value a case object stores) so a bar click
 * can deep-link to `/cases?caseType=<id>` and have the index pre-filter match.
 * The human-readable title is carried alongside as `label` for display. Cases
 * whose caseType cannot be resolved are grouped under an empty id / "Unknown".
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for label resolution)
 * @return {Array<{ type: string, label: string, count: number }>} `type` = caseType id
 *   (the case's stored value), `label` = title. Sorted by count descending.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export function aggregateByType(openCases, caseTypes) {
	const labelMap = caseTypeLabels(caseTypes)
	const counts = countsByCaseType(openCases)

	return Array.from(counts.entries())
		.map(([type, count]) => ({
			type,
			label: labelMap.get(type) || t('dossiq', 'Unknown'),
			count,
		}))
		.sort((a, b) => b.count - a.count)
}

/**
 * Each case type's label by id: its title, else its name, else Unknown.
 *
 * @param {object[]} caseTypes All case types.
 * @return {Map<string, string>} The labels.
 */
function caseTypeLabels(caseTypes) {
	const labelMap = new Map()
	for (const ct of caseTypes) {
		labelMap.set(ct.id, ct.title || ct.name || t('dossiq', 'Unknown'))
	}
	return labelMap
}

/**
 * How many cases carry each case type id, '' for a case without one.
 *
 * @param {object[]} openCases The cases.
 * @return {Map<string, number>} The counts, in first-seen order.
 */
function countsByCaseType(openCases) {
	const counts = new Map()
	for (const c of openCases) {
		const id = c.caseType || ''
		counts.set(id, (counts.get(id) || 0) + 1)
	}
	return counts
}
