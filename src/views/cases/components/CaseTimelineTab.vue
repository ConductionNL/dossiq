<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseTimelineTab — one chronological read of everything said about this
  case: notes, logged calls, inbound and outbound mail, portal messages
  and the acknowledgement of receipt, newest first with pinned entries
  on top.

  It reads OpenRegister's timeline (timeline-entries-are-records, #3762)
  and keeps no store of its own. Notes, Communication and Email stay
  where they are: each is the place to DO that one thing, and this is
  the place to see the order they happened in. The audit sidebar keeps
  the change history.

  THE LIST READ IS FILTERED WHETHER OR NOT WE ASK. A reader without
  `update` on the case is served the public view even with no
  `visibility` parameter. The response says which filter was applied and
  whether the reader may manage, so the visibility control and the pin
  and follow-up buttons are drawn from `canManage` rather than guessed.

  A FAILED READ IS NOT AN EMPTY CASE. An outage and a case nobody has
  said anything about look identical from the browser, and only one of
  them is safe to present as "nothing happened yet".

  @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
-->
<template>
	<div class="case-timeline" data-testid="case-timeline">
		<form
v-if="canManage"
			class="case-timeline__composer"
			data-testid="case-timeline-composer"
			@submit.prevent="submit">
			<NcSelect
v-if="textBlocks.length"
				v-model="chosenBlock"
				class="case-timeline__filter"
				:options="blockOptions"
				:clearable="true"
				label="label"
				:inputLabel="t('dossiq', 'Standard note')"
				:placeholder="t('dossiq', 'Write your own')"
				data-testid="case-timeline-block"
				@input="pickBlock" />

			<label class="case-timeline__draft-label" for="case-timeline-draft">
				{{ t('dossiq', 'Write a note on this case') }}
			</label>
			<textarea
				id="case-timeline-draft"
				v-model="draft"
				class="case-timeline__draft"
				:placeholder="t('dossiq', 'Write a note on this case')"
				data-testid="case-timeline-draft" />

			<NcCheckboxRadioSwitch
v-model="draftIsPublic"
				data-testid="case-timeline-draft-public">
				{{ t('dossiq', 'Visible to the applicant') }}
			</NcCheckboxRadioSwitch>

			<NcButton
variant="primary"
				type="submit"
				:disabled="saving || !draft.trim()"
				data-testid="case-timeline-save">
				{{ t('dossiq', 'Add to the timeline') }}
			</NcButton>
		</form>

		<div class="case-timeline__controls">
			<NcSelect
v-model="kindFilter"
				class="case-timeline__filter"
				:options="kindOptions"
				:clearable="true"
				label="label"
				:inputLabel="t('dossiq', 'Kind')"
				:placeholder="t('dossiq', 'Every kind')"
				data-testid="case-timeline-kind-filter"
				@input="load" />

			<NcSelect
v-if="canManage"
				v-model="visibilityFilter"
				class="case-timeline__filter"
				:options="visibilityOptions"
				:clearable="true"
				label="label"
				:inputLabel="t('dossiq', 'Visibility')"
				:placeholder="t('dossiq', 'Internal and public')"
				data-testid="case-timeline-visibility-filter"
				@input="load" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard
v-else-if="error"
			type="error"
			data-testid="case-timeline-error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
v-else-if="entries.length === 0"
			:name="t('dossiq', 'Nothing recorded yet')"
			:description="t('dossiq', 'Notes, calls and messages on this case appear here in the order they happened.')"
			data-testid="case-timeline-empty" />

		<ul v-else class="case-timeline__list" data-testid="case-timeline-list">
			<li
v-for="entry in entries"
				:key="entry.id"
				class="case-timeline__entry"
				:class="{ 'case-timeline__entry--pinned': entry.pinned }"
				:data-kind="entry.kind || 'note'"
				data-testid="case-timeline-entry">
				<div class="case-timeline__head">
					<span class="case-timeline__kind">{{ kindLabel(entry) }}</span>
					<span class="case-timeline__author">{{ entry.author }}</span>
					<span class="case-timeline__moment">{{ moment(entry.created) }}</span>
					<span
v-if="entry.visibility === 'public'"
						class="case-timeline__badge"
						data-testid="case-timeline-public">
						{{ t('dossiq', 'Visible to the applicant') }}
					</span>
					<span
v-if="entry.followUp === 'open'"
						class="case-timeline__badge case-timeline__badge--open"
						data-testid="case-timeline-followup-open">
						{{ t('dossiq', 'Needs an answer') }}
					</span>
				</div>

				<p class="case-timeline__message">{{ entry.message }}</p>

				<p
v-if="entry.siblings && entry.siblings.length"
					class="case-timeline__siblings"
					data-testid="case-timeline-siblings">
					{{ n('dossiq', 'Also written on %n other case', 'Also written on %n other cases', entry.siblings.length) }}
				</p>

				<div v-if="canManage" class="case-timeline__actions">
					<NcButton
variant="tertiary"
						:data-testid="'case-timeline-pin-' + entry.id"
						@click="togglePin(entry)">
						{{ entry.pinned ? t('dossiq', 'Unpin') : t('dossiq', 'Pin') }}
					</NcButton>
					<NcButton
v-if="entry.followUp === 'open'"
						variant="tertiary"
						:data-testid="'case-timeline-followup-' + entry.id"
						@click="closeFollowUp(entry)">
						{{ t('dossiq', 'Mark as answered') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'CaseTimelineTab',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** Case UUID; forwarded by CnObjectSidebar's sharedTabProps. */
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
			entries: [],
			kinds: [],
			loading: false,
			error: '',
			canManage: false,
			kindFilter: null,
			visibilityFilter: null,
			textBlocks: [],
			chosenBlock: null,
			draft: '',
			draftIsPublic: false,
			saving: false,
		}
	},

	computed: {
		/**
		 * The kind filter's options, with the plain note first.
		 *
		 * A note written through the notes tab is an entry with NO kind, so
		 * "Notitie" filters on the absence of one rather than on a declared
		 * `notitie` kind. Declaring such a kind would split one log into two
		 * buckets meaning the same thing.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		kindOptions() {
			return [{ id: '', label: t('dossiq', 'Note') }].concat(
				this.kinds.map((kind) => ({
					id: kind.slug,
					label: kind.title || kind.slug,
				})),
			)
		},

		/**
		 * The seeded standard notes, as picker options.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		blockOptions() {
			return this.textBlocks.map((block) => ({
				id: block.slug,
				label: block.title || block.slug,
			}))
		},

		/**
		 * The visibility filter's two options.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		visibilityOptions() {
			return [
				{ id: 'internal', label: t('dossiq', 'Internal only') },
				{ id: 'public', label: t('dossiq', 'Visible to the applicant') },
			]
		},

		/**
		 * The timeline endpoint for this case.
		 *
		 * @return {string} The url, or '' when the case is not addressed yet.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		timelineUrl() {
			if (!this.objectId || !this.register || !this.schema) {
				return ''
			}
			return generateUrl(
				`${this.apiBase}/objects/${this.register}/${this.schema}/${this.objectId}/timeline`,
			)
		},
	},

	watch: {
		objectId: 'load',
	},

	/**
	 * Read the declarations and the timeline as soon as the tab is on screen.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	mounted() {
		this.loadKinds()
		this.loadTextBlocks()
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Read the declared kinds, so the filter names them as they are
		 * administered rather than as this file happens to spell them.
		 *
		 * @return {Promise<void>} Resolves when the kinds are in.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async loadKinds() {
			try {
				const response = await axios.get(
					generateUrl(`${this.apiBase}/timeline/kinds`),
				)
				this.kinds = response.data?.results || []
			} catch {
				// The list still reads without the filter's labels.
				this.kinds = []
			}
		},

		/**
		 * Read the seeded standard notes.
		 *
		 * @return {Promise<void>} Resolves when the blocks are in.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async loadTextBlocks() {
			try {
				const response = await axios.get(
					generateUrl(`${this.apiBase}/timeline/text-blocks`),
				)
				this.textBlocks = response.data?.results || []
			} catch {
				// Writing your own still works without them.
				this.textBlocks = []
			}
		},

		/**
		 * Note which standard note was picked.
		 *
		 * The BLOCK is sent, not its body pasted into the draft, because
		 * OpenRegister substitutes the case's own values into it on the way
		 * in and leaves a placeholder nothing supplies standing. Pasting the
		 * raw body here would ship the braces to the reader.
		 *
		 * @param {object|null} block The picked option.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		pickBlock(block) {
			this.chosenBlock = block || null
			if (block) {
				const declared = this.textBlocks.find((entry) => entry.slug === block.id)
				this.draft = declared?.title || block.label
			}
		},

		/**
		 * Write the drafted note onto this case.
		 *
		 * @return {Promise<void>} Resolves when the timeline is re-read.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async submit() {
			if (!this.draft.trim() || !this.timelineUrl) {
				return
			}

			this.saving = true
			this.error = ''

			try {
				const payload = {
					visibility: this.draftIsPublic ? 'public' : 'internal',
				}
				if (this.chosenBlock?.id) {
					payload.textBlock = this.chosenBlock.id
				} else {
					payload.message = this.draft
				}

				await axios.post(this.timelineUrl, payload)
				this.draft = ''
				this.chosenBlock = null
				this.draftIsPublic = false
				await this.load()
			} catch {
				this.error = t('dossiq', 'That note could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Read the timeline, applying whichever filters are set.
		 *
		 * @return {Promise<void>} Resolves when the entries are in.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async load() {
			if (!this.timelineUrl) {
				return
			}

			this.loading = true
			this.error = ''

			try {
				const params = {}
				if (this.visibilityFilter?.id) {
					params.visibility = this.visibilityFilter.id
				}

				const response = await axios.get(this.timelineUrl, { params })
				this.canManage = response.data?.canManage === true
				this.entries = this.applyKindFilter(response.data?.results || [])
			} catch {
				this.entries = []
				this.error = t(
					'dossiq',
					'The timeline could not be read. Try again, or ask an administrator to check OpenRegister.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Keep only the entries of the chosen kind.
		 *
		 * The kind filter is applied in the reading rather than sent as a
		 * parameter, because the list endpoint filters on visibility and
		 * paging and a kind parameter it does not read would be dropped in
		 * silence, leaving a filter that appears to work and does nothing.
		 *
		 * @param {Array<object>} results The entries as read.
		 *
		 * @return {Array<object>} The entries to show.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		applyKindFilter(results) {
			if (!this.kindFilter) {
				return results
			}

			const wanted = this.kindFilter.id || ''
			return results.filter((entry) => (entry.kind || '') === wanted)
		},

		/**
		 * The label for one entry's kind, falling back to "Note".
		 *
		 * @param {object} entry The entry.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		kindLabel(entry) {
			if (!entry.kind) {
				return t('dossiq', 'Note')
			}

			const declared = this.kinds.find((kind) => kind.slug === entry.kind)
			return declared?.title || entry.kind
		},

		/**
		 * One entry's moment, in the reader's own locale.
		 *
		 * @param {string} value An ISO 8601 stamp.
		 *
		 * @return {string} The formatted moment, or the raw value.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		moment(value) {
			if (!value) {
				return ''
			}

			const parsed = new Date(value)
			if (isNaN(parsed.getTime())) {
				return value
			}

			return parsed.toLocaleString()
		},

		/**
		 * Pin an entry, or unpin it.
		 *
		 * @param {object} entry The entry.
		 *
		 * @return {Promise<void>} Resolves when the entry is rewritten.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async togglePin(entry) {
			await this.patch(entry, { pinned: !entry.pinned })
		},

		/**
		 * Close an entry's follow-up.
		 *
		 * @param {object} entry The entry.
		 *
		 * @return {Promise<void>} Resolves when the entry is rewritten.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async closeFollowUp(entry) {
			await this.patch(entry, { followUp: 'done' })
		},

		/**
		 * Write one change onto one entry and read the timeline back.
		 *
		 * Read back rather than patched in place: pinning changes the ORDER,
		 * and a pinned entry that stays where it was looks like a click that
		 * did nothing.
		 *
		 * @param {object} entry   The entry.
		 * @param {object} changes The fields to write.
		 *
		 * @return {Promise<void>} Resolves when the timeline is re-read.
		 *
		 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
		 */
		async patch(entry, changes) {
			try {
				await axios.patch(`${this.timelineUrl}/${entry.id}`, changes)
				await this.load()
			} catch {
				this.error = t('dossiq', 'That change could not be saved.')
			}
		},
	},
}
</script>

<style scoped>
.case-timeline__composer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-end: 16px;
}

.case-timeline__draft-label {
	font-weight: bold;
}

.case-timeline__draft {
	width: 100%;
	min-height: 70px;
}

.case-timeline__controls {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 12px;
}

.case-timeline__filter {
	min-width: 200px;
}

.case-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-timeline__entry {
	border-block-end: 1px solid var(--color-border);
	padding-block: 12px;
}

.case-timeline__entry--pinned {
	border-inline-start: 3px solid var(--color-primary-element);
	padding-inline-start: 8px;
}

.case-timeline__head {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: baseline;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-timeline__kind {
	font-weight: bold;
	color: var(--color-main-text);
}

.case-timeline__badge {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 0 6px;
}

.case-timeline__badge--open {
	border-color: var(--color-warning);
	color: var(--color-warning-text);
}

.case-timeline__message {
	margin-block: 4px 0;
	white-space: pre-wrap;
}

.case-timeline__siblings {
	margin-block: 4px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-timeline__actions {
	display: flex;
	gap: 8px;
	margin-block-start: 4px;
}
</style>
