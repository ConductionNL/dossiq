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
				<CnStatusBadge
					v-if="stateLabel"
					:label="stateLabel"
					size="small"
					data-testid="task-detail-state" />
			</div>

			<div
				v-if="verbs.length > 0"
				class="task-detail-page__actions"
				data-testid="task-detail-actions">
				<NcButton
					v-for="verb in verbs"
					:key="verb.name"
					:disabled="busy"
					:variant="verb.primary ? 'primary' : 'secondary'"
					:data-testid="`task-detail-verb-${verb.name}`"
					@click="invoke(verb)">
					{{ verb.label }}
				</NcButton>
			</div>
		</header>

		<NcEmptyContent
			v-if="missing"
			:name="t('dossiq', 'This task is not there')"
			:description="
				t('dossiq', 'It was finished and removed, or you may not open it.')
			"
			data-testid="task-detail-missing" />

		<template v-else-if="task">
			<TaskCaseCard :objectId="taskId" :objectData="task" />

			<section class="task-detail-page__body" data-testid="task-detail-body">
				<p
					v-if="description"
					class="task-detail-page__description"
					data-testid="task-detail-description">
					{{ description }}
				</p>

				<dl v-if="facts.length > 0" class="task-detail-page__facts">
					<div
						v-for="fact in facts"
						:key="fact.key"
						class="task-detail-page__fact">
						<dt class="task-detail-page__label">
							{{ fact.label }}
						</dt>
						<dd
							class="task-detail-page__value"
							:data-testid="`task-detail-${fact.key}`">
							{{ fact.value }}
						</dd>
					</div>
				</dl>
			</section>

			<section
				v-if="checklist.length > 0"
				class="task-detail-page__checklist"
				data-testid="task-detail-checklist">
				<h3 class="task-detail-page__section-title">
					{{ t('dossiq', 'Checklist') }}
				</h3>
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

			<TaskWaitingCaseSection />

			<div class="task-detail-page__leaves">
				<section class="task-detail-page__leaf">
					<h3 class="task-detail-page__section-title">
						{{ t('dossiq', 'Notes') }}
					</h3>
					<TaskNotesLeaf :taskId="taskId" />
				</section>

				<section class="task-detail-page__leaf">
					<h3 class="task-detail-page__section-title">
						{{ t('dossiq', 'Appointments') }}
					</h3>
					<TaskEventsLeaf :taskId="taskId" />
				</section>
			</div>

			<TaskAuditLeaf :taskId="taskId" />
		</template>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import TaskWaitingCaseSection from '../../components/flow/TaskWaitingCaseSection.vue'
import TaskAuditLeaf from '../../components/tasks/TaskAuditLeaf.vue'
import TaskCaseCard from '../../components/tasks/TaskCaseCard.vue'
import TaskEventsLeaf from '../../components/tasks/TaskEventsLeaf.vue'
import TaskNotesLeaf from '../../components/tasks/TaskNotesLeaf.vue'
import { isTerminal, useEngineTaskStore } from '../../store/modules/engineTask.js'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'TaskDetailView',

	components: {
		CnStatusBadge,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		TaskAuditLeaf,
		TaskCaseCard,
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
		 * The state, for the badge beside the heading.
		 *
		 * The engine's own vocabulary, untranslated into a second one. The
		 * store's header says why: `Task::STATES` is the same CMMN vocabulary
		 * `caseTask` used, and a mapping table here would diverge the two the
		 * first time either side gained a value.
		 *
		 * @return {string} The state, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		stateLabel() {
			return String(this.task?.state ?? '').trim()
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
		 * The task's own facts, in reading order.
		 *
		 * Only what is present. That is the fix, not a refinement: the
		 * page's Data widget rendered EVERY declared property, so an engine
		 * uuid on the object store produced a full grid of em dashes and
		 * looked like a broken record rather than a task read from the wrong
		 * place. A fact that is not there is now absent, the same convention
		 * TaskCaseCard already uses one card above.
		 *
		 * The CASE is deliberately not here. It is the card above, resolved
		 * to a title and a link; repeating it as a raw uuid is what the card
		 * exists to stop.
		 *
		 * @return {Array<{key: string, label: string, value: string}>} The rows.
		 * @spec openspec/specs/task-management/spec.md
		 */
		facts() {
			const rows = [
				{
					key: 'assignee',
					label: t('dossiq', 'Assignee'),
					value: String(this.task?.assignee ?? '').trim(),
				},
				{
					key: 'due',
					label: t('dossiq', 'Due'),
					value: this.formatDate(this.task?.dueAt ?? this.task?.dueDate),
				},
				{
					key: 'team',
					label: t('dossiq', 'Team'),
					value: this.team,
				},
				{
					key: 'priority',
					label: t('dossiq', 'Priority'),
					value: String(this.task?.priority ?? '').trim(),
				},
				{
					key: 'requester',
					label: t('dossiq', 'Requested by'),
					value: String(this.task?.requester ?? '').trim(),
				},
				{
					key: 'expires',
					label: t('dossiq', 'Expires'),
					value: this.formatDate(this.task?.expiresAt),
				},
				{
					key: 'created',
					label: t('dossiq', 'Created'),
					value: this.formatDate(this.task?.created),
				},
			]
			return rows.filter((row) => row.value !== '')
		},

		/**
		 * The team the task is offered to, beside the person holding it.
		 *
		 * The engine's name for this is `candidateGroups`, and it is a list.
		 * `caseTask` spelled the same idea `assignedGroup` and it was a
		 * single value, which is why this is not a rename: a task offered to
		 * two teams has two rows on the engine and had one on the register.
		 *
		 * It is on the page because a handler deciding whether to pick a
		 * task up needs to know whose queue it is in. The Parties change put
		 * it on the case page for the same reason, and the task page carried
		 * it for free while its widget rendered the whole schema.
		 *
		 * @return {string} The teams, comma separated, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		team() {
			const groups = this.task?.candidateGroups
			if (Array.isArray(groups) === true) {
				return groups
					.map((group) => String(group ?? '').trim())
					.filter((group) => group !== '')
					.join(', ')
			}
			return String(groups ?? '').trim()
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
				name: 'complete',
				label: t('dossiq', 'Complete'),
				primary: true,
			})
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
		 * A date in the reader's locale.
		 *
		 * An absent date returns '' so the row is dropped, rather than the em
		 * dash the Data widget rendered for every unreadable field.
		 *
		 * @param {string|null|undefined} value The ISO date-time.
		 * @return {string} The formatted date, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		formatDate(value) {
			const raw = String(value ?? '').trim()
			if (raw === '') {
				return ''
			}
			const parsed = new Date(raw)
			if (Number.isNaN(parsed.getTime())) {
				return raw
			}
			return parsed.toLocaleDateString()
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
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 1200px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__identity {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		min-width: 0;
	}

	&__title {
		margin: 0;
		overflow-wrap: anywhere;
	}

	&__actions {
		display: flex;
		gap: var(--default-grid-baseline);
		flex-wrap: wrap;
	}

	&__section-title {
		margin: 0 0 var(--default-grid-baseline);
		font-size: var(--default-font-size);
	}

	&__description {
		margin: 0;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__facts {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
		gap: calc(var(--default-grid-baseline) * 2);
		margin: 0;
	}

	&__fact {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: var(--default-font-size);
	}

	&__value {
		margin: 0;
		overflow-wrap: anywhere;
	}

	&__checklist-items {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__leaves {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
	}
}
</style>
