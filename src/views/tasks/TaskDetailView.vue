<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The task page (`/tasks/:id`), reading OpenRegister's task engine.

  WHY THIS PAGE IS `type: "custom"` AND THE TASKS INDEX IS NOT
  ------------------------------------------------------------
  The index kept `type: "index"` and gained `entitySource: "tasks"`, because
  the library ships an entity source for exactly this case: a list that is
  neither OpenRegister objects nor rows a parent holds. There is no detail
  equivalent. CnDetailPage takes `objectType` / `objectId` and resolves them
  through the object store; it has no entity-source mode at all (checked
  against the installed @conduction/nextcloud-vue 2.46.0, not the docs). So
  a `type: "detail"` page binds a register and a schema, and the schema this
  one bound is the one being deleted.

  WHAT WAS BROKEN, PRECISELY
  --------------------------
  Not "the page did not work". The page asked BOTH stores with the SAME
  uuid, and each answered for a different task:

    - an ENGINE uuid rendered the case card (TaskCaseCard reads the engine)
      and every field below it as an em dash, because the object read found
      nothing;
    - a LEGACY uuid rendered the fields and no case card.

  Neither showed a lifecycle button that worked. Both looked like a rendering
  bug rather than two stores disagreeing, which is why it survived: an em
  dash is what an empty field looks like, and an absent card is what a task
  with no case looks like.

  THE LIFECYCLE BUTTONS ARE THE ENGINE'S VERBS
  --------------------------------------------
  🔴 NOT `CnLifecycleActions`, and this mistake has already been made once on
  the case pane. That component asks OpenRegister for an OBJECT's available
  transitions (`GET /api/objects/{uuid}/available-actions`). An engine task
  is not an object, so the endpoint 404s and the strip renders no buttons at
  all. It only shows in a browser: every unit test stubs the component away,
  so the suite stays green over a page with no working buttons.

  The verbs go through `useEngineTaskStore.invoke(uuid, verb)`. Which verbs
  are OFFERED is read off the row, never guessed at: `claim` when nobody
  holds the task, `unclaim` when somebody does, and `complete` / `cancel`
  while it is open. Whether the caller MAY invoke one is the engine's call,
  and it refuses with a message naming the verb and the reason, which is
  shown as-is.

  WHAT MOVED, AND WHAT DID NOT
  ----------------------------
  The route (`/tasks/:id`), the page id (`TaskDetail`) and therefore every
  deep link and notification target are unchanged. The case card and the
  waiting-case section are the same two components the detail page mounted
  through its slots. The notes, appointments and history leaves each moved
  from an object-anchored library widget to the task-anchored endpoint,
  because an engine task has no object for the old ones to read.

  @spec openspec/specs/task-management/spec.md
-->
<template>
	<div class="task-detail-page" data-testid="task-detail-page">
		<header class="task-detail-page__header">
			<div class="task-detail-page__identity">
				<h2 class="task-detail-page__title" data-testid="task-detail-title">
					{{ title }}
				</h2>
				<p class="task-detail-page__crumbs">
					<CnStatusBadge
						v-if="stateLabel"
						:label="stateLabel"
						:variant="stateTone"
						size="small"
						data-testid="task-detail-state" />
					<CnStatusBadge
						v-if="priorityTone"
						:label="priorityText"
						:variant="priorityTone"
						size="small"
						data-testid="task-detail-priority" />
					<router-link :to="{ name: 'Tasks' }">
						{{ t('dossiq', 'Tasks') }}
					</router-link>
					<span aria-hidden="true">/</span>
					<span>{{ title }}</span>
					<template v-if="metaLine">
						<span aria-hidden="true">·</span>
						<span data-testid="task-detail-meta">{{ metaLine }}</span>
					</template>
				</p>
			</div>

			<div
				v-if="verbs.length > 0"
				class="task-detail-page__actions"
				data-testid="task-detail-actions">
				<NcButton
					v-for="verb in primaryVerbs"
					:key="verb.name"
					:disabled="busy"
					variant="secondary"
					:data-testid="`task-detail-verb-${verb.name}`"
					@click="invoke(verb)">
					{{ verb.label }}
				</NcButton>
				<NcActions
					v-if="moreVerbs.length > 0"
					:name="t('dossiq', 'More')"
					:menuName="t('dossiq', 'More')">
					<NcActionButton
						v-for="verb in moreVerbs"
						:key="verb.name"
						:disabled="busy"
						:data-testid="`task-detail-verb-${verb.name}`"
						@click="invoke(verb)">
						{{ verb.label }}
					</NcActionButton>
				</NcActions>
			</div>
		</header>

		<NcEmptyContent
			v-if="missing"
			:name="t('dossiq', 'This task is not there')"
			:description="
				t('dossiq', 'It was finished and removed, or you may not open it.')
			"
			data-testid="task-detail-missing" />

		<div
			v-else-if="task"
			class="task-detail-page__columns"
			data-testid="task-detail-body">
			<div class="task-detail-page__main">
				<div class="task-detail-page__tabs" role="tablist">
					<button
						v-for="tab in tabs"
						:key="tab.id"
						type="button"
						role="tab"
						class="task-detail-page__tab"
						:class="{
							'task-detail-page__tab--active': activeTab === tab.id,
						}"
						:aria-selected="String(activeTab === tab.id)"
						:data-testid="`task-detail-tab-${tab.id}`"
						@click="activeTab = tab.id">
						{{ tab.label }}
					</button>
				</div>

				<div
					v-if="activeTab === 'overview'"
					class="task-detail-page__panel"
					role="tabpanel">
					<p
						v-if="description"
						class="task-detail-page__card task-detail-page__description"
						data-testid="task-detail-description">
						{{ description }}
					</p>

					<section
						v-if="checklist.length > 0"
						class="task-detail-page__card task-detail-page__checklist"
						data-testid="task-detail-checklist">
						<h3 class="task-detail-page__section-title">
							{{ t('dossiq', 'Checklist') }}
						</h3>
						<p class="task-detail-page__section-sub">
							{{
								t('dossiq', '{done} of {total} done', {
									done: checklistDone,
									total: checklist.length,
								})
							}}
						</p>
						<ul class="task-detail-page__checklist-items">
							<li v-for="item in checklist" :key="item.id">
								<NcCheckboxRadioSwitch
									:modelValue="item.checked === true"
									:disabled="busy || finished"
									:data-testid="`task-detail-check-${item.id}`"
									@update:modelValue="check(item, $event)">
									{{ item.label || item.id }}
								</NcCheckboxRadioSwitch>
							</li>
						</ul>
					</section>

					<section class="task-detail-page__card task-detail-page__leaf">
						<h3 class="task-detail-page__section-title">
							{{ t('dossiq', 'Appointments') }}
						</h3>
						<TaskEventsLeaf :taskId="taskId" />
					</section>

					<section class="task-detail-page__card task-detail-page__leaf">
						<h3 class="task-detail-page__section-title">
							{{ t('dossiq', 'Notes') }}
						</h3>
						<TaskNotesLeaf :taskId="taskId" />
					</section>
				</div>

				<div v-else class="task-detail-page__panel" role="tabpanel">
					<TaskAuditLeaf :taskId="taskId" />
				</div>
			</div>

			<aside class="task-detail-page__side">
				<section
					v-if="hasFacts"
					class="task-detail-page__card task-detail-page__facts-card">
					<h3 class="task-detail-page__section-title">
						{{ t('dossiq', 'Task') }}
					</h3>
					<CnFactsWidget :content="taskFacts" :objectData="task" />
				</section>

				<section
					v-if="caseId"
					class="task-detail-page__card task-detail-page__facts-card"
					data-testid="task-case-card">
					<h3 class="task-detail-page__section-title">
						{{ t('dossiq', 'Case') }}
					</h3>
					<CnFactsWidget :content="caseFacts" :objectData="task" />
				</section>
				<TaskWaitingCaseSection />
			</aside>
		</div>
	</div>
</template>

<script>
import { CnFactsWidget, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import TaskWaitingCaseSection from '../../components/flow/TaskWaitingCaseSection.vue'
import TaskAuditLeaf from '../../components/tasks/TaskAuditLeaf.vue'
import TaskEventsLeaf from '../../components/tasks/TaskEventsLeaf.vue'
import TaskNotesLeaf from '../../components/tasks/TaskNotesLeaf.vue'
import { isTerminal, useEngineTaskStore } from '../../store/modules/engineTask.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { caseIdFrom, taskCaseRef } from '../../utils/flowTaskHelpers.js'
import {
	PRIORITY_VALUES,
	priorityLabel,
	priorityPillTone,
} from '../../utils/priorityValues.js'
import { taskMetaLine } from '../../utils/taskMetaLine.js'
import { taskStateLabel, taskStateTone } from '../../utils/taskStateLabel.js'

export default {
	name: 'TaskDetailView',

	components: {
		CnFactsWidget,
		CnStatusBadge,
		NcActionButton,
		NcActions,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		TaskAuditLeaf,
		TaskEventsLeaf,
		TaskNotesLeaf,
		TaskWaitingCaseSection,
	},

	// CnPageRenderer spreads the page's whole `config` blob onto the page
	// component alongside the route params, so `_note` and
	// `documentationUrl` arrive as attributes this component does not
	// declare. Without this they would be painted onto the root element,
	// and a `title` attribute puts a browser tooltip over the whole page.
	inheritAttrs: false,

	props: {
		/**
		 * The engine task uuid, from the `/tasks/:id` route.
		 *
		 * CnPageRenderer merges `$route.params` into the dispatched
		 * component's props, so this is the route parameter under its own
		 * name. The route is unchanged from the detail page it replaces:
		 * deep links and notification targets resolve exactly as before.
		 */
		id: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** The task, as the engine returns it. */
			task: null,
			/** Whether the read completed and found nothing. */
			missing: false,
			/** Whether a verb is in flight, so a double click cannot fire twice. */
			busy: false,
			/** The tab in view: the overview, or the history. */
			activeTab: 'overview',
			/** The number of the case the task is on, '' until it is read. */
			caseNumber: '',
		}
	},

	computed: {
		/** @spec openspec/specs/task-management/spec.md */
		engineTasks() {
			return useEngineTaskStore()
		},

		/**
		 * The task uuid, trimmed.
		 *
		 * @return {string} The uuid.
		 * @spec openspec/specs/task-management/spec.md
		 */
		taskId() {
			return String(this.id ?? this.$route?.params?.id ?? '').trim()
		},

		/**
		 * The task's heading.
		 *
		 * @return {string} The title.
		 * @spec openspec/specs/task-management/spec.md
		 */
		title() {
			const title = String(
				this.task?.displayTitle ?? this.task?.title ?? '',
			).trim()
			return title === '' ? t('dossiq', 'Task') : title
		},

		/**
		 * The state, for the badge beside the heading, in the reader's words
		 * (DqTaak draws "In uitvoering", never "active").
		 *
		 * The engine keeps its own vocabulary; only the label is worded, and by
		 * the one helper the case plan uses too, so the two cannot diverge.
		 *
		 * @return {string} The label, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		stateLabel() {
			return taskStateLabel(this.task?.state)
		},

		/**
		 * The tone of the state badge: DqTaak draws an active task in the
		 * work-in-progress purple and a finished one in green.
		 *
		 * @return {string} A CnStatusBadge variant.
		 * @spec openspec/specs/task-management/spec.md
		 */
		stateTone() {
			return taskStateTone(this.task?.state)
		},

		/**
		 * The tone of the priority pill beside the state, '' for no pill.
		 * Only a high or urgent task draws one, as DqTaak draws "Hoog".
		 *
		 * @return {string} A CnStatusBadge variant, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		priorityTone() {
			return priorityPillTone(this.task?.priority)
		},

		/**
		 * The priority in the reader's words, for the pill.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/task-management/spec.md
		 */
		priorityText() {
			return priorityLabel(this.task?.priority)
		},

		/**
		 * The line after the crumbs (DqTaak): "bij zaak 2026-0074, deadline
		 * vandaag". The case number is read from the case; the deadline is the
		 * task's own `dueAt` in words. A part that is not known is left out.
		 *
		 * @return {string} The line, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		metaLine() {
			return taskMetaLine({
				caseNumber: this.caseNumber,
				dueAt: this.task?.dueAt ?? null,
				finished: this.finished,
			})
		},

		/**
		 * Whether the task is finished.
		 *
		 * @return {boolean} True for a terminal task.
		 * @spec openspec/specs/task-management/spec.md
		 */
		finished() {
			return isTerminal(this.task)
		},

		/** @spec openspec/specs/task-management/spec.md */
		description() {
			return String(this.task?.description ?? '').trim()
		},

		/**
		 * The task's own facts for the library's facts card, in the order the
		 * board draws them (DqTaak). A fact the task does not carry is dropped by
		 * the widget rather than shown as an em dash. `candidateGroups` is a LIST
		 * on the engine (a task offered to two teams has two), joined by the
		 * widget's `list` format.
		 *
		 * @return {object} The `content` for CnFactsWidget.
		 * @spec openspec/specs/task-management/spec.md
		 */
		taskFacts() {
			return {
				fields: [
					{
						key: 'assignee',
						label: t('dossiq', 'Handler'),
						path: 'assignee',
					},
					{
						key: 'team',
						label: t('dossiq', 'Team'),
						path: 'candidateGroups',
						format: 'list',
					},
					{
						key: 'due',
						label: t('dossiq', 'Due by'),
						path: 'dueAt',
						format: 'date',
					},
					{
						key: 'expires',
						label: t('dossiq', 'Expires'),
						path: 'expiresAt',
						format: 'date',
					},
					{
						key: 'priority',
						label: t('dossiq', 'Priority'),
						path: 'priority',
						// The engine's value worded as the case priority is
						// (low/normal/high/urgent), never the raw "normal".
						map: Object.fromEntries(
							PRIORITY_VALUES.map((value) => [
								value,
								priorityLabel(value),
							]),
						),
					},
					{
						key: 'requester',
						label: t('dossiq', 'Requested by'),
						path: 'requester',
					},
					{
						key: 'created',
						label: t('dossiq', 'Created'),
						path: 'created',
						format: 'date',
					},
				],
			}
		},

		/**
		 * The case this task is on, or null. A task with no case draws no card.
		 *
		 * @return {string|null} The case id.
		 * @spec openspec/specs/task-management/spec.md
		 */
		caseId() {
			return caseIdFrom(taskCaseRef(this.task))
		},

		/**
		 * The case card for the library's facts widget: the title as the way
		 * back, the number and case type, the handler and the case's own
		 * deadline, and the status. The case type and the status are references
		 * the case holds as ids; `resolve` shows their names and never the id.
		 *
		 * @return {object} The `content` for CnFactsWidget.
		 * @spec openspec/specs/task-management/spec.md
		 */
		caseFacts() {
			const caseRoute = { name: 'CaseDetail', params: { id: '@source.id' } }
			return {
				source: {
					register: 'dossiq',
					schema: 'case',
					id: '@object.objectUuid',
				},

				fields: [
					{
						key: 'case-title',
						as: 'title',
						path: 'title',
						route: caseRoute,
					},
					{
						key: 'case-meta',
						as: 'line',
						parts: [
							'identifier',
							{
								path: 'caseType',
								resolve: {
									register: 'dossiq',
									schema: 'caseType',
									labelField: 'title',
								},
							},
						],
					},
					{
						key: 'case-owner',
						as: 'line',
						parts: [
							{ path: 'assignee', label: t('dossiq', 'Handler') },
							{
								path: 'deadline',
								label: t('dossiq', 'Case deadline'),
								format: 'date',
							},
						],
					},
					{
						key: 'case-status',
						label: t('dossiq', 'Status'),
						path: 'status',
						resolve: {
							register: 'dossiq',
							schema: 'statusType',
							labelField: 'name',
						},
					},
				],

				link: { label: t('dossiq', 'To the case'), route: caseRoute },
				emptyText: '',
			}
		},

		/**
		 * Whether the task carries any fact the card would show.
		 *
		 * @return {boolean} True when at least one fact has a value.
		 * @spec openspec/specs/task-management/spec.md
		 */
		hasFacts() {
			const task = this.task ?? {}
			const groups = Array.isArray(task.candidateGroups)
				? task.candidateGroups
				: []
			return (
				groups.length > 0
				|| [
					'assignee',
					'dueAt',
					'expiresAt',
					'priority',
					'requester',
					'created',
				].some((key) => String(task[key] ?? '').trim() !== '')
			)
		},

		/**
		 * The task's checklist, if it declares one.
		 *
		 * Items are `{ id, label, description, checked }` (the engine's Task
		 * entity says so on the field). An item with no id cannot be toggled,
		 * because the PATCH route addresses it by id, so it is dropped rather
		 * than rendered as a control that silently does nothing.
		 *
		 * @return {Array<object>} The items.
		 * @spec openspec/specs/task-management/spec.md
		 */
		checklist() {
			const items = this.task?.checklist
			if (Array.isArray(items) === false) {
				return []
			}
			return items.filter(
				(item) => item && String(item.id ?? '').trim() !== '',
			)
		},

		/**
		 * How many checklist items are ticked, for the card's "2 of 4 done".
		 *
		 * @return {number} The ticked items.
		 */
		checklistDone() {
			return this.checklist.filter((item) => item.checked === true).length
		},

		/**
		 * The page's tabs, as the board draws them.
		 *
		 * @return {Array<{id: string, label: string}>} The tabs.
		 */
		tabs() {
			return [
				{ id: 'overview', label: t('dossiq', 'Overview') },
				{ id: 'history', label: t('dossiq', 'Task history') },
			]
		},

		/**
		 * The verbs that stand in the header: finishing, and handing on.
		 *
		 * @return {Array<object>} The verbs.
		 */
		primaryVerbs() {
			return this.verbs.filter((verb) => verb.name !== 'cancel')
		},

		/**
		 * The verbs behind More: cancelling a task is rare.
		 *
		 * @return {Array<object>} The verbs.
		 */
		moreVerbs() {
			return this.verbs.filter((verb) => verb.name === 'cancel')
		},

		/**
		 * The lifecycle verbs offered on this task.
		 *
		 * Read off the ROW, not guessed: a task nobody holds can be claimed,
		 * a task somebody holds can be handed back, and an open task can be
		 * completed or cancelled. A finished task offers nothing, because
		 * every verb on it would be refused and a row of buttons that all
		 * fail is worse than none.
		 *
		 * This is not an authorization check and does not pretend to be. The
		 * engine decides whether the caller may invoke a verb and refuses
		 * with a reason; duplicating that judgement here is the copied
		 * authorization this migration exists to remove.
		 *
		 * @return {Array<{name: string, label: string, primary: boolean}>} The verbs.
		 * @spec openspec/specs/task-management/spec.md
		 */
		verbs() {
			if (this.task === null || this.finished === true) {
				return []
			}

			const held = String(this.task?.assignee ?? '').trim() !== ''
			const verbs = []

			verbs.push({
				name: 'complete',
				label: t('dossiq', 'Complete'),
				primary: true,
			})

			if (held === false) {
				verbs.push({
					name: 'claim',
					label: t('dossiq', 'Pick up'),
					primary: false,
				})
			} else {
				verbs.push({
					name: 'unclaim',
					label: t('dossiq', 'Hand back'),
					primary: false,
				})
			}

			verbs.push({
				name: 'cancel',
				label: t('dossiq', 'Cancel'),
				primary: false,
			})

			return verbs
		},
	},

	watch: {
		taskId: {
			immediate: false,
			/**
			 * The router moved to another task on the same page.
			 *
			 * Non-immediate: `mounted()` does the first read, and an
			 * immediate handler would fire it before the stores resolve.
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
	 * Resolve the stores, then read the task.
	 *
	 * `initializeStores()` is awaited even though the engine read needs no
	 * object type: TaskCaseCard is mounted below and resolves the CASE
	 * through the object store, and CnAppRoot mounts a page component before
	 * App.vue's own initialisation has settled. The call is idempotent.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/task-management/spec.md
	 */
	async mounted() {
		await initializeStores()
		await this.load()
	},

	methods: {
		/**
		 * Read the task.
		 *
		 * A failure and an absence are told apart. The engine answers 404 for
		 * a task that is not there AND for one the caller may not read, on
		 * purpose, so a stranger cannot confirm a uuid by probing; the page
		 * says the same thing for both rather than guessing which it was.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async load() {
			const id = this.taskId
			if (id === '') {
				this.task = null
				this.missing = true
				return
			}

			this.task = await this.engineTasks.fetch(id)
			this.missing = this.task === null

			if (this.task === null && this.engineTasks.error) {
				this.report(this.engineTasks.error)
			}

			await this.loadCaseNumber()
		},

		/**
		 * Read the number of the case the task is on, for the line after the
		 * crumbs. The engine task names its case by uuid only. A case that
		 * cannot be read leaves the number out; the line then says the
		 * deadline alone, and the case card says why the case is missing.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async loadCaseNumber() {
			this.caseNumber = ''
			const caseId = this.caseId
			if (!caseId) {
				return
			}
			try {
				const found = await useObjectStore().fetchObject('case', caseId)
				// The task may have changed while the case was read.
				if (caseId === this.caseId) {
					this.caseNumber = String(found?.identifier ?? '').trim()
				}
			} catch {
				this.caseNumber = ''
			}
		},

		/**
		 * Invoke a verb, and let the engine rule.
		 *
		 * A refusal keeps the engine's own message, which names the verb and
		 * the reason. A generic failure would throw away the only part a
		 * handler can act on.
		 *
		 * @param {{name: string}} verb The verb.
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async invoke(verb) {
			const id = this.taskId
			if (id === '' || this.busy === true) {
				return
			}

			this.busy = true
			const title = this.title
			try {
				const updated = await this.engineTasks.invoke(id, verb.name)
				if (updated === null) {
					this.report(this.engineTasks.error)
					return
				}

				this.task = updated

				if (isTerminal(updated)) {
					showSuccess(t('dossiq', 'Task {title} finished', { title }))
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Tick or untick a checklist item.
		 *
		 * The engine answers with the whole task, so the reply replaces the
		 * row rather than the local box being flipped: a refused tick then
		 * shows the item unchanged instead of a control that moved and a
		 * server that did not.
		 *
		 * @param {object} item The checklist item.
		 * @param {boolean} checked The new state.
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async check(item, checked) {
			if (this.busy === true) {
				return
			}

			this.busy = true
			try {
				const updated = await this.engineTasks.checkItem(
					this.taskId,
					item?.id,
					checked === true,
				)
				if (updated === null) {
					this.report(this.engineTasks.error)
					return
				}
				this.task = updated
			} finally {
				this.busy = false
			}
		},

		/**
		 * Show a server message to the handler.
		 *
		 * @param {string|null|undefined} message The message.
		 * @return {void}
		 * @spec openspec/specs/task-management/spec.md
		 */
		report(message) {
			const text = String(message ?? '').trim()
			if (text === '') {
				return
			}
			showError(text)
		},
	},
}
</script>

<style scoped lang="scss">
.task-detail-page {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 1200px;

	&__header {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__identity {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		min-width: 0;
	}

	&__title {
		margin: 0;
		font-size: 28px;
		font-weight: 700;
		overflow-wrap: anywhere;
	}

	&__crumbs {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		flex-wrap: wrap;
	}

	&__columns {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 300px;
		gap: calc(var(--default-grid-baseline) * 4);
		align-items: start;
	}

	&__main,
	&__side,
	&__panel {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 4);
		min-width: 0;
	}

	&__tabs {
		display: flex;
		gap: var(--default-grid-baseline);
	}

	&__tab {
		padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
		border: 1px solid var(--color-border);
		border-bottom: none;
		border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
		background: var(--color-background-dark);
		color: var(--color-main-text);
		font-weight: 600;
		cursor: pointer;

		&--active {
			background: var(--color-main-background);
		}
	}

	&__card {
		margin: 0;
		padding: calc(var(--default-grid-baseline) * 5);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
	}

	&__section-title {
		margin: 0 0 var(--default-grid-baseline);
		font-size: 18px;
		font-weight: 700;
	}

	&__section-sub {
		margin: 0 0 calc(var(--default-grid-baseline) * 3);
		color: var(--color-text-maxcontrast);
	}

	&__description {
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__checklist-items {
		margin: 0;
		padding: 0;
		list-style: none;
	}
}

@media (max-width: 900px) {
	.task-detail-page__columns {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
