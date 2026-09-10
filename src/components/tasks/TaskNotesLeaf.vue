<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The notes leaf on the task page, anchored on the TASK.

  WHY THIS IS A DOSSIQ COMPONENT AND NOT THE `notes` INTEGRATION WIDGET
  ---------------------------------------------------------------------
  The manifest used to declare `type: "integration", integrationId: "notes"`,
  which resolves to the library's CnNotesCard. That card builds one URL and
  only one:

      `${apiBase}/objects/${registerId}/${schemaId}/${objectId}/notes`

  Checked against the installed @conduction/nextcloud-vue 2.46.0 source, in
  all three of its methods. An engine task is not an OpenRegister object, so
  there is no register, no schema and no object id to put in that URL, and
  the card cannot be pointed anywhere else: `apiBase` is a prefix, not a
  template. The leaf therefore has to be read from the task's own endpoint,
  which is what this component does. The day CnNotesCard grows an entity
  mode, this file is deleted and the widget goes back to
  `type: "integration"`.

  WHAT WAS ACTUALLY ON SCREEN BEFORE
  ----------------------------------
  Not notes. The page bound the leaf to `register: dossiq, schema: caseTask`
  with the ROUTE id as the object, and the route id is an engine uuid: the
  object read answers nothing and the card renders its empty state. So the
  notes a handler wrote against a task were unreachable from the task, with
  no error to say why. That is the defect this closes, not a new feature.

  THE ENDPOINT IS OPENREGISTER'S, NOT DOSSIQ'S
  --------------------------------------------
  `GET/POST /api/flow-tasks/{uuid}/notes` and
  `DELETE /api/flow-tasks/{uuid}/notes/{noteId}` (ADR-022: dossiq consumes an
  OpenRegister abstraction and writes no note storage of its own). A note is
  `{ id, message, actorType, actorId, actorDisplayName, createdAt,
  isCurrentUser }` — `message`, not `content`; `actorDisplayName`, not
  `author`. The list arrives as `{ results, total }`; a created note arrives
  bare.

  🚧 SEAM: EDITING. The endpoint carries `PUT .../notes/{noteId}`, and this
  leaf does not offer it. Adding a note and removing your own are the two
  gestures the task page needs to stop losing work; an inline editor is a
  second interaction model and belongs with a deliberate design pass, not
  with a page retype.

  @spec openspec/specs/task-management/spec.md
-->
<template>
	<section class="task-notes-leaf" data-testid="task-notes-leaf">
		<p
			v-if="error"
			class="task-notes-leaf__error"
			data-testid="task-notes-leaf-error">
			{{ error }}
		</p>

		<ol v-if="notes.length > 0" class="task-notes-leaf__list">
			<li
				v-for="note in notes"
				:key="note.id"
				class="task-notes-leaf__note"
				data-testid="task-notes-leaf-note">
				<div class="task-notes-leaf__head">
					<span class="task-notes-leaf__author">{{ authorOf(note) }}</span>
					<span class="task-notes-leaf__when">{{ whenOf(note) }}</span>
					<NcButton
						v-if="note.isCurrentUser === true"
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('dossiq', 'Delete this note')"
						:data-testid="`task-notes-leaf-delete-${note.id}`"
						@click="remove(note)">
						{{ t('dossiq', 'Delete') }}
					</NcButton>
				</div>
				<p class="task-notes-leaf__message">
					{{ note.message }}
				</p>
			</li>
		</ol>
		<p
			v-else-if="!error"
			class="task-notes-leaf__empty"
			data-testid="task-notes-leaf-empty">
			{{ t('dossiq', 'Nobody has written a note here yet') }}
		</p>

		<div class="task-notes-leaf__composer">
			<NcTextArea
				v-model="draft"
				:label="t('dossiq', 'Add a note')"
				:placeholder="t('dossiq', 'What did you find, who did you call?')"
				:disabled="busy" />
			<NcButton
				variant="primary"
				:disabled="busy || draft.trim() === ''"
				data-testid="task-notes-leaf-add"
				@click="add">
				{{ t('dossiq', 'Add note') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { useEngineTaskStore } from '../../store/modules/engineTask.js'

export default {
	name: 'TaskNotesLeaf',

	components: {
		NcButton,
		NcTextArea,
	},

	props: {
		/** The engine task the notes hang on. */
		taskId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** The notes, oldest first, as the endpoint returns them. */
			notes: [],
			/** The composer's text. */
			draft: '',
			/** Whether a write is in flight, so a double click cannot fire twice. */
			busy: false,
			/**
			 * The last failure, rendered in place.
			 *
			 * Shown rather than toasted: a leaf that failed to load and a leaf
			 * with nothing in it look identical, and the whole point of moving
			 * this endpoint is that the previous version failed silently.
			 */
			error: '',
		}
	},

	watch: {
		taskId: {
			immediate: false,
			/**
			 * The page re-bound to another task.
			 *
			 * Non-immediate: `mounted()` does the first read, so an immediate
			 * handler would issue it twice.
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
	 * Read the notes.
	 *
	 * No `initializeStores()` here, unlike the widgets this page replaces.
	 * That await exists to let the object store register its types before a
	 * read names one, and this leaf reads no object type at all: it asks the
	 * engine for a task's notes by uuid.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/task-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the task's notes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async load() {
			const id = String(this.taskId ?? '').trim()
			if (id === '') {
				this.notes = []
				return
			}

			const outcome = await useEngineTaskStore().readLeaf(id, 'notes')
			this.notes = outcome.results
			this.error = outcome.error ?? ''
		},

		/**
		 * Add the composed note.
		 *
		 * Re-reads rather than pushing the response onto the list: the
		 * endpoint is the ordering authority, and a locally appended row
		 * would sit in the wrong place the moment two people write at once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async add() {
			const text = this.draft.trim()
			if (text === '' || this.busy === true) {
				return
			}

			this.busy = true
			try {
				const outcome = await useEngineTaskStore().writeNote(this.taskId, text)
				if (outcome.note === null) {
					this.error = outcome.error ?? ''
					return
				}
				this.draft = ''
				await this.load()
			} finally {
				this.busy = false
			}
		},

		/**
		 * Remove one of the reader's own notes.
		 *
		 * @param {object} note The note.
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async remove(note) {
			if (this.busy === true) {
				return
			}

			this.busy = true
			try {
				const outcome = await useEngineTaskStore().removeNote(
					this.taskId,
					note?.id,
				)
				if (outcome.removed === false) {
					this.error = outcome.error ?? ''
					return
				}
				await this.load()
			} finally {
				this.busy = false
			}
		},

		/**
		 * Who wrote a note.
		 *
		 * `actorDisplayName` is what the endpoint resolves; `actorId` is the
		 * fallback for an actor whose display name could not be read, which
		 * is still better than an anonymous note.
		 *
		 * @param {object} note The note.
		 * @return {string} The author.
		 * @spec openspec/specs/task-management/spec.md
		 */
		authorOf(note) {
			const name = String(
				note?.actorDisplayName ?? note?.actorId ?? '',
			).trim()
			return name === '' ? t('dossiq', 'Unknown') : name
		},

		/**
		 * When a note was written, in the reader's locale.
		 *
		 * @param {object} note The note.
		 * @return {string} The formatted timestamp, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		whenOf(note) {
			const raw = String(note?.createdAt ?? '').trim()
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
.task-notes-leaf {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__note {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		padding: calc(var(--default-grid-baseline) * 2);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__head {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__author {
		font-weight: bold;
	}

	&__when,
	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__message {
		margin: 0;
		overflow-wrap: anywhere;
		white-space: pre-wrap;
	}

	&__error {
		margin: 0;
		color: var(--color-error);
	}

	&__composer {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		align-items: flex-start;
	}
}
</style>
