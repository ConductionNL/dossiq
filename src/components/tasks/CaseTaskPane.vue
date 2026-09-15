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

  @spec openspec/specs/task-management/spec.md
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
				<dt>{{ t('dossiq', 'Reference') }}</dt>
				<dd data-testid="case-task-pane-reference">
					{{ referenceOf(currentTask) }}
				</dd>
				<dt>{{ t('dossiq', 'Assignee') }}</dt>
				<dd data-testid="case-task-pane-assignee">
					{{ currentAssignee }}
				</dd>
				<dt>{{ t('dossiq', 'Due') }}</dt>
				<dd data-testid="case-task-pane-due">
					{{ currentDue }}
				</dd>
				<dt>{{ t('dossiq', 'Lock') }}</dt>
				<dd data-testid="case-task-pane-lock">
					{{ lockOf(currentTask) }}
				</dd>
			</dl>
			<p
				v-if="candidatesFor(currentTask)"
				class="case-task-pane__candidates"
				data-testid="case-task-pane-candidates">
				{{ candidatesFor(currentTask) }}
			</p>
			<TaskFormFields
				v-if="formOf(currentTask)"
				:form="formOf(currentTask)"
				:answers="answersFor(currentTask)"
				:test-id="`case-task-pane-form`" />
			<div
				class="case-task-pane__actions"
				data-testid="case-task-pane-actions">
				<NcButton
					v-if="mayClaim(currentTask)"
					:disabled="busy"
					variant="secondary"
					data-testid="case-task-pane-verb-claim"
					@click="claim(currentTask)">
					{{ t('dossiq', 'Pick up') }}
				</NcButton>
				<NcButton
					v-for="verb in verbs"
					:key="verb.name"
					:disabled="busy"
					:variant="verb.primary ? 'primary' : 'secondary'"
					:data-testid="`case-task-pane-verb-${verb.name}`"
					@click="invoke(verb, currentTask)">
					{{ verb.label }}
				</NcButton>
			</div>
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
					class="case-task-pane__list-item"
					:data-testid="`case-task-pane-task-${taskIdOf(task)}`">
					<router-link
						v-if="taskRouteFor(task, content)"
						:to="taskRouteFor(task, content)"
						class="case-task-pane__link">
						{{ titleOf(task) }}
					</router-link>
					<span v-else>{{ titleOf(task) }}</span>
					<span class="case-task-pane__row-meta">
						{{ referenceOf(task) }} · {{ formatDate(task.dueDate) }}
					</span>
					<TaskFormFields
						v-if="formOf(task)"
						:form="formOf(task)"
						:answers="answersFor(task)"
						:test-id="`case-task-pane-form-${taskIdOf(task)}`" />
					<span class="case-task-pane__row-actions">
						<NcButton
							v-if="mayClaim(task)"
							:disabled="busy"
							variant="tertiary"
							:data-testid="`case-task-pane-row-claim-${taskIdOf(task)}`"
							@click="claim(task)">
							{{ t('dossiq', 'Pick up') }}
						</NcButton>
						<NcButton
							v-for="verb in verbs"
							:key="verb.name"
							:disabled="busy"
							variant="tertiary"
							:data-testid="`case-task-pane-row-${verb.name}-${taskIdOf(task)}`"
							@click="invoke(verb, task)">
							{{ verb.label }}
						</NcButton>
					</span>
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
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { isTerminal, useEngineTaskStore } from '../../store/modules/engineTask.js'
import { initializeStores } from '../../store/store.js'
import {
	candidatesOf,
	isFinalStatus,
	isUnclaimed,
	missingRequiredField,
	openTasksQuery,
	taskFormOf,
	taskIdOf,
	taskLockOf,
	taskReferenceOf,
	taskRouteFor,
	viewAllRouteFor,
} from '../../utils/caseTaskPaneHelpers.js'
import TaskFormFields from './TaskFormFields.vue'

export default {
	name: 'CaseTaskPane',

	components: {
		NcButton,
		TaskFormFields,
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
			/** Whether a verb is in flight, so a double click cannot fire twice. */
			busy: false,
			/**
			 * The last error already reported to the handler, so the watcher on
			 * the lifecycle child's inline error does not toast it repeatedly
			 * on every re-render.
			 */
			reportedError: '',
			/**
			 * One answer set per open task, keyed by task id.
			 *
			 * Per TASK, not one shared set, because every open task on the
			 * case is completable here: a single set would carry one task's
			 * verslag into the next task's completion.
			 */
			answers: {},
			/**
			 * What the task engine on this instance answers to.
			 *
			 * Asked rather than assumed. The claim affordance is rendered
			 * only when the engine has a claim act; where it has none, the
			 * declaration is stated on the case type screen and no button
			 * that silently assigns is offered here.
			 */
			capabilities: { claim: false },
		}
	},

	computed: {
		/** @spec openspec/specs/task-management/spec.md */
		engineTasks() {
			return useEngineTaskStore()
		},

		/**
		 * The task the pane acts on: the first open one.
		 *
		 * @return {object|null} The task row, or null when none is open.
		 * @spec openspec/specs/task-management/spec.md
		 */
		currentTask() {
			return this.tasks[0] ?? null
		},

		/** @spec openspec/specs/task-management/spec.md */
		currentTaskId() {
			return taskIdOf(this.currentTask)
		},

		/** @spec openspec/specs/task-management/spec.md */
		currentTitle() {
			return this.titleOf(this.currentTask)
		},

		/** @spec openspec/specs/task-management/spec.md */
		currentAssignee() {
			const assignee = String(this.currentTask?.assignee ?? '').trim()
			return assignee === '' ? t('dossiq', 'Unassigned') : assignee
		},

		/** @spec openspec/specs/task-management/spec.md */
		currentDue() {
			return this.formatDate(this.currentTask?.dueDate)
		},

		/**
		 * The open tasks after the one in the pane, listed underneath.
		 *
		 * @return {object[]} The remaining rows.
		 * @spec openspec/specs/task-management/spec.md
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
		 * @spec openspec/specs/task-management/spec.md
		 */
		/**
		 * The verbs offered on the current task.
		 *
		 * 🔴 NOT `CnLifecycleActions`. That component asks OpenRegister for
		 * an OBJECT's available transitions
		 * (`/api/objects/{uuid}/available-actions`), and an engine task is
		 * not an object: the endpoint answers 500. Measured in the browser
		 * after the read moved, which is the only place it shows — the unit
		 * tests stub the component away.
		 *
		 * The engine decides whether a verb is legal and whether the caller
		 * may invoke it, and refuses visibly with a message naming both. So
		 * this offers the two a handler needs and lets the engine rule,
		 * rather than pre-judging availability client-side, which is the
		 * duplicated authorization this migration exists to remove.
		 *
		 * @return {Array<{name: string, label: string, primary: boolean}>} The verbs.
		 * @spec openspec/changes/remove-casetask/tasks.md
		 */
		verbs() {
			return [
				{ name: 'complete', label: t('dossiq', 'Complete'), primary: true },
				{ name: 'cancel', label: t('dossiq', 'Cancel'), primary: false },
			]
		},

		lifecycleConfig() {
			return { field: 'status' }
		},

		/** @spec openspec/specs/task-management/spec.md */
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
			 * @spec openspec/specs/task-management/spec.md
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
	 * @spec openspec/specs/task-management/spec.md
	 */
	async mounted() {
		await initializeStores()
		await Promise.all([this.load(), this.loadCapabilities()])
		this.watchLifecycleError()
	},

	methods: {
		taskIdOf,
		taskRouteFor,

		/**
		 * Ask the engine what it can do, once per mount.
		 *
		 * A failure leaves the capability off, which renders no claim
		 * affordance. That is the safe direction: a button that silently
		 * assigns a task the handler believed they claimed from a pool is
		 * worse than no button.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		async loadCapabilities() {
			try {
				const response = await axios.get(
					generateUrl('/apps/dossiq/api/case-tasks/capabilities'),
				)
				this.capabilities = {
					claim: response?.data?.claim === true,
				}
			} catch (error) {
				this.capabilities = { claim: false }
			}
		},

		/**
		 * How this task is referred to: its number, or the engine identifier.
		 *
		 * A task with no number says so rather than showing a blank, and
		 * dossiq generates none of its own.
		 *
		 * @param {object} task The task row.
		 * @return {string} What to show.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		referenceOf(task) {
			const reference = taskReferenceOf(task)
			if (reference.value === '') {
				return t('dossiq', 'No number yet')
			}
			if (reference.isNumber) {
				return reference.value
			}
			return t('dossiq', '{id} (no number yet)', { id: reference.value })
		},

		/**
		 * Whether this task is locked, in the reader's words.
		 *
		 * Three answers, not two: the engine tracks no lock on a task today,
		 * and saying "not locked" where nothing is tracked would tell a
		 * handler that nobody else is editing it, which nothing here knows.
		 *
		 * @param {object} task The task row.
		 * @return {string} The lock state.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		lockOf(task) {
			const locked = taskLockOf(task)
			if (locked === null) {
				return t('dossiq', 'Not tracked')
			}
			return locked ? t('dossiq', 'Locked') : t('dossiq', 'Open')
		},

		/**
		 * Who this task is offered to, when it is offered rather than assigned.
		 *
		 * @param {object} task The task row.
		 * @return {string} The sentence, or '' when it has an assignee.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		candidatesFor(task) {
			if (!isUnclaimed(task)) {
				return ''
			}
			const candidates = candidatesOf(task).join(', ')
			if (this.capabilities.claim) {
				return t('dossiq', 'Waiting for someone from {candidates}', { candidates })
			}
			// The declaration is honoured by the case type and not yet by the
			// engine. Said plainly rather than hidden, because a handler
			// waiting for a team to pick it up would wait forever.
			return t(
				'dossiq',
				'Meant for {candidates}. Nobody can pick it up here yet, so assign it to someone.',
				{ candidates },
			)
		},

		/**
		 * The form this task carries, or null.
		 *
		 * @param {object} task The task row.
		 * @return {object|null} The form.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		formOf(task) {
			return taskFormOf(task)
		},

		/**
		 * This task's own answer set, created on first use.
		 *
		 * @param {object} task The task row.
		 * @return {object} The answers.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		answersFor(task) {
			const id = taskIdOf(task)
			if (!this.answers[id]) {
				this.answers = { ...this.answers, [id]: {} }
			}
			return this.answers[id]
		},

		/**
		 * Whether a claim affordance may be offered on this task.
		 *
		 * @param {object} task The task row.
		 * @return {boolean} True when the engine answers a claim act and the
		 *   task is waiting for one.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		mayClaim(task) {
			return this.capabilities.claim === true && isUnclaimed(task)
		},

		/**
		 * Take a task that was offered to a team.
		 *
		 * @param {object} task The task row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		async claim(task) {
			const id = taskIdOf(task)
			if (id === '' || this.busy === true) {
				return
			}

			this.busy = true
			try {
				const updated = await this.engineTasks.invoke(id, 'claim')
				if (updated === null) {
					this.report(this.engineTasks.error)
					return
				}

				await this.load()
				showSuccess(t('dossiq', 'Task {title} is yours', { title: this.titleOf(task) }))
			} finally {
				this.busy = false
			}
		},

		/**
		 * A task's display title, with a fallback so a titleless row is still
		 * identifiable rather than rendering as an empty line.
		 *
		 * @param {object|null} task The task row.
		 * @return {string} The title.
		 * @spec openspec/specs/task-management/spec.md
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
		 * @spec openspec/specs/task-management/spec.md
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
		 * @spec openspec/specs/task-management/spec.md
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
		 * @spec openspec/specs/task-management/spec.md
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
		 * Invoke a verb on the current task, and let the engine rule.
		 *
		 * A refusal keeps the engine's own message, which names the verb and
		 * the reason ("not the assignee"). A generic failure would throw away
		 * the only part a handler can act on.
		 *
		 * EVERY OPEN TASK, NOT ONLY THE FIRST. The pane used to act on
		 * `currentTask` alone, so finishing the second task of a case meant
		 * leaving the page for it — which is the route change this whole
		 * surface exists to remove. The task is passed in now and defaults to
		 * the current one, so the existing buttons behave exactly as they did.
		 *
		 * A COMPLETION CARRIES THE FORM'S ANSWERS. A required field left
		 * empty is named here, before the round trip, using the same rule the
		 * server applies; the server refuses it again for every other client.
		 *
		 * @param {{name: string}} verb The verb.
		 * @param {object} [task] The task to act on; the current one by default.
		 * @return {Promise<void>}
		 * @spec openspec/changes/remove-casetask/tasks.md
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		async invoke(verb, task = null) {
			const subject = task ?? this.currentTask
			const id = taskIdOf(subject)
			if (id === '' || this.busy === true) {
				return
			}

			const title = this.titleOf(subject)
			const answers = this.answersFor(subject)
			if (verb.name === 'complete') {
				const missing = missingRequiredField(subject, answers)
				if (missing !== '') {
					showError(
						t('dossiq', 'Fill in {field} before completing this task', {
							field: missing,
						}),
					)
					return
				}
			}

			this.busy = true
			try {
				const updated = await this.engineTasks.invoke(
					id,
					verb.name,
					verb.name === 'complete' ? { data: answers } : {},
				)
				if (updated === null) {
					this.report(this.engineTasks.error)
					return
				}

				await this.load()

				if (isTerminal(updated)) {
					showSuccess(t('dossiq', 'Task {title} finished', { title }))
				}
			} finally {
				this.busy = false
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
		 * @spec openspec/specs/task-management/spec.md
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
		 * @spec openspec/specs/task-management/spec.md
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
