<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The Dashboard's My work tile: your open tasks, soonest due first.

	Reads OpenRegister's task engine through `useEngineTaskStore`, the same
	seam every other task surface in dossiq reads. The tile it replaces was a
	`type: "object-table"` widget over `register: dossiq, schema: caseTask`,
	a store nothing writes any more. That read answered 200 and rendered a
	table, so the tile looked healthy while showing rows no writer had
	touched since the engine took over.

	ONE READER, AND THIS IS NOT IT. The store's `list()` puts every row
	through `asTaskRow`, which adds the register's names for the engine's
	own (`uuid` to `id`, `state` to `status`, `dueAt` to `dueDate`,
	`objectUuid` to `case`). This tile reads those names and shapes nothing
	itself: a per-widget row shaper is a second vocabulary, and the day the
	two disagree neither one fails, they just render different cells.

	@spec openspec/specs/dashboard/spec.md
	@spec openspec/specs/signalering-widgets/spec.md
-->
<template>
	<div v-if="isList" class="dossiq-task-list" data-testid="my-work-task-list">
		<p v-if="loading && !rows.length" class="dossiq-task-list__empty">
			{{ t('dossiq', 'Loading tasks') }}
		</p>
		<p v-else-if="!rows.length" class="dossiq-task-list__empty">
			{{ emptyText }}
		</p>
		<ul v-else class="dossiq-task-list__rows">
			<li
				v-for="row in rows"
				:key="rowKey(row)"
				class="dossiq-task-list__row"
				data-testid="my-work-task-row">
				<input
					type="checkbox"
					class="dossiq-task-list__check"
					:checked="completing.includes(rowKey(row))"
					:disabled="completing.includes(rowKey(row))"
					:aria-label="
						t('dossiq', 'Mark {title} as done', { title: row.title })
					"
					data-testid="my-work-task-check"
					@change="complete(row, $event)" />
				<span class="dossiq-task-list__body">
					<a
						class="dossiq-task-list__title"
						:href="taskHref(row)"
						@click.prevent="openTask(row)"
						>{{ row.title }}</a
					>
					<span class="dossiq-task-list__meta">
						<span class="dossiq-task-list__case">{{
							caseTitleOf(row)
						}}</span>
						<span
							class="dossiq-task-list__due"
							:class="{
								'dossiq-task-list__due--urgent': isUrgent(row),
							}"
							>{{ dueLabel(row) }}</span
						>
					</span>
					<span
						v-if="row.substitutedMarker"
						class="dossiq-task-list__case">
						{{ row.substitutedMarker }}
					</span>
				</span>
			</li>
		</ul>
		<NcButton
			v-if="substitutedTasks.length"
			variant="tertiary"
			data-testid="substituted-toggle-widget"
			:aria-pressed="String(showSubstituted)"
			@click="toggleSubstituted">
			{{ substitutedToggleLabel }}
		</NcButton>
	</div>
	<CnDataTable
		v-else
		:rows="rows"
		:columns="columns"
		:loading="loading"
		:rowClass="rowClass"
		:emptyText="emptyText"
		borderless
		@rowClick="openTask">
		<template #footer>
			<router-link class="cn-data-table__view-all" :to="viewAllRoute">
				{{ viewAllLabel }}
			</router-link>
			<NcButton
				v-if="substitutedTasks.length"
				variant="tertiary"
				data-testid="substituted-toggle-widget"
				:aria-pressed="String(showSubstituted)"
				@click="toggleSubstituted">
				{{ substitutedToggleLabel }}
			</NcButton>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { fetchSubstitutedWork } from '../../services/substitutionApi.js'
import {
	isTerminal,
	signedDaysUntilDue,
	useEngineTaskStore,
} from '../../store/modules/engineTask.js'
import { taskIdOf, taskRouteFor } from '../../utils/caseTaskPaneHelpers.js'
import {
	applySubstitutedFilter,
	asSubstitutedItems,
	buildSubstitutedMap,
	mergeSubstitutedCases,
	readShowSubstituted,
	substitutedFor,
	substitutedUntil,
	writeShowSubstituted,
} from '../../utils/substitutionHelpers.js'

/** How many rows the tile shows when the manifest names no limit. */
const DEFAULT_LIMIT = 10

export default {
	name: 'MyWorkWidget',

	components: {
		CnDataTable,
		NcButton,
	},

	props: {
		/**
		 * The manifest widget entry, injected by CnDashboardPage into the
		 * `widget-my-work` slot. Declared rather than left to fall through:
		 * an undeclared object prop lands on the root element as a
		 * stringified attribute in Vue 2.7, which is how `[object Object]`
		 * ends up in the DOM.
		 */
		widget: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The grid placement of this widget, the second half of the slot
		 * scope. Declared for the same reason as `widget`; the tile reads
		 * nothing from it.
		 */
		// eslint-disable-next-line vue/no-unused-properties
		item: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			loading: false,
			tasks: [],
			/** The read's own failure, when the read threw rather than returned. */
			failure: '',
			/** Open tasks routed here by an active substitution. */
			substitutedTasks: [],
			/** `task:<id>` -> the routing context, for the marker and the filter. */
			substitutedMap: {},
			/** Whether substituted rows are listed; remembered per browser. */
			showSubstituted: readShowSubstituted(),
			/** Keys of the rows whose completion is on its way to the engine. */
			completing: [],
		}
	},

	computed: {
		/**
		 * @return {object} The engine's task store.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		engineTasks() {
			return useEngineTaskStore()
		},

		/**
		 * @return {object} The widget's manifest `content` block.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		content() {
			return this.widget?.content ?? {}
		},

		/**
		 * Whether the tile draws the checkbox list instead of the table.
		 *
		 * OPT-IN, read from the manifest's `content.variant`. Only the simple
		 * profile's dashboard declares `"list"` (src/menu-layout.simple.json,
		 * `simple-my-tasks`), after the DqDashboard board: a checkbox, the
		 * task, its case on a second muted line and the due date on the right.
		 * Every other placement declares nothing and keeps the table, so the
		 * full profile and the My work page render exactly as before.
		 *
		 * @return {boolean} True for the list variant.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		isList() {
			return this.content.variant === 'list'
		},

		/**
		 * @return {number} How many rows to ask the engine for.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		limit() {
			const declared = Number(this.content.limit)
			return Number.isFinite(declared) && declared > 0
				? declared
				: DEFAULT_LIMIT
		},

		/**
		 * What the tile says instead of rows.
		 *
		 * A failed read is NOT "you have no open tasks". The store answers an
		 * empty list rather than throwing and records why on `error`, so
		 * without this the one thing a reader would see is a tile saying
		 * their queue is clear. That is the failure shape this whole
		 * migration keeps producing, and it is worse than an error.
		 *
		 * @return {string} The empty-state text.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		emptyText() {
			const failure = this.failure || this.engineTasks.error
			if (failure) {
				return t('dossiq', 'Could not load your tasks: {reason}', {
					reason: String(failure),
				})
			}
			return this.content.emptyText || t('dossiq', 'You have no open tasks')
		},

		/**
		 * @return {string} The footer link's label.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		viewAllLabel() {
			return this.content.viewAllLabel || t('dossiq', 'View all')
		},

		/**
		 * Where the footer link goes.
		 *
		 * The manifest still carries the route, so the day a generic widget
		 * can read the engine this tile hands its destination straight back.
		 *
		 * @return {object} A vue-router location.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		viewAllRoute() {
			const declared = this.content.viewAllRoute
			if (declared && typeof declared === 'object' && declared.name) {
				return declared
			}
			return { name: 'Tasks' }
		},

		/**
		 * The two columns the engine can fill today.
		 *
		 * The tile used to carry a third, the case the task sits on, keyed
		 * `case.title` and filled by extending the `caseTask` object's `$ref`.
		 * The engine answers the same fact in its `subject` block, and that
		 * block is null on every row the live instance returns: fifty of
		 * fifty on 2026-09-10, while 37 of those rows name an `objectUuid`.
		 * The cause is in OpenRegister, not here, and it is reported rather
		 * than worked around. A column blank on every row is the same
		 * looks-fine-shows-nothing failure this widget is being fixed for, so
		 * the column comes back when `subject` resolves.
		 *
		 * @return {Array<object>} The column definitions.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		columns() {
			const columns = [
				{
					key: 'title',
					label: t('dossiq', 'Task'),
					cellClass: 'cn-cell--strong',
				},
			]

			// Only when there is substituted work to mark. A column that is
			// blank on every row for everyone who has no waarneming is the
			// same empty-cell noise the Case column was removed for.
			if (this.substitutedTasks.length > 0) {
				columns.push({
					key: 'substitutedMarker',
					label: t('dossiq', 'Standing in for'),
					cellClass: 'cn-cell--muted',
				})
			}

			columns.push({
				key: 'daysLeft',
				label: t('dossiq', 'Days left'),
				cellClass: 'cn-cell--muted cn-cell--end',
			})

			return columns
		},

		/**
		 * The toggle's label, which says what clicking it does.
		 *
		 * @return {string} The button text.
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		substitutedToggleLabel() {
			if (this.showSubstituted === true) {
				return t('dossiq', 'Hide substituted work')
			}
			return t('dossiq', 'Show substituted work')
		},

		/**
		 * The rows the table renders, in the engine's order.
		 *
		 * The row is passed through, not rebuilt. The store already put it
		 * through `asTaskRow`, so the two visible columns read what is
		 * already there (`title`) or a projection of it, and the register's
		 * `id` is on the row for `openTask` to route by.
		 *
		 * Only the two cells no row carries are added: `daysLeft`, which is
		 * a phrase rather than a number, and `daysUntilDue`, overwritten
		 * with the SIGNED value so `rowClass()` can colour an overdue row.
		 * The engine's own `daysUntilDue` is null exactly when the task is
		 * overdue, so reading it unfolded would colour nothing.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		rows() {
			// The reader's own rows are passed through untouched: only the
			// substituted ones carry the `type` half of the map key, and an own
			// row that carries none simply misses the map, which is what it is.
			const merged = mergeSubstitutedCases(this.tasks, this.substitutedTasks)
			const visible = applySubstitutedFilter(
				merged,
				this.substitutedMap,
				this.showSubstituted,
			)

			return visible.map((task) => ({
				...task,
				daysUntilDue: this.daysUntilDueOf(task),
				daysLeft: this.daysLeftPhrase(this.daysUntilDueOf(task)),
				substitutedMarker: this.markerFor(task),
			}))
		},
	},

	/**
	 * Both reads, side by side: the reader's own open tasks and whatever an
	 * active substitution routes here. Neither waits on the other.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	mounted() {
		this.fetchData()
		this.loadSubstitutedWork()
	},

	methods: {
		t,

		/**
		 * A stable key for a row, the same id the verbs and routes take.
		 *
		 * @param {object} row A shaped row.
		 * @return {string} The key.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		rowKey(row) {
			return taskIdOf(row)
		},

		/**
		 * The case a task sits on, for the list's second line.
		 *
		 * The engine answers it in the row's `subject` block. When that block
		 * is empty the line stays empty rather than showing a uuid.
		 *
		 * @param {object} row A shaped row.
		 * @return {string} The case title, or ''.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		caseTitleOf(row) {
			return String(row?.subject?.title ?? '').trim()
		},

		/**
		 * Whether the due date reads in the error colour: due today or late.
		 *
		 * @param {object} row A shaped row.
		 * @return {boolean} True when the deadline is today or past.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		isUrgent(row) {
			return typeof row?.daysUntilDue === 'number' && row.daysUntilDue <= 0
		},

		/**
		 * The list's due date, short: Today, Tomorrow, a day and a month, or
		 * how late the task is.
		 *
		 * @param {object} row A shaped row.
		 * @return {string} The label.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		dueLabel(row) {
			const days = row?.daysUntilDue
			if (typeof days !== 'number') {
				return t('dossiq', 'No deadline')
			}
			if (days < 0) {
				return t('dossiq', '{n} days overdue', { n: Math.abs(days) })
			}
			if (days === 0) {
				return t('dossiq', 'Today')
			}
			if (days === 1) {
				return t('dossiq', 'Tomorrow')
			}
			const due = new Date(row?.dueDate ?? '')
			if (isNaN(due.getTime())) {
				return t('dossiq', '{n} days remaining', { n: days })
			}
			return new Intl.DateTimeFormat(getCanonicalLocale(), {
				day: 'numeric',
				month: 'short',
			}).format(due)
		},

		/**
		 * The task page's address, so the title is a real link.
		 *
		 * @param {object} row A shaped row.
		 * @return {string} The href, or '#' when the row cannot be routed.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		taskHref(row) {
			const route = taskRouteFor(row, {
				rowRoute: this.content.rowRoute || 'TaskDetail',
			})
			if (route === null || typeof this.$router?.resolve !== 'function') {
				return '#'
			}
			return this.$router.resolve(route).href
		},

		/**
		 * Complete a task from its checkbox.
		 *
		 * THE SAME PATH THE TASK PAGE TAKES: `useEngineTaskStore.invoke(uuid,
		 * 'complete')`, which posts to dossiq's own complete endpoint, so the
		 * case type's required answers and effects are checked exactly as on
		 * the task page. A refusal shows the engine's own message and the box
		 * unticks again; a completion drops the row and reads the list anew.
		 *
		 * @param {object} row The ticked row.
		 * @param {Event} event The change event, to undo a refused tick.
		 * @return {Promise<void>}
		 * @spec openspec/specs/dashboard/spec.md
		 * @spec openspec/specs/task-management/spec.md
		 */
		async complete(row, event) {
			const id = this.rowKey(row)
			if (id === '' || this.completing.includes(id)) {
				return
			}

			this.completing = [...this.completing, id]
			try {
				const updated = await this.engineTasks.invoke(id, 'complete')
				if (updated === null) {
					if (event?.target) {
						event.target.checked = false
					}
					showError(
						this.engineTasks.error
							|| t('dossiq', 'Could not complete {title}', {
								title: row.title,
							}),
					)
					return
				}

				showSuccess(
					t('dossiq', 'Task {title} finished', { title: row.title }),
				)
				this.tasks = this.tasks.filter((task) => taskIdOf(task) !== id)
				this.substitutedTasks = this.substitutedTasks.filter(
					(task) => taskIdOf(task) !== id,
				)
				await this.fetchData()
			} finally {
				this.completing = this.completing.filter((key) => key !== id)
			}
		},

		/**
		 * How far a row is from its deadline, in words.
		 *
		 * The three branches the retired `conditionalPhrase` formatter
		 * carried, plus the one it had no answer for: a task with no deadline
		 * at all, which read as a bare empty cell.
		 *
		 * @param {number|null} days Days left, negative when overdue.
		 * @return {string} The phrase for the Days left cell.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		daysLeftPhrase(days) {
			if (typeof days !== 'number') {
				return t('dossiq', 'No deadline')
			}
			if (days < 0) {
				return t('dossiq', '{n} days overdue', { n: Math.abs(days) })
			}
			if (days === 0) {
				return t('dossiq', 'Due today')
			}
			return t('dossiq', '{n} days remaining', { n: days })
		},

		/**
		 * How far a row is from its deadline, in signed days.
		 *
		 * `signedDaysUntilDue` reads the two counters the engine's own inbox
		 * API computes. A substituted row does not come from there: it comes
		 * from `/api/substitutions/work`, which answers the register's
		 * vocabulary (`dueDate`) and no counters at all. Without the fallback
		 * every substituted task reads "No deadline" beside a deadline it has,
		 * which is the same looks-fine-shows-nothing cell this tile was
		 * rewritten to remove.
		 *
		 * @param {object} task A merged task row.
		 * @return {number|null} Days left, negative when overdue, null when the
		 *   row carries no deadline at all.
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		daysUntilDueOf(task) {
			const counted = signedDaysUntilDue(task)
			if (counted !== null) {
				return counted
			}

			const due = new Date(task?.dueDate ?? '')
			if (isNaN(due.getTime())) {
				return null
			}

			const startOfToday = new Date()
			startOfToday.setHours(0, 0, 0, 0)
			return Math.ceil((due.getTime() - startOfToday.getTime()) / 86400000)
		},

		/**
		 * The marker naming whose task this is, and until when.
		 *
		 * Empty on the reader's own rows, which is how the column stays
		 * readable: a marker on every row would mark nothing.
		 *
		 * @param {object} row A merged task row.
		 * @return {string} The marker text, or ''.
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		markerFor(row) {
			const absentee = substitutedFor(this.substitutedMap, row)
			if (absentee === '') {
				return ''
			}
			const until = substitutedUntil(this.substitutedMap, row)
			if (until === '') {
				return t('dossiq', 'for {name}', { name: absentee })
			}
			return t('dossiq', 'for {name}, until {date}', {
				name: absentee,
				date: until,
			})
		},

		/**
		 * Show or hide the substituted rows, and remember which.
		 *
		 * @return {void}
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		toggleSubstituted() {
			this.showSubstituted = !this.showSubstituted
			writeShowSubstituted(this.showSubstituted)
		},

		/**
		 * Ask the resolver what an active substitution routes to this reader.
		 *
		 * Scope and the reader's own OpenRegister permissions were applied
		 * server-side, so every row returned may be shown. A failure leaves the
		 * tile with the reader's own tasks rather than emptying it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		async loadSubstitutedWork() {
			try {
				const work = await fetchSubstitutedWork()
				this.substitutedMap = buildSubstitutedMap(work.cases, work.tasks)
				this.substitutedTasks = asSubstitutedItems(work.tasks, 'task')
			} catch {
				this.substitutedTasks = []
				this.substitutedMap = {}
			}
		},

		/**
		 * A row past its deadline reads in the error colour.
		 *
		 * @param {object} row A shaped row.
		 * @return {string} The row's class, or ''.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		rowClass(row) {
			return typeof row?.daysUntilDue === 'number' && row.daysUntilDue < 0
				? 'cn-row--danger'
				: ''
		},

		/**
		 * Open a task. The lifecycle buttons live on its page.
		 *
		 * Routed by `taskRouteFor`, the same builder the case task pane
		 * uses, so the two cannot disagree about which key `/tasks/:id`
		 * takes. Belt and braces on purpose: `asTaskRow` has already put
		 * the uuid on `id`, and this reads `uuid` first anyway, so a
		 * regression in that mapping cannot turn every row on this tile
		 * into a dead link to the engine's numeric primary key.
		 *
		 * @param {object} row The clicked row.
		 * @return {void}
		 * @spec openspec/specs/dashboard/spec.md
		 */
		openTask(row) {
			const route = taskRouteFor(row, {
				rowRoute: this.content.rowRoute || 'TaskDetail',
			})
			if (route === null) {
				return
			}
			this.$router.push(route)
		},

		/**
		 * Ask the engine for your open tasks, soonest due first.
		 *
		 * `scope: 'assigned'` is what makes this YOUR work: the engine
		 * compares against the session, so the tile needs no `@me` token and
		 * cannot accidentally list a colleague's queue. `isTerminal: false`
		 * is the same filter server-side that `isTerminalStatus: false` was
		 * client-side, and it is applied again on the rows because a store
		 * that fails answers an empty list rather than throwing.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/dashboard/spec.md
		 */
		async fetchData() {
			this.loading = true
			this.failure = ''
			try {
				const results = await this.engineTasks.list({
					scope: 'assigned',
					isTerminal: false,
					sort: 'dueAt',
					limit: this.limit,
				})
				this.tasks = (results || []).filter((task) => !isTerminal(task))
			} catch (error) {
				// Shown, not logged. A console line is invisible to the person
				// looking at an empty tile, and `eslint-suppressions.json`
				// already carries fourteen files' worth of that habit.
				this.failure = error?.message || String(error)
				this.tasks = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
/*
 * The list variant, after the DqDashboard board's My tasks card. Theme
 * variables only, so every token set paints it in its own colours. The side
 * padding is the dashboard card header's 16px, so the rows line up under the
 * card title.
 */
.dossiq-task-list {
	display: flex;
	flex-direction: column;
	padding: 0 16px 10px;
}

.dossiq-task-list__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.dossiq-task-list__row {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 0;
}

/* The card header already draws a line under the title, so the first row
 * carries none of its own and the rows are separated from each other. */
.dossiq-task-list__row + .dossiq-task-list__row {
	border-top: 1px solid var(--color-border);
}

.dossiq-task-list__check {
	flex: none;
	width: 20px;
	height: 20px;
	margin: 1px 0 0;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}

.dossiq-task-list__body {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.dossiq-task-list__title {
	color: var(--color-main-text);
	font-size: 15px;
	line-height: 1.35;
	text-decoration: none;
}

.dossiq-task-list__title:hover,
.dossiq-task-list__title:focus-visible {
	text-decoration: underline;
}

.dossiq-task-list__meta {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-size: 13px;
}

.dossiq-task-list__case {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.dossiq-task-list__due {
	flex: none;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.dossiq-task-list__due--urgent {
	color: var(--color-text-error, var(--color-error-text));
	font-weight: 700;
}

.dossiq-task-list__empty {
	margin: 12px 0;
	color: var(--color-text-maxcontrast);
}
</style>
