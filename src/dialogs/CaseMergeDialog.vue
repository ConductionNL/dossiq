<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Merging this case into another one.

  Two cases for one request happen: the applicant files twice, or two channels
  file the same thing. Relating them is not enough, because each keeps its own
  term, its own tasks and its own number, and a reply to the wrong number lands
  where nobody is working.

  The picker is a search and a list rather than a dropdown, because the survivor
  is chosen from what the words find and the handler must read the case's title
  and number before picking one. Nothing is preselected: a merge is not
  reversible after its window, and a default survivor is a default that gets
  confirmed.

  Every refusal on screen is the server's, read from `error` beside its `code`.
  The dialog hides no act it cannot perform: the refusal is what tells the
  handler what to do instead.

  @spec openspec/changes/case-merge/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Merge this case into another')"
		data-testid="case-merge-dialog"
		@closing="$emit('close')">
		<div class="case-merge-dialog">
			<p class="case-merge-dialog__explainer">
				{{
					t(
						'dossiq',
						'The parties, documents and objects move to the case you pick. This case keeps its number and points at the one it became part of, so a reply quoting the old number still arrives.',
					)
				}}
			</p>

			<NcTextField
				v-model="query"
				data-testid="case-merge-search"
				:label="t('dossiq', 'Search the case to merge into')"
				:placeholder="t('dossiq', 'Case number or a word from the title')"
				@update:modelValue="search" />

			<ul
				v-if="results.length > 0"
				class="case-merge-dialog__results"
				data-testid="case-merge-results">
				<li v-for="option in results" :key="option.id">
					<NcButton
						:data-testid="`case-merge-option-${option.id}`"
						:variant="option.id === survivorId ? 'primary' : 'tertiary'"
						alignment="start"
						wide
						@click="survivorId = option.id">
						{{ option.label }}
					</NcButton>
				</li>
			</ul>

			<p
				v-else-if="searched && !busy"
				class="case-merge-dialog__empty"
				data-testid="case-merge-empty">
				{{ t('dossiq', 'No other case matches those words.') }}
			</p>

			<NcTextArea
				v-model="reason"
				data-testid="case-merge-reason"
				:label="t('dossiq', 'Why these are one case')" />

			<p
				v-if="error"
				class="case-merge-dialog__error"
				data-testid="case-merge-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-merge-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-merge-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Merge') }}
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

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseMergeDialog',

	components: {
		NcButton,
		NcDialog,
		NcTextArea,
		NcTextField,
	},

	props: {
		/**
		 * The case being merged away. Absent when the manifest opened the
		 * dialog: an `open-modal` action carries no object context, so the
		 * route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			query: '',
			results: [],
			survivorId: '',
			reason: '',
			error: '',
			busy: false,
			searched: false,
		}
	},

	computed: {
		/** @spec openspec/changes/case-merge/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/case-merge/specs/case-management/spec.md */
		canConfirm() {
			return (
				this.busy === false
				&& this.survivorId !== ''
				&& this.survivorId !== this.targetCaseId
				&& this.reason.trim().length > 0
			)
		},
	},

	methods: {
		t,

		/**
		 * The cases those words find, minus this one.
		 *
		 * A malformed term is refused by OpenRegister with a 400 rather than
		 * run as a literal, so an empty list here means no match and never a
		 * term nobody could parse.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-merge/specs/case-management/spec.md
		 */
		async search() {
			const term = this.query.trim()
			if (term.length < 2) {
				this.results = []
				this.searched = false
				return
			}

			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/dossiq/case'),
					{ params: { _search: term, _limit: 10 } },
				)
				this.results = (data?.results || [])
					.filter((row) => String(row?.id ?? '') !== this.targetCaseId)
					.map((row) => ({
						id: String(row?.id ?? ''),
						label: [row?.identifier, row?.title]
							.filter(Boolean)
							.join(' · '),
					}))
			} catch (error) {
				this.results = []
				this.error =
					error?.response?.data?.error
					|| t('dossiq', 'Those words could not be searched for.')
			} finally {
				this.searched = true
				this.busy = false
			}
		},

		/**
		 * Ask the server to merge, and keep the dialog open on a refusal.
		 *
		 * The refusal is shown verbatim: a case with a signed decision and a
		 * case that was already merged are refused for different reasons, and
		 * one generic sentence over both tells the handler nothing.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-merge/specs/case-management/spec.md
		 */
		async confirm() {
			if (!this.canConfirm || !this.targetCaseId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/merge`,
					),
					{ into: this.survivorId, reason: this.reason.trim() },
				)
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('dossiq', 'These cases were not merged.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-merge-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-merge-dialog__results {
	display: flex;
	flex-direction: column;
	gap: 4px;
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 240px;
	overflow-y: auto;
}

.case-merge-dialog__empty {
	color: var(--color-text-maxcontrast);
}

.case-merge-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
