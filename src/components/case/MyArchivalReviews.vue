<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The destruction list entries this person has to sign off.

  THE LIST IS NOT NARROWED HERE. `/archival/reviews/pending` reads the session
  user id and answers that person's own undecided entries, so there is no id in
  the request to tamper with and none is sent. Filtering a wider list in the
  browser would be a different, weaker thing wearing the same label.

  EMPTY IS NOT THE SAME AS BROKEN. A failed read draws an error with a retry. A
  reviewer with nothing to sign off is told so. The two look identical from an
  empty array, and only one of them means somebody's work is invisible.

  THREE ANSWERS, EACH WITH A REASON. Retain also asks for the new
  archiefactiedatum, which openregister requires and refuses the decision
  without. Nothing is defaulted: a date this app invented would be recorded as
  the reviewer's own.

  @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
-->
<template>
	<div class="archival-reviews" data-testid="archival-reviews">
		<h3>{{ t('dossiq', 'My archival reviews') }}</h3>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="error"
			data-testid="archival-reviews-error"
			:name="t('dossiq', 'Your archival reviews are unavailable')"
			:description="error">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton data-testid="archival-reviews-retry" @click="load">
					{{ t('dossiq', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="entries.length === 0"
			data-testid="archival-reviews-empty"
			:name="t('dossiq', 'Nothing to sign off')"
			:description="t('dossiq', 'You hold no archival decisions right now.')">
			<template #icon>
				<ArchiveOutline :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="archival-reviews__list">
			<li
				v-for="entry in entries"
				:key="entry.entryId"
				class="archival-reviews__entry"
				:data-testid="`archival-review-${entry.entryId}`">
				<p class="archival-reviews__title">
					{{ entry.title || entry.entryId }}
				</p>
				<p class="archival-reviews__due">
					{{ t('dossiq', 'Disposal date') }}:
					{{ entry.archiefactiedatum || '' }}
				</p>

				<NcSelect
					:modelValue="answerFor(entry)"
					:options="answers"
					:inputLabel="t('dossiq', 'Your answer')"
					:data-testid="`archival-review-answer-${entry.entryId}`"
					@update:modelValue="(v) => setAnswer(entry, v)" />

				<NcTextField
					:modelValue="reasonFor(entry)"
					:label="t('dossiq', 'Why?')"
					:data-testid="`archival-review-reason-${entry.entryId}`"
					@update:modelValue="(v) => setReason(entry, v)" />

				<NcDateTimePickerNative
					v-if="answerFor(entry) === 'retain'"
					:modelValue="dateFor(entry)"
					type="date"
					:label="t('dossiq', 'New disposal date')"
					:data-testid="`archival-review-date-${entry.entryId}`"
					@update:modelValue="(v) => setDate(entry, v)" />

				<NcButton
					:disabled="canAnswer(entry) === false"
					:data-testid="`archival-review-confirm-${entry.entryId}`"
					@click="answer(entry)">
					{{ t('dossiq', 'Record this decision') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import { ANSWERS, decide, pendingReviews } from '../../services/archivalApi.js'

export default {
	name: 'MyArchivalReviews',

	components: {
		AlertCircleOutline,
		ArchiveOutline,
		NcButton,
		NcDateTimePickerNative,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			error: '',
			entries: [],
			drafts: {},
			answers: ANSWERS,
		}
	},

	/**
	 * Read the worklist once the section is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read this person's own pending entries.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				this.entries = await pendingReviews()
			} catch (e) {
				this.error = String(e?.message ?? e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The draft answer held for one entry.
		 *
		 * @param {object} entry The entry.
		 * @return {object} The draft, never undefined.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		draftFor(entry) {
			return this.drafts[entry.entryId] ?? { answer: '', reason: '', date: '' }
		},

		/**
		 * Replace one entry's draft.
		 *
		 * @param {object} entry The entry.
		 * @param {object} patch The fields to change.
		 * @return {void}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		patch(entry, patch) {
			this.drafts = {
				...this.drafts,
				[entry.entryId]: { ...this.draftFor(entry), ...patch },
			}
		},

		/**
		 * @param {object} entry The entry.
		 * @return {string} The chosen answer.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		answerFor(entry) {
			return String(this.draftFor(entry).answer ?? '')
		},

		/**
		 * @param {object} entry The entry.
		 * @return {string} The typed reason.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		reasonFor(entry) {
			return String(this.draftFor(entry).reason ?? '')
		},

		/**
		 * @param {object} entry The entry.
		 * @return {string} The chosen new disposal date.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		dateFor(entry) {
			return String(this.draftFor(entry).date ?? '')
		},

		/**
		 * @param {object} entry The entry.
		 * @param {string} value The chosen answer.
		 * @return {void}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		setAnswer(entry, value) {
			this.patch(entry, { answer: String(value ?? '') })
		},

		/**
		 * @param {object} entry The entry.
		 * @param {string} value The typed reason.
		 * @return {void}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		setReason(entry, value) {
			this.patch(entry, { reason: String(value ?? '') })
		},

		/**
		 * @param {object} entry The entry.
		 * @param {string} value The chosen date.
		 * @return {void}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		setDate(entry, value) {
			this.patch(entry, { date: String(value ?? '') })
		},

		/**
		 * May this draft be sent?
		 *
		 * Every answer needs a reason. Retain also needs the new date, because
		 * openregister refuses a retention without one and meeting that refusal
		 * after the click teaches the reviewer nothing they could not have been
		 * told first.
		 *
		 * @param {object} entry The entry.
		 * @return {boolean} True when openregister will accept it.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		canAnswer(entry) {
			const draft = this.draftFor(entry)
			if (ANSWERS.includes(String(draft.answer)) === false) {
				return false
			}

			if (String(draft.reason ?? '').trim() === '') {
				return false
			}

			return (
				draft.answer !== 'retain' || String(draft.date ?? '').trim() !== ''
			)
		},

		/**
		 * Record one decision, and take the entry off the list.
		 *
		 * The entry leaves on the answer landing, not on a reload: a reviewer
		 * working down a list should not have to find their place again.
		 *
		 * @param {object} entry The entry.
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		async answer(entry) {
			if (this.canAnswer(entry) === false) {
				return
			}

			const draft = this.draftFor(entry)

			try {
				await decide({
					listId: entry.listId,
					entryId: entry.entryId,
					answer: draft.answer,
					reason: String(draft.reason).trim(),
					newArchiefactiedatum:
						draft.answer === 'retain' ? draft.date : null,
				})

				this.entries = this.entries.filter(
					(row) => row.entryId !== entry.entryId,
				)
			} catch (e) {
				this.error = String(e?.message ?? e)
			}
		},
	},
}
</script>

<style scoped>
.archival-reviews__entry {
	border-block-end: 1px solid var(--color-border);
	padding-block: var(--default-grid-baseline, 4px);
}
</style>
