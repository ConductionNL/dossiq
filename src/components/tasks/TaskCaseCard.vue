<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The case a task is on, as a card (task-on-the-case, REQ-TASK-015).

  This replaces TaskCaseLink, which rendered the same relationship as one
  flat line: the word "Case" and a link. That line answered "which case" and
  nothing else, so a handler who opened a task from a notification still had
  to load the case to learn whether it was urgent, who owned it, or when it
  was due. Every one of those facts is on the case record already.

  WHY A CARD AND NOT A DATA WIDGET OVER `caseTask.case`
  -----------------------------------------------------
  `case` is a $ref and the platform renders a $ref as its uuid, which reads
  as broken data rather than as a way back. That was true of the line and it
  is still true here. The card resolves the reference itself, and resolves
  `case.caseType` and `case.status` too, because those are $refs in turn and
  a uuid tells a handler nothing.

  Three cached reads, not one. `fetchObject` has no `_extend`, so the case,
  its type and its status are three calls. They are de-duplicated per (store,
  type, id) by the store and served from cache on every later render, which
  is why this is affordable on a page that already fetches the task.

  A task with no case renders NOTHING: not an empty card, not a titled box.
  Its layout entry carries `showTitle: false` for the same reason. That is
  the behaviour TaskCaseLink had and the one the spec asks for.

  This stays a SECOND component beside TaskWaitingCaseSection. The two answer
  different questions: that one says "a suspended run is waiting on you" and
  renders only for a task with a `flowRun`; this one says "this task is on
  that case" and renders for every task that has a case. Folding them
  together would either make the waiting claim on ordinary tasks, which is
  untrue, or hide the case on them, which is the gap being closed.

  @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
-->
<template>
	<div v-if="caseId" class="task-case-card" data-testid="task-case-card">
		<div class="task-case-card__head">
			<div class="task-case-card__identity">
				<span
					v-if="identifier"
					class="task-case-card__identifier"
					data-testid="task-case-card-identifier">
					{{ identifier }}
				</span>
				<router-link
					class="task-case-card__link"
					:to="caseRoute"
					data-testid="task-case-link-link">
					{{ caseLabel }}
				</router-link>
			</div>
			<CnStatusBadge
				v-if="statusLabel"
				:label="statusLabel"
				size="small"
				data-testid="task-case-card-status" />
		</div>

		<dl v-if="facts.length > 0" class="task-case-card__facts">
			<div v-for="fact in facts" :key="fact.key" class="task-case-card__fact">
				<dt class="task-case-card__label">
					{{ fact.label }}
				</dt>
				<dd
					class="task-case-card__value"
					:data-testid="`task-case-card-${fact.key}`">
					{{ fact.value }}
				</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { caseIdFrom, caseRouteFor } from '../../utils/flowTaskHelpers.js'

export default {
	name: 'TaskCaseCard',

	components: { CnStatusBadge },

	// The page slot binds the whole detail context (`item`, `widget`,
	// `register`, `schema`, …). Only two of those are read here; without this
	// the rest would be stringified onto the root element.
	inheritAttrs: false,

	props: {
		/** The task this page is showing, bound by the detail surface. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * The loaded task, when the surface already has it. Null on the first
		 * render, which is why `objectId` is the one that is always usable.
		 */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			/** The task, read here when the surface has not supplied it. */
			fetchedTask: null,
			/** The case this task is on, best-effort. */
			caseObject: null,
			/** The case type's title, best-effort. */
			caseTypeLabel: '',
			/** The status type's title, best-effort. */
			statusLabel: '',
		}
	},

	computed: {
		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The task to read the case reference off: the surface's copy when it
		 * has one, otherwise the one fetched here.
		 *
		 * @return {object|null} The task.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		task() {
			return this.objectData ?? this.fetchedTask
		},

		/**
		 * The case this task is on, or null.
		 *
		 * Unlike TaskWaitingCaseSection this does NOT require a `flowRun`: an
		 * ordinary to-do is on a case just as much as a flow task is, and it is
		 * the ordinary one that had no way back.
		 *
		 * @return {string|null} The case id.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		caseId() {
			return caseIdFrom(this.task?.case)
		},

		/** @spec openspec/changes/task-on-the-case/specs/task-management/spec.md */
		caseRoute() {
			return caseRouteFor(this.caseId)
		},

		/**
		 * The case's own reference number, when it has one.
		 *
		 * @return {string} The identifier, or ''.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		identifier() {
			return String(this.caseObject?.identifier ?? '').trim()
		},

		/**
		 * The link text: the case's title, falling back to a plain phrase so a
		 * case whose title cannot be read is still reachable.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		caseLabel() {
			const title = String(this.caseObject?.title ?? '').trim()
			return title || t('dossiq', 'Open the case')
		},

		/**
		 * The facts worth carrying over from the case, in reading order.
		 *
		 * Only what is present: a case with no handler and no deadline shows
		 * neither row rather than two em dashes. An empty list hides the whole
		 * definition list, so a bare case still renders as a clean header.
		 *
		 * @return {Array<{key: string, label: string, value: string}>} The rows.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		facts() {
			const rows = [
				{
					key: 'type',
					label: t('dossiq', 'Case type'),
					value: this.caseTypeLabel,
				},
				{
					key: 'handler',
					label: t('dossiq', 'Handler'),
					value: this.handler,
				},
				{
					key: 'deadline',
					label: t('dossiq', 'Case deadline'),
					value: this.deadline,
				},
			]
			return rows.filter((row) => row.value !== '')
		},

		/**
		 * Who owns the case, which is not always who owns the task.
		 *
		 * @return {string} The handler, or ''.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		handler() {
			return String(this.caseObject?.assignee ?? '').trim()
		},

		/**
		 * The case's own deadline, formatted for reading.
		 *
		 * The statutory deadline where the case has one, the planned end date
		 * otherwise. Deliberately the CASE's clock and not the task's: the task
		 * carries its own due date in the data widget right below, and showing
		 * the same date twice would suggest they are always the same.
		 *
		 * @return {string} The formatted date, or ''.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		deadline() {
			const raw = this.caseObject?.deadline ?? this.caseObject?.plannedEndDate
			const value = String(raw ?? '').trim()
			if (value === '') {
				return ''
			}
			const parsed = new Date(value)
			if (Number.isNaN(parsed.getTime())) {
				return value
			}
			return parsed.toLocaleDateString()
		},
	},

	watch: {
		caseId: {
			immediate: false,
			/**
			 * The task resolved to a different case, so the card has to be read
			 * again. Without this it would carry the new case's link under the
			 * previous case's facts, which is worse than no facts.
			 *
			 * Non-immediate: `mounted()` does the first read, after the stores
			 * have resolved.
			 *
			 * @return {void}
			 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
			 */
			handler() {
				this.loadCase()
			},
		},
	},

	/**
	 * Resolve the stores, then the task and its case.
	 *
	 * CnAppRoot mounts manifest slot widgets before App.vue's
	 * initializeStores() has resolved the app-config, so the object types may
	 * not be registered yet: await it here (idempotent), the same pattern
	 * TaskWaitingCaseSection and InitiatorSection use.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
	 */
	async mounted() {
		await initializeStores()
		await this.loadTask()
		await this.loadCase()
	},

	methods: {
		/**
		 * Read the task, unless the surface already handed it over.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		async loadTask() {
			if (this.objectData) {
				return
			}
			const taskId = String(this.objectId ?? '').trim()
			if (taskId === '') {
				return
			}
			try {
				this.fetchedTask =
					(await this.objectStore.fetchObject('caseTask', taskId)) || null
			} catch {
				// An unreadable task renders nothing, same as a task with no case.
				this.fetchedTask = null
			}
		},

		/**
		 * Read the case and the two references it carries, best-effort.
		 *
		 * A failed read still renders the link: the relationship is a fact of
		 * the task, and the facts are only a fuller label for it. Each
		 * reference is resolved on its own so one unreadable status type does
		 * not cost the case type as well.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		async loadCase() {
			const caseId = this.caseId
			if (!caseId) {
				this.caseObject = null
				this.caseTypeLabel = ''
				this.statusLabel = ''
				return
			}
			try {
				this.caseObject =
					(await this.objectStore.fetchObject('case', caseId)) || null
			} catch {
				this.caseObject = null
			}
			this.caseTypeLabel = await this.labelOf(
				'caseType',
				this.caseObject?.caseType,
			)
			this.statusLabel = await this.labelOf(
				'statusType',
				this.caseObject?.status,
			)
		},

		/**
		 * The label behind a reference, or '' when it cannot be read.
		 *
		 * `title` OR `name`, because the two schemas this resolves disagree:
		 * the deployed `caseType` carries `title`, the deployed `statusType`
		 * carries `name`. Reading only `title` renders no status badge at all,
		 * silently, which is what shipped until the live page was opened.
		 * tests/e2e/helpers/fixtures.ts documents the same divergence.
		 *
		 * @param {string} type The registered type slug.
		 * @param {string|object|null|undefined} ref The reference.
		 * @return {Promise<string>} The label.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		async labelOf(type, ref) {
			const id = caseIdFrom(ref)
			if (!id) {
				return ''
			}
			try {
				const object = await this.objectStore.fetchObject(type, id)
				return String(object?.title ?? object?.name ?? '').trim()
			} catch {
				return ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.task-case-card {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__identity {
		display: flex;
		align-items: baseline;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
		min-width: 0;
	}

	&__identifier {
		color: var(--color-text-maxcontrast);
		font-size: var(--default-font-size);
	}

	&__link {
		color: var(--color-primary-element);
		font-weight: bold;
		text-decoration: underline;
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
}
</style>
