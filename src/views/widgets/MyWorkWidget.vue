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
	<CnDataTable
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
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	isTerminal,
	signedDaysUntilDue,
	useEngineTaskStore,
} from '../../store/modules/engineTask.js'
import { taskRouteFor } from '../../utils/caseTaskPaneHelpers.js'

/** How many rows the tile shows when the manifest names no limit. */
const DEFAULT_LIMIT = 10

export default {
	name: 'MyWorkWidget',

	components: {
		CnDataTable,
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
			return [
				{
					key: 'title',
					label: t('dossiq', 'Task'),
					cellClass: 'cn-cell--strong',
				},
				{
					key: 'daysLeft',
					label: t('dossiq', 'Days left'),
					cellClass: 'cn-cell--muted cn-cell--end',
				},
			]
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
			return this.tasks.map((task) => ({
				...task,
				daysUntilDue: signedDaysUntilDue(task),
				daysLeft: this.daysLeftPhrase(signedDaysUntilDue(task)),
			}))
		},
	},

	mounted() {
		this.fetchData()
	},

	methods: {
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
