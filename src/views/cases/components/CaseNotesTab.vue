<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseNotesTab — sidebar tab wired as `component:` on the case-detail
  sidebar so that notes typed here go through the library's full
  `CnNotesTab` (not the compact `CnNotesCard` used for the existing
  "case-notes" body-grid widget). `CnNotesCard` predates the @mention
  feature (nc-vue #207) and only emits `note-added` / `note-deleted` /
  `show-all` — `CnNotesTab` is the component that actually parses
  `@mention` tokens and emits a `mention` event, so a mention-aware
  surface requires this tab, not the grid widget.

  Two things live here and nothing else, and both are things the
  library cannot know about: dossiq's own NC notification for an
  @mention, and the one act that sends a single note to a neighbouring
  ZGW register. Note storage stays OpenRegister's entirely (ADR-022)
  and the notes UI stays the library's (ADR-Leaf-First); `CnNotesTab`
  owns storage, autocomplete and chip rendering end to end.

  The push action is why nc-vue grew `noteActions`. `notes#push` was
  routed, guarded and tested on 2026-09-20 and no page could call it,
  because the library's notes tab rendered its own edit, history and
  delete actions and offered no place for a fourth. The change that
  built the endpoint wrote that down rather than declaring a prop
  nothing read. `noteActions` (nc-vue 2.56.0) is that place: one entry
  goes down, a `note-action` event comes back with the whole note, and
  `onNoteAction` POSTs it.

  Zero note/mention logic is reimplemented here. The mention half
  listens for the `mention` event and forwards its payload
  ({ objectId, register, schema, noteId, mentionedUserIds }) to
  POST /api/notes/mention. Resolves `CnNotesTab` via the existing
  `leafTab()` helper (see src/integrations/leafTabs.js) — the same
  mechanism already used for the calendar/forms/photos/maps leaf tabs
  — because `CnNotesTab` is not exported from the package root; it is
  only reachable through the integration registry's `notes` descriptor.

  Registered in src/registry.js as `CaseNotesTab` and wired as a
  `component:` sidebar tab (alongside the "audit" widgets-tab) on
  CaseDetail in src/manifest.json.

  @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
-->
<template>
	<!-- `CnNotesTabComponent` is a component OBJECT held in data, not a
	     registered component. A `<CnNotesTabComponent>` tag is resolved
	     against the registered components only, so Vue emitted a literal
	     unknown `<cnnotestabcomponent>` element and the tab stayed empty;
	     `<component :is>` takes the object itself. -->
	<component
		:is="CnNotesTabComponent"
		v-if="CnNotesTabComponent"
		:objectId="objectId"
		:register="register"
		:schema="schema"
		:apiBase="apiBase"
		:noteActions="noteActions"
		@mention="onMention"
		@noteAction="onNoteAction" />
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import { leafTab } from '../../../integrations/leafTabs.js'
import logger from '../../../logger.js'

/**
 * The id of the one action this tab adds to a note.
 *
 * Shared by the entry and the handler on purpose: the handler ignores
 * anything else, so a second action added here later cannot silently
 * inherit the push.
 */
const PUSH_ACTION = 'push-to-neighbouring-register'

export default {
	name: 'CaseNotesTab',

	props: {
		/** Object UUID; forwarded by CnObjectSidebar's sharedTabProps. */
		objectId: {
			type: String,
			default: '',
		},

		/** OpenRegister register slug; forwarded by sharedTabProps. */
		register: {
			type: String,
			default: '',
		},

		/** OpenRegister schema slug; forwarded by sharedTabProps. */
		schema: {
			type: String,
			default: '',
		},

		/** OpenRegister API base; forwarded by sharedTabProps. */
		apiBase: {
			type: String,
			default: '/apps/openregister/api',
		},
	},

	data() {
		return {
			// Resolved once — the integration registry is populated at
			// library bootstrap time, well before this tab ever mounts.
			CnNotesTabComponent: leafTab('notes'),

			// The one act this app adds to a note the library shows.
			//
			// SHOWN ON EVERY NOTE, and that is the endpoint's own design
			// rather than an oversight. `NotePush` answers `no-register` on
			// an instance bound to nothing and `not-sent` on an internal
			// note, each with the sentence saying why, and it records the
			// ones that matter on the case. Hiding the action until the
			// frontend can prove a connector exists would mean asking a
			// question no endpoint answers, and guessing would hide the
			// action on exactly the instances where it works.
			noteActions: [
				{
					id: PUSH_ACTION,
					label: t('dossiq', 'Send to the neighbouring register'),
					icon: SendOutline,
				},
			],
		}
	},

	methods: {
		/**
		 * Forward a saved note's mentions to dossiq's own notification
		 * endpoint. Best-effort: the note itself is already saved by
		 * `CnNotesTab` at this point, so a failed notification must never
		 * surface as an error to the user typing the note.
		 *
		 * @param {object} payload `{ objectId, register, schema, noteId, mentionedUserIds }`
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		async onMention(payload) {
			try {
				await axios.post(
					generateUrl('/apps/dossiq/api/notes/mention'),
					payload,
				)
			} catch (e) {
				logger.warn(
					'CaseNotesTab: failed to dispatch mention notification',
					{ error: e },
				)
			}
		},

		/**
		 * Send the note the reader picked to the neighbouring register.
		 *
		 * THIS IS THE ONLY CALLER OF `notes#push`. The endpoint, its mutation
		 * guard, its route and its tests all shipped on 2026-09-20 and
		 * `tests/e2e/note-sync.spec.ts` was the only thing in the repository
		 * that ever called it, which is a suite around a door nobody could
		 * open. The blocker was real and it was recorded: the library's notes
		 * tab carried no per-note seam, so nc-vue grew a `noteActions` prop
		 * and this passes one entry through it.
		 *
		 * Unlike `onMention`, this is NOT best effort. The reader deliberately
		 * asked for a note to leave the municipality, so every answer is said
		 * out loud, including the ones that are neither a success nor a
		 * failure.
		 *
		 * @param {object} payload           `{ action, note }` from CnNotesTab.
		 * @param {string} payload.action    The action id that was clicked.
		 * @param {object} payload.note      The whole note, as OpenRegister answered it.
		 *
		 * @spec openspec/specs/zgw-api-mapping/spec.md
		 */
		async onNoteAction({ action, note }) {
			if (action !== PUSH_ACTION || !this.objectId) {
				return
			}

			try {
				const response = await axios.post(
					generateUrl('/apps/dossiq/api/cases/{caseId}/notes/push', {
						caseId: this.objectId,
					}),
					{ note },
				)
				this.sayWhatBecameOfIt(response.data || {})
			} catch (e) {
				logger.error('CaseNotesTab: a note push failed', { error: e })
				showError(t('dossiq', 'The note could not be sent'))
			}
		},

		/**
		 * Say what became of a pushed note.
		 *
		 * The server's own `reason` wherever it gave one, because it names the
		 * thing to fix: which config key is unset, which field the receiver
		 * rejected, or that the case could not record the outcome. A sentence
		 * of ours in its place would be shorter and would tell the reader
		 * nothing they can act on.
		 *
		 * @param {object} outcome The endpoint's answer.
		 *
		 * @spec openspec/specs/zgw-api-mapping/spec.md
		 */
		sayWhatBecameOfIt(outcome) {
			const reason = String(outcome.reason || '').trim()

			if (outcome.outcome === 'sent') {
				showSuccess(
					reason || t('dossiq', 'Note sent to the neighbouring register'),
				)
				return
			}

			if (outcome.outcome === 'failed') {
				showError(reason || t('dossiq', 'The note could not be sent'))
				return
			}

			// `no-register` and `not-sent`: nothing went wrong and nothing
			// left either, and both always carry their own sentence.
			showWarning(reason || t('dossiq', 'This note stays here'))
		},
	},
}
</script>
