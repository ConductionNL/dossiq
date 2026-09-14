<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The task's history, from the engine's own append-only audit.

  WHY NOT `CnAuditTrailWidget`
  ---------------------------
  The sidebar tab this replaces declared `{ type: "audit" }`, which resolves
  to the library's CnAuditTrailWidget. Checked against the installed
  @conduction/nextcloud-vue 2.46.0: it resolves `register`, `schema` and
  `objectId` from its props or the injected detail context and reads
  OpenRegister's OBJECT audit trail. An engine task has no register, no
  schema and no object id, so the widget resolves an empty context and shows
  nothing — with no error, because "no audit entries" is a legitimate answer
  for a brand new object.

  The engine keeps its own trail and serves it at
  `GET /api/flow-tasks/{uuid}/audit`, oldest first, as `{ results }`. Each
  entry is `{ id, taskId, action, stateAfter, actor, performerType,
  onBehalfOf, mandate, reason, authorized, created }`. That is a RICHER
  record than the object trail was: it names the performer type and whether
  the action was authorized, which the object audit has no concept of.

  WHY IT IS A SECTION AND NOT A SIDEBAR TAB
  -----------------------------------------
  It was a sidebar tab, and a `type: "custom"` page has no sidebar to put it
  in: CnPageRenderer mounts the component and nothing else, so the sidebar
  config a detail page carries is not read at all (verified in
  CnPageRenderer's `resolvedComponent` / `resolvedProps`, 2.46.0). Rendering
  it as the last section of the page keeps the content and drops only the
  drawer. It stays collapsed by default so it costs nothing to a handler who
  came to finish the task.

  The `version-history` tab does NOT come across, and that is not an
  oversight. It rendered OpenRegister object versions, and an engine task has
  none: it is not an object and nothing writes a version of it. Keeping the
  tab would have been a drawer that is always empty.

  @spec openspec/specs/task-management/spec.md
-->
<template>
	<section class="task-audit-leaf" data-testid="task-audit-leaf">
		<details class="task-audit-leaf__disclosure">
			<summary
				class="task-audit-leaf__summary"
				data-testid="task-audit-leaf-toggle"
				@click="loadOnce">
				{{ t('dossiq', 'History') }}
			</summary>

			<p
				v-if="error"
				class="task-audit-leaf__error"
				data-testid="task-audit-leaf-error">
				{{ error }}
			</p>

			<ol v-else-if="entries.length > 0" class="task-audit-leaf__list">
				<li
					v-for="entry in entries"
					:key="entry.id"
					class="task-audit-leaf__entry"
					data-testid="task-audit-leaf-entry">
					<span class="task-audit-leaf__when">{{ whenOf(entry) }}</span>
					<span class="task-audit-leaf__what">{{ describe(entry) }}</span>
					<span v-if="entry.reason" class="task-audit-leaf__reason">
						{{ entry.reason }}
					</span>
				</li>
			</ol>

			<p
				v-else-if="loaded"
				class="task-audit-leaf__empty"
				data-testid="task-audit-leaf-empty">
				{{ t('dossiq', 'Nothing has happened to this task yet') }}
			</p>
		</details>
	</section>
</template>

<script>
import { useEngineTaskStore } from '../../store/modules/engineTask.js'

export default {
	name: 'TaskAuditLeaf',

	props: {
		/** The engine task whose history this is. */
		taskId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** The audit entries, oldest first. */
			entries: [],
			/** Whether the read has completed, so an empty list can say so. */
			loaded: false,
			/** The last failure, rendered in place. */
			error: '',
		}
	},

	watch: {
		taskId: {
			immediate: false,
			/**
			 * The page re-bound to another task: drop the previous task's
			 * history rather than showing it under the new task's heading,
			 * and read again only if the reader had it open.
			 *
			 * @return {void}
			 * @spec openspec/specs/task-management/spec.md
			 */
			handler() {
				const wasLoaded = this.loaded
				this.entries = []
				this.error = ''
				this.loaded = false
				if (wasLoaded === true) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Read the history the first time the reader opens the disclosure.
		 *
		 * Deferred on purpose. The history is the one part of this page a
		 * handler finishing a task never looks at, and it is a second request
		 * per task view; the notes and the appointments are not deferred
		 * because both are open on the page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async loadOnce() {
			if (this.loaded === true) {
				return
			}
			await this.load()
		},

		/**
		 * Read the task's audit trail.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async load() {
			const id = String(this.taskId ?? '').trim()
			if (id === '') {
				return
			}

			const outcome = await useEngineTaskStore().readLeaf(id, 'audit')
			this.entries = outcome.results
			this.error = outcome.error ?? ''
			this.loaded = true
		},

		/**
		 * One entry as a sentence.
		 *
		 * The action and the state it left the task in, plus who did it. An
		 * entry the engine recorded as UNAUTHORIZED says so: a refused verb
		 * is written to the trail too, and rendering it identically to a
		 * successful one would make the history claim things happened that
		 * did not.
		 *
		 * Assembled from flat parts rather than nested `t()` calls. A
		 * translated string interpolated into another translated string
		 * gives a translator no sentence to work with, and the parts here
		 * are each a whole phrase.
		 *
		 * @param {object} entry The audit entry.
		 * @return {string} The sentence.
		 * @spec openspec/specs/task-management/spec.md
		 */
		describe(entry) {
			const action = String(entry?.action ?? '').trim()
			const state = String(entry?.stateAfter ?? '').trim()
			const actor = String(entry?.actor ?? '').trim()

			const parts = [state === '' ? action : `${action} (${state})`]

			if (actor !== '') {
				parts.push(t('dossiq', 'by {actor}', { actor }))
			}

			if (entry?.authorized === false) {
				parts.push(t('dossiq', '(refused)'))
			}

			return parts.join(' ')
		},

		/**
		 * When an entry was recorded, in the reader's locale.
		 *
		 * @param {object} entry The audit entry.
		 * @return {string} The formatted timestamp, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		whenOf(entry) {
			const raw = String(entry?.created ?? '').trim()
			if (raw === '') {
				return ''
			}
			const parsed = new Date(raw)
			if (Number.isNaN(parsed.getTime())) {
				return raw
			}
			return parsed.toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
.task-audit-leaf {
	&__summary {
		cursor: pointer;
		font-weight: bold;
		padding: var(--default-grid-baseline) 0;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		margin: 0;
		padding: calc(var(--default-grid-baseline) * 2) 0 0;
		list-style: none;
	}

	&__entry {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__when,
	&__reason,
	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error);
	}
}
</style>
