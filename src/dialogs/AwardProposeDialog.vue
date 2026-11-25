<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Propose the award of a tender case.

  Reads the case's scoring sheet (`GET /api/cases/{caseId}/scoring`) and lists
  its admissible bids, highest total first, with the bid proposed now marked.
  The buyer picks the bid (the highest scored one is preselected) and the
  dialog posts it (`POST /api/cases/{caseId}/award-proposal`). The bid then
  holds the proposal, one per lot, and the case's flow makes the award final
  after the standstill term. A refusal is shown as the server words it.

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
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'

/**
 * The state of the bid proposed for the award of its lot.
 */
const PROPOSED = 'proposed-award'

/**
 * The URL of one of a case's routes.
 *
 * @param {string} caseId The case uuid.
 * @param {string} path The route under the case.
 * @return {string}
 */
function caseUrl(caseId, path) {
	return generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/${path}`)
}

/**
 * The message a refused call carries, else the fallback.
 *
 * @param {Error} e The axios error.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string}
 */
function refusalOf(e, fallback) {
	return e?.response?.data?.message || e?.response?.data?.error || fallback
}

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
		 * The bid proposed now, if any.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		proposedNow() {
			return this.submissions.find((row) => row.state === PROPOSED) ?? null
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
		 * Read the scoring sheet; preselect the highest scored bid.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(caseUrl(this.caseId, 'scoring'))
				this.submissions = Array.isArray(data?.submissions) ? data.submissions : []
				this.refusal = data?.refusal || ''
				const best = this.ranked.find((row) => row.total !== null && row.total !== undefined)
				this.bidId = this.proposedNow?.id ?? best?.id ?? null
			} catch (e) {
				this.submissions = []
				this.error = refusalOf(e, t('dossiq', 'The bids of this case could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Post the chosen bid; close on success, keep a refusal in the dialog.
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
			try {
				const { data } = await axios.post(caseUrl(this.caseId, 'award-proposal'), { bid: this.bidId })
				showSuccess(t('dossiq', 'Award proposed.'))
				this.$emit('done', data)
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
