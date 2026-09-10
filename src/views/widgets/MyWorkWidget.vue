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
import { isTerminal, useEngineTaskStore } from '../../store/modules/engineTask.js'
import { shapeEngineTask } from './engineTaskRows.js'

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
		/** @return {object} The engine's task store. */
		engineTasks() {
			return useEngineTaskStore()
		},

		/** @return {object} The widget's manifest `content` block. */
		content() {
			return this.widget?.content ?? {}
		},

		/** @return {number} How many rows to ask the engine for. */
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
		 */
		emptyText() {
			const failure = this.failure || this.engineTasks.error
			if (failure) {
				return t('dossiq', 'Your tasks could not be loaded: {reason}', {
					reason: String(failure),
				})
			}
			return this.content.emptyText || t('dossiq', 'You have no open tasks')
		},

		/** @return {string} The footer link's label. */
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
		 * the column comes back when `subject` resolves. `shapeEngineTask`
		 * already reads it.
		 *
		 * @return {Array<object>} The column definitions.
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

		/** @return {Array<object>} The shaped rows, in the engine's order. */
		rows() {
			return this.tasks.map((task) => {
				const row = shapeEngineTask(task)
				return {
					...row,
					daysLeft: this.daysLeftPhrase(row.daysUntilDue),
				}
			})
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
		 */
		rowClass(row) {
			return typeof row?.daysUntilDue === 'number' && row.daysUntilDue < 0
				? 'cn-row--danger'
				: ''
		},

		/**
		 * Open a task. The lifecycle buttons live on its page.
		 *
		 * @param {object} row The clicked row.
		 * @return {void}
		 */
		openTask(row) {
			const id = String(row?.id ?? '')
			if (id === '') {
				return
			}
			this.$router.push({
				name: this.content.rowRoute || 'TaskDetail',
				params: { id },
			})
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
