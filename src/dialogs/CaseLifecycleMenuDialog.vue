<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One menu holding every lifecycle act on the case.

  The acts used to sit in three places: four header actions, the transitions
  the stages widget drew, and a delete behind its own button. Each was gated
  differently, so a handler found out what they could do by trying. This is the
  same three server answers rendered in one list.

  An act the handler may not perform is SHOWN and disabled with the reason. It
  is never hidden. A hidden act teaches nobody why, and the reason is what
  tells the handler who to ask.

  The menu decides nothing. Every verdict and every sentence in it is copied
  from a server answer, because a second derivation would eventually offer a
  move the write refuses.

  @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'What you can do with this case')"
		data-testid="case-acts-dialog"
		@closing="$emit('close')">
		<div v-if="chosen === null" class="case-acts">
			<p v-if="loading" data-testid="case-acts-loading">
				{{ t('dossiq', 'Reading what this case allows.') }}
			</p>

			<ul v-else class="case-acts__list" data-testid="case-acts-list">
				<li
					v-for="entry in entries"
					:key="entry.kind + ':' + entry.id"
					class="case-acts__item"
					:class="{ 'case-acts__item--disabled': entry.disabled }"
					:data-testid="'case-act-' + entry.id">
					<NcButton
						:disabled="entry.disabled"
						:data-testid="'case-act-button-' + entry.id"
						@click="choose(entry)">
						{{ label(entry) }}
					</NcButton>
					<span
						v-if="entry.disabled && entry.reason"
						class="case-acts__reason"
						:data-testid="'case-act-reason-' + entry.id">
						{{ refusalOn(entry) }}
					</span>
					<span v-else-if="entry.explainer" class="case-acts__explainer">
						{{ explainer(entry) }}
					</span>
				</li>
			</ul>
		</div>

		<div v-else class="case-acts">
			<p class="case-acts__explainer">
				{{ explainer(chosen) }}
			</p>

			<NcTextArea
				v-if="inputs.reason"
				v-model="reason"
				data-testid="case-act-reason-input"
				:label="t('dossiq', 'Reason')" />

			<NcTextField
				v-if="inputs.result"
				v-model="resultTypeId"
				data-testid="case-act-result-input"
				:label="t('dossiq', 'Result type')" />

			<NcTextField
				v-if="inputs.until"
				v-model="until"
				type="date"
				data-testid="case-act-until-input"
				:label="t('dossiq', 'The case comes back on')" />

			<NcTextField
				v-if="inputs.days"
				v-model="days"
				type="number"
				data-testid="case-act-days-input"
				:label="t('dossiq', 'Days the applicant is given')" />

			<p
				v-if="error"
				class="case-acts__error"
				data-testid="case-act-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-acts-cancel" @click="back">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="chosen !== null"
				data-testid="case-acts-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { buildActsMenu, endpointFor, inputsFor } from '../utils/caseActsMenu.js'
import {
	buildTransitionPayload,
	refusalMessage,
} from '../utils/caseLifecycleHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseLifecycleMenuDialog',

	components: { NcButton, NcDialog, NcTextArea, NcTextField },

	props: {
		/**
		 * The case to act on. Absent when the manifest opened the dialog: an
		 * `open-modal` action carries no object context, so the route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			entries: [],
			chosen: null,
			loading: true,
			reason: '',
			resultTypeId: '',
			until: '',
			days: '14',
			error: '',
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		inputs() {
			return inputsFor(this.chosen ?? {})
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		canConfirm() {
			if (this.busy || this.chosen === null) {
				return false
			}
			if (this.inputs.reason && this.reason.trim().length === 0) {
				return false
			}

			return !(this.inputs.until && this.until.trim().length === 0)
		},
	},

	/**
	 * Read what the case allows before anything is on screen.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * One translated label.
		 *
		 * 🔴 EVERY ACT'S LABEL IS A LITERAL `t()` CALL HERE, not a lookup of
		 * the string the menu carries. The l10n extractor reads literal calls
		 * out of the source, so `t('dossiq', entry.label)` gives translators
		 * nothing at all: the strings would ship English on every instance and
		 * the check would pass, because there would be no key to miss.
		 *
		 * A transition's label is the exception and is passed through: it is
		 * authored by an administrator in the workflow template, in their own
		 * language, so there is no key for it and nothing to translate.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The label.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		label(entry) {
			switch (String(entry?.id ?? '')) {
				case 'suspend':
					return t('dossiq', 'Suspend')
				case 'resume':
					return t('dossiq', 'Resume')
				case 'extend':
					return t('dossiq', 'Extend term')
				case 'reopen':
					return t('dossiq', 'Reopen')
				case 'finish':
					return t('dossiq', 'Finish')
				case 'abort':
					return t('dossiq', 'Abort')
				case 'archive':
					return t('dossiq', 'Archive')
				case 'hold':
					return t('dossiq', 'Hold')
				case 'release-hold':
					return t('dossiq', 'Take off hold')
				case 'promote':
					return t('dossiq', 'Promote to case')
				default:
					return String(entry?.label ?? '')
			}
		},

		/**
		 * One translated explainer, for the same reason label() is a switch.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The explainer, empty when the entry carries none.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		explainer(entry) {
			switch (String(entry?.id ?? '')) {
				case 'finish':
					return t(
						'dossiq',
						'The case reached its result. The result decides what is kept.',
					)
				case 'abort':
					return t(
						'dossiq',
						'An intrekking. There is a result, and it is not a besluit.',
					)
				case 'archive':
					return t(
						'dossiq',
						'The case moves to the retention rule its result type carries.',
					)
				case 'hold':
					return t(
						'dossiq',
						'You park the case until a date. The statutory term keeps running.',
					)
				case 'release-hold':
					return t('dossiq', 'You pick the case up again before its date.')
				case 'promote':
					return t(
						'dossiq',
						'The term starts now. The case leaves your drafts.',
					)
				default:
					return ''
			}
		},

		/**
		 * One translated refusal.
		 *
		 * The sentences the menu builds for a refused term gesture are the
		 * same four `refusalMessage` prints for the matching code, so they are
		 * translated through it: one key per refusal, shared by the menu and
		 * by the dialog behind it. A refusal the SERVER authored, which is
		 * every role refusal, is shown as it arrived.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The refusal, empty when the entry is not refused.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		refusalOn(entry) {
			if (!entry?.disabled || !entry?.reason) {
				return ''
			}

			return entry.kind === 'gesture'
				? t('dossiq', entry.reason)
				: String(entry.reason)
		},

		/**
		 * Ask the three endpoints and merge their answers into one menu.
		 *
		 * `allSettled` and not `all`: one endpoint that is down must not empty
		 * the menu, because an empty menu is a legitimate answer for a case in
		 * a terminal status and the two would be indistinguishable.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async load() {
			if (!this.targetCaseId) {
				this.loading = false
				return
			}
			const id = encodeURIComponent(this.targetCaseId)
			const [moves, state, acts] = await Promise.allSettled([
				axios.get(
					generateUrl(`/apps/dossiq/api/case/${id}/available-transitions`),
				),
				axios.get(generateUrl(`/apps/dossiq/api/case/${id}/lifecycle`)),
				axios.get(generateUrl(`/apps/dossiq/api/case/${id}/acts`)),
			])

			this.entries = buildActsMenu({
				transitions:
					moves.status === 'fulfilled'
						? (moves.value?.data?.transitions ?? [])
						: [],
				state:
					state.status === 'fulfilled'
						? (state.value?.data ?? null)
						: null,
				acts:
					acts.status === 'fulfilled' ? (acts.value?.data ?? null) : null,
			})
			this.loading = false
		},

		/**
		 * Open one act's form.
		 *
		 * @param {object} entry The entry the handler pressed.
		 * @return {void}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		choose(entry) {
			if (entry.disabled) {
				return
			}
			this.chosen = entry
			this.error = ''
		},

		/**
		 * Go back to the list, or close when already on it.
		 *
		 * @return {void}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		back() {
			if (this.chosen === null) {
				this.$emit('close')
				return
			}
			this.chosen = null
			this.error = ''
		},

		/**
		 * Post the act, and keep the form open on a refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.busy = true
			this.error = ''
			const id = encodeURIComponent(this.targetCaseId)
			try {
				if (this.chosen.kind === 'transition') {
					await axios.post(
						generateUrl(`/apps/dossiq/api/case/${id}/transition`),
						buildTransitionPayload({
							transitionId: this.chosen.id,
							comment: this.reason,
							resultTypeId: this.resultTypeId,
						}),
					)
				} else {
					await axios.post(
						generateUrl(
							`/apps/dossiq/api/case/${id}/${endpointFor(this.chosen)}`,
						),
						{
							reason: this.reason,
							resultTypeId: this.resultTypeId,
							until: this.until,
							days: Number(this.days) || 0,
						},
					)
				}
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error = refusalMessage(error?.response?.data ?? {}, (s) =>
					t('dossiq', s),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-acts {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-acts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-acts__item {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.case-acts__reason,
.case-acts__explainer {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-acts__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
