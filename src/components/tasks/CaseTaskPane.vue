<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The task pane on the case page (task-on-the-case, A06).

  Widget `case-tasks` used to be a five-column `object-list` that routed every
  row to TaskDetail. The lifecycle buttons live there, so finishing the one
  task a handler came to the case for meant leaving the case, pressing the
  button, reading no confirmation, and navigating back twice to reach the next
  one. This pane puts the first open task, its buttons and its confirmation on
  the case page.

  WHY THIS IS A DOSSIQ COMPONENT AND NOT CONFIGURATION
  ---------------------------------------------------
  [blocked: nextcloud-vue 2.41.1 CnObjectListWidget has no rowActions and no
  lifecycle column]. Its `content` accepts register / schema / filter / sort /
  limit / columns / rowRoute / prompt / emptyText / viewAllRoute /
  viewAllQuery and nothing else, and a config key the component does not
  declare is dropped in silence, so there is no way to put a button inside a
  row from the manifest. Verified against the installed 2.40.0 dist AND the
  published 2.41.1 tarball before this component was written. When the library
  grows a row-action or lifecycle column the widget goes back to
  `type: "object-list"` and this file is deleted; the e2e spec asserts on the
  widget's tab and the button labels, not on this component, so it survives
  the swap.

  WHY THE WIDGET CARRIES A REGISTRY *TYPE* AND NOT `type: "custom"`
  ----------------------------------------------------------------
  The change design called for `type: "custom"` resolved through the page slot
  `widget-case-tasks`, the way TaskDetail resolves `widget-task-waiting-case`.
  That works only for a widget in the page's `layout`: CnDetailPage renders a
  `widget-<id>` slot per GRID item. `case-tasks` is a child of the `case-panels`
  tabs widget and is deliberately absent from `layout` (a layout entry would
  render it twice), and CnTabsWidget renders its children through
  CnDetailWidgetHost, which resolves a renderer from `cnRegistry[widget.type]`.
  A `type: "custom"` tab child resolves to nothing and renders nothing, with
  nothing in the console — the exact failure CnDetailWidgetHost's own closing
  comment describes. So the widget's TYPE is the registry key, which is the
  injection CnAppRoot provides and the one the library documents for an app's
  own widgets. No page is added or retyped either way, so the ADR-100 page
  ratchet is untouched.

  @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
-->
<template>
	<div class="case-task-pane" data-testid="case-task-pane">
		<div
			v-if="currentTask"
			class="case-task-pane__current"
			data-testid="case-task-pane-current">
			<h4 class="case-task-pane__title" data-testid="case-task-pane-title">
				{{ currentTitle }}
			</h4>
			<dl class="case-task-pane__meta">
				<dt>{{ t('dossiq', 'Assignee') }}</dt>
				<dd data-testid="case-task-pane-assignee">
					{{ currentAssignee }}
				</dd>
				<dt>{{ t('dossiq', 'Due') }}</dt>
				<dd data-testid="case-task-pane-due">
					{{ currentDue }}
				</dd>
			</dl>
			<CnLifecycleActions
				ref="lifecycle"
				class="case-task-pane__actions"
				:objectId="currentTaskId"
				:object="currentTask"
				:config="lifecycleConfig"
				@transitioned="onTransitioned" />
		</div>
		<p v-else class="case-task-pane__empty" data-testid="case-task-pane-empty">
			{{ t('dossiq', 'No open tasks on this case') }}
		</p>

		<div
			v-if="remainingTasks.length > 0"
			class="case-task-pane__remaining"
			data-testid="case-task-pane-remaining">
			<h5 class="case-task-pane__remaining-title">
				{{ t('dossiq', 'Other open tasks') }}
			</h5>
			<ul class="case-task-pane__list">
				<li
					v-for="task in remainingTasks"
					:key="taskIdOf(task)"
					class="case-task-pane__list-item">
					<router-link
						v-if="taskRouteFor(task, content)"
						:to="taskRouteFor(task, content)"
						class="case-task-pane__link">
						{{ titleOf(task) }}
					</router-link>
					<span v-else>{{ titleOf(task) }}</span>
				</li>
			</ul>
		</div>

		<div v-if="viewAllRoute" class="case-task-pane__footer">
			<router-link
				:to="viewAllRoute"
				class="case-task-pane__link"
				data-testid="case-task-pane-view-all">
				{{ t('dossiq', 'View all') }}
			</router-link>
		</div>
	</div>
</template>

<script>
import { CnLifecycleActions } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { isTerminal, useEngineTaskStore } from '../../store/modules/engineTask.js'
import { initializeStores } from '../../store/store.js'
import {
	isFinalStatus,
	openTasksQuery,
	taskIdOf,
	taskRouteFor,
	viewAllRouteFor,
} from '../../utils/caseTaskPaneHelpers.js'

export default {
	name: 'CaseTaskPane',

	components: {
		CnLifecycleActions,
	},

	// CnDetailWidgetHost spreads the widget's whole `content` blob onto the
	// renderer alongside the object context, so `register`, `schema`, `filter`
	// and `columns` arrive as attributes this component does not declare.
	// Without this they would be stringified onto the root element, putting
	// `[object Object]` in the DOM.
	inheritAttrs: false,

	props: {
		/** The case this pane is on, bound by the detail surface. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The widget's manifest `content` blob. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/** The open tasks of this case, earliest due first. */
			tasks: [],
			/**
			 * The last error already reported to the handler, so the watcher on
			 * the lifecycle child's inline error does not toast it repeatedly
			 * on every re-render.
			 */
			reportedError: '',
		}
	},

	computed: {
		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		engineTasks() {
			return useEngineTaskStore()
		},

		/**
		 * The task the pane acts on: the first open one.
		 *
		 * @return {object|null} The task row, or null when none is open.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		currentTask() {
			return this.tasks[0] ?? null
		},

		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		currentTaskId() {
			return taskIdOf(this.currentTask)
		},

		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		currentTitle() {
			return this.titleOf(this.currentTask)
		},

		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		currentAssignee() {
			const assignee = String(this.currentTask?.assignee ?? '').trim()
			return assignee === '' ? t('dossiq', 'Unassigned') : assignee
		},

		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		currentDue() {
			return this.formatDate(this.currentTask?.dueDate)
		},

		/**
		 * The open tasks after the one in the pane, listed underneath.
		 *
		 * @return {object[]} The remaining rows.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		remainingTasks() {
			return this.tasks.slice(1)
		},

		/**
		 * Server mode: no declared transitions, so CnLifecycleActions asks
		 * OpenRegister's `available-actions` route and labels the buttons with
		 * the schema's transition descriptions — the same labels TaskDetail
		 * shows for the same task.
		 *
		 * @return {{field: string}} The lifecycle config.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		lifecycleConfig() {
			return { field: 'status' }
		},

		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		viewAllRoute() {
			return viewAllRouteFor(this.objectId, this.content)
		},
	},

	watch: {
		objectId: {
			immediate: false,
			/**
			 * The surface re-bound this widget to another case.
			 *
			 * `mounted()` does the first load, so this stays non-immediate:
			 * an immediate handler would fire before initializeStores() has
			 * resolved and query a type the store has not registered.
			 *
			 * @return {void}
			 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
			 */
			handler() {
				this.load()
			},
		},
	},

	/**
	 * Resolve the stores, then load the case's open tasks.
	 *
	 * CnAppRoot mounts widget renderers before App.vue's initializeStores()
	 * has resolved the app-config, so the `caseTask` object type may not be
	 * registered yet — await it here (idempotent), the same way
	 * TaskWaitingCaseSection and InitiatorSection do.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
	 */
	async mounted() {
		await initializeStores()
		await this.load()
		this.watchLifecycleError()
	},

	methods: {
		taskIdOf,
		taskRouteFor,

		/**
		 * A task's display title, with a fallback so a titleless row is still
		 * identifiable rather than rendering as an empty line.
		 *
		 * @param {object|null} task The task row.
		 * @return {string} The title.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		titleOf(task) {
			const title = String(task?.title ?? '').trim()
			return title === '' ? t('dossiq', 'Task') : title
		},

		/**
		 * A due date in the reader's locale, or a plain phrase when the task
		 * carries none.
		 *
		 * @param {string|null|undefined} value The ISO date-time.
		 * @return {string} The formatted date.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		formatDate(value) {
			const raw = String(value ?? '').trim()
			if (raw === '') {
				return t('dossiq', 'No due date')
			}
			const parsed = new Date(raw)
			if (Number.isNaN(parsed.getTime())) {
				return raw
			}
			return parsed.toLocaleDateString()
		},

		/**
		 * Load the open tasks of this case.
		 *
		 * A failed read empties the pane and says so through a toast rather
		 * than leaving the previous task on screen: a stale task with live
		 * buttons is the one state worse than an empty pane.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		async load() {
			const caseId = String(this.objectId ?? '').trim()
			if (caseId === '') {
				this.tasks = []
				return
			}

			const rows = await this.engineTasks.list(
				openTasksQuery(caseId, this.content),
			)
			this.tasks = rows.filter((row) => !isTerminal(row))

			// The store surfaces its failure rather than throwing, because an
			// empty list with no trace of why is indistinguishable from a
			// genuinely empty one. Report it here so the handler sees it.
			if (this.engineTasks.error) {
				this.report(this.engineTasks.error)
			}
		},

		/**
		 * A lifecycle transition succeeded.
		 *
		 * A final status ends the task: confirm it by name and refetch, which
		 * puts the next open task in the pane (including one the flow created
		 * in response to this very completion, once the run has resumed). Any
		 * other transition — `activate` — keeps the same task and refetches so
		 * its newly allowed buttons render.
		 *
		 * @param {{action: string, to: string, object: object}} payload The event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		async onTransitioned(payload) {
			const finished = isFinalStatus(payload?.to)
			const title = this.currentTitle

			await this.load()

			if (finished) {
				showSuccess(t('dossiq', 'Task {title} finished', { title }))
			}
		},

		/**
		 * Forward the lifecycle child's inline error to a toast, once.
		 *
		 * CnLifecycleActions emits `transitioned` and `reload` and NOTHING on a
		 * rejected transition: it puts the server's message in its own
		 * `error` data and renders it inline. So a refusal cannot be observed
		 * through an event, and this watches the child's state instead. The
		 * pane keeps the task either way — no `transitioned` fired, so no
		 * refetch and no advance — and if the library ever stops exposing
		 * `error` this watcher simply never fires, leaving the inline message
		 * as the report. Asking nextcloud-vue for an `error` event is part of
		 * the follow-up issue this change files.
		 *
		 * @return {void}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		watchLifecycleError() {
			this.$watch(
				() => this.$refs.lifecycle?.error ?? '',
				(message) => this.report(message),
			)
		},

		/**
		 * Show a server message to the handler, at most once per message.
		 *
		 * @param {string|null|undefined} message The message.
		 * @return {void}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		report(message) {
			const text = String(message ?? '').trim()
			if (text === '' || text === this.reportedError) {
				return
			}
			this.reportedError = text
			showError(text)
		},
	},
}
</script>

<style scoped lang="scss">
.case-task-pane {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);

	&__title {
		margin: 0;
	}

	&__meta {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
		margin: 0;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
		}
	}

	&__actions {
		margin-top: var(--default-grid-baseline);
	}

	&__empty {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__remaining-title {
		margin: 0 0 var(--default-grid-baseline) 0;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__list-item {
		padding: calc(var(--default-grid-baseline) / 2) 0;
	}

	&__link {
		color: var(--color-primary-element);
		text-decoration: underline;
	}
}
</style>
