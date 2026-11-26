<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Propose the award of a tender case.

  Reads the case's scoring sheet (`GET /api/cases/{caseId}/scoring`) and lists
  its admissible bids, highest total first, with the bid proposed now marked.
  The buyer picks the bid (the highest scored one is preselected). The bid
  holds the proposal, one per lot, through its own declared lifecycle: the
  dialog walks OpenRegister's transition endpoint, `releaseAward` on the bid
  of the same lot proposed so far, then `proposeAward` on the chosen one. The
  case's flow makes the award final after the standstill term. A refusal is
  shown as the server words it.

  @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Propose the award')"
		data-testid="award-propose"
		@closing="$emit('close')">
		<div class="award-propose">
			<p v-if="loading" data-testid="award-propose-loading">
				{{ t('dossiq', 'Loading the bids …') }}
			</p>
			<p v-else-if="refusal" data-testid="award-propose-refusal">
				{{ refusal }}
			</p>
			<template v-else>
				<NcSelect
					v-model="bidId"
					data-testid="award-propose-bid"
					:inputLabel="t('dossiq', 'Bid')"
					:options="bidOptions"
					:reduce="(option) => option.id"
					label="label" />
				<p v-if="proposedNow" class="award-propose__note" data-testid="award-propose-current">
					{{ t('dossiq', 'Proposed now: {bid}', { bid: proposedNow.label }) }}
				</p>
				<p class="award-propose__note">
					{{ t('dossiq', 'The award becomes final through the case flow, after the standstill term.') }}
				</p>
			</template>
			<p
				v-if="error"
				class="award-propose__error"
				role="alert"
				data-testid="award-propose-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="award-propose-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="award-propose-submit"
				:disabled="!canPropose || saving"
				variant="primary"
				@click="propose">
				<template #icon>
					<HandshakeOutline :size="20" />
				</template>
				{{ saving ? t('dossiq', 'Saving …') : t('dossiq', 'Propose') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import { readScoringSheet, refusalOf, walkBidTransition } from '../services/bidAwardApi.js'

/**
 * The state of the bid proposed for the award of its lot.
 */
const PROPOSED = 'proposed-award'

/**
 * A total as the reader writes numbers.
 *
 * @param {number|null} total The total.
 * @return {string}
 */
function totalText(total) {
	return total === null || total === undefined ? '' : Number(total).toLocaleString()
}

export default {
	name: 'AwardProposeDialog',

	components: { NcButton, NcDialog, NcSelect, HandshakeOutline },

	props: {
		/** The case uuid whose award is proposed. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			submissions: [],
			refusal: '',
			bidId: null,
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The bids, highest total first; an unscored bid comes last.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		ranked() {
			return [...this.submissions].sort((a, b) => (b.total ?? -Infinity) - (a.total ?? -Infinity))
		},

		/**
		 * The bids in the picker, each with its total.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		bidOptions() {
			return this.ranked.map((row) => {
				const total = totalText(row.total)
				return { id: row.id, label: total === '' ? row.label : t('dossiq', '{bid} · total {total}', { bid: row.label, total }) }
			})
		},

		/**
		 * The bid of the chosen bid's lot proposed now, if any.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		proposedNow() {
			const lot = this.selected?.lot ?? ''
			return this.submissions.find((row) => row.state === PROPOSED && (row.lot ?? '') === lot) ?? null
		},

		/**
		 * The chosen bid.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		selected() {
			return this.submissions.find((row) => row.id === this.bidId) ?? null
		},

		/**
		 * Whether a bid other than the one proposed now is chosen.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		canPropose() {
			return this.bidId !== null && this.bidId !== this.proposedNow?.id
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the scoring sheet; preselect the highest scored bid, or the proposal of its lot.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const sheet = await readScoringSheet(this.caseId)
				this.submissions = sheet.submissions
				this.refusal = sheet.refusal
				// The best scored bid, unless its lot already has a proposal: then that one.
				const best = this.ranked.find((row) => row.total !== null && row.total !== undefined) ?? null
				const proposed = this.submissions.find((row) => row.state === PROPOSED && (best === null || (row.lot ?? '') === (best.lot ?? ''))) ?? null
				this.bidId = proposed?.id ?? best?.id ?? null
			} catch (e) {
				this.submissions = []
				this.error = refusalOf(e, t('dossiq', 'The bids of this case could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Release the lot's earlier proposal, then propose the chosen bid; close
		 * on success, keep a refusal in the dialog.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async propose() {
			if (this.canPropose === false || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			const chosen = this.bidId
			try {
				if (this.proposedNow !== null) {
					await walkBidTransition(this.proposedNow.id, 'releaseAward')
				}
				await walkBidTransition(chosen, 'proposeAward')
				showSuccess(t('dossiq', 'Award proposed.'))
				this.$emit('done', { proposed: chosen })
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, t('dossiq', 'The award could not be proposed.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.award-propose {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.award-propose__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.award-propose__error {
	color: var(--color-error-text);
}
</style>
