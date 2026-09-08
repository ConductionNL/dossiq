<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The way back from a task to its case (task-on-the-case, REQ-TASK-015).

  A task page names its assignee, its due date and its status, and never the
  case it is on. The one field that says so, `caseTask.case`, is a $ref, and
  the platform renders a $ref as its uuid. So the way back was the browser
  Back button, which is not a way back at all once the task was reached from a
  list or a notification.

  This is deliberately a SECOND component beside TaskWaitingCaseSection rather
  than an extension of it. The two answer different questions: that one says
  "a suspended run is waiting on you" and renders for a task with a `flowRun`
  and for no other, which is the whole point of it. This one says "this task
  is on that case" and renders for every task that has a case. Folding them
  together would either make the waiting claim on ordinary tasks, which is
  untrue, or hide the case link on them, which is the gap being closed.

  A task with no case renders NOTHING: not an empty box, not a titled card.
  Its layout entry carries `showTitle: false` for the same reason.

  @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
-->
<template>
	<div v-if="caseId" class="task-case-link" data-testid="task-case-link">
		<span class="task-case-link__label">{{ t('dossiq', 'Case') }}</span>
		<router-link
			class="task-case-link__link"
			:to="caseRoute"
			data-testid="task-case-link-link">
			{{ caseLabel }}
		</router-link>
	</div>
</template>

<script>
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { caseIdFrom, caseRouteFor } from '../../utils/flowTaskHelpers.js'

export default {
	name: 'TaskCaseLink',

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
			/** The case's title, best-effort. */
			caseTitle: '',
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
		 * The link text: the case's title, falling back to a plain phrase so a
		 * case whose title cannot be read is still reachable.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		caseLabel() {
			return this.caseTitle || t('dossiq', 'Open the case')
		},
	},

	watch: {
		caseId: {
			immediate: false,
			/**
			 * The task resolved to a different case, so its title has to be
			 * read again. Without this the link would carry the new case's id
			 * under the previous case's title, which is worse than no title.
			 *
			 * Non-immediate: `mounted()` does the first read, after the stores
			 * have resolved.
			 *
			 * @return {void}
			 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
			 */
			handler() {
				this.loadCaseTitle()
			},
		},
	},

	/**
	 * Resolve the stores, then the task and its case title.
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
		await this.loadCaseTitle()
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
		 * Read the case's title, best-effort.
		 *
		 * A failed read still renders the link: the relationship is a fact of
		 * the task, and the title is only a nicer label for it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
		 */
		async loadCaseTitle() {
			const caseId = this.caseId
			if (!caseId) {
				this.caseTitle = ''
				return
			}
			try {
				const caseObject = await this.objectStore.fetchObject('case', caseId)
				this.caseTitle = String(caseObject?.title ?? '').trim()
			} catch {
				this.caseTitle = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.task-case-link {
	display: flex;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);

	&__label {
		color: var(--color-text-maxcontrast);
	}

	&__link {
		color: var(--color-primary-element);
		text-decoration: underline;
	}
}
</style>
