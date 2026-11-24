<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Score a bid with the scoring formula the case names.

  Reads the case's scoring sheet (`GET /api/cases/{caseId}/scoring`): the
  formula, the values the case holds for it, the values the evaluator enters
  per bid, and the admissible bids. The evaluator picks a bid, enters those
  values, and the server applies the formula and stores their evaluation
  (`POST`), so this dialog computes nothing itself. A refusal is shown as the
  server words it.

  @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Score a bid')"
		data-testid="bid-score-dialog"
		@closing="$emit('close')">
		<div class="bid-score">
			<p v-if="loading" data-testid="bid-score-loading">
				{{ t('dossiq', 'Loading the bids …') }}
			</p>
			<p v-else-if="refusal" data-testid="bid-score-refusal">
				{{ refusal }}
			</p>
			<template v-else>
				<p class="bid-score__formula" data-testid="bid-score-formula">
					{{ t('dossiq', 'Scored with: {formula}', { formula: formulaTitle }) }}
				</p>
				<NcSelect
					v-model="bidId"
					data-testid="bid-score-bid"
					:inputLabel="t('dossiq', 'Bid')"
					:options="bidOptions"
					:reduce="(option) => option.id"
					label="label" />
				<NcTextField
					v-for="input in inputs"
					:key="input"
					:modelValue="String(scores[input] ?? '')"
					:data-testid="`bid-score-input-${input}`"
					:label="labelOfInput(input)"
					type="number"
					@update:modelValue="(value) => setScore(input, value)" />
				<p v-if="fixedLine" class="bid-score__note" data-testid="bid-score-fixed">
					{{ fixedLine }}
				</p>
				<p v-if="total !== null" class="bid-score__total" data-testid="bid-score-total">
					{{ t('dossiq', 'Total: {total}', { total: formatNumber(total) }) }}
				</p>
			</template>
			<p
				v-if="error"
				class="bid-score__error"
				role="alert"
				data-testid="bid-score-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="bid-score-cancel" @click="$emit('close')">
				{{ total === null ? t('dossiq', 'Cancel') : t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				data-testid="bid-score-submit"
				:disabled="!canScore"
				variant="primary"
				@click="score">
				<template #icon>
					<Scale :size="20" />
				</template>
				{{ saving ? t('dossiq', 'Scoring …') : t('dossiq', 'Score bid') }}
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
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Scale from 'vue-material-design-icons/ScaleBalance.vue'

/**
 * Words for the formula values a shipped formula asks the evaluator for; any
 * other value is shown by its own name.
 *
 * @return {Record<string, string>}
 */
function inputLabels() {
	return {
		quality: t('dossiq', 'Quality score'),
		maxPoints: t('dossiq', 'Maximum points'),
		qualityWeight: t('dossiq', 'Quality weight'),
		priceWeight: t('dossiq', 'Price weight'),
	}
}

export default {
	name: 'BidScoreDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextField, Scale },

	props: {
		/** The case uuid whose bids are scored. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			sheet: null,
			bidId: null,
			scores: {},
			total: null,
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Why nothing can be scored, as the server words it.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		refusal() {
			return String(this.sheet?.refusal ?? '')
		},

		/**
		 * The formula's name.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		formulaTitle() {
			return String(this.sheet?.formula?.title ?? '')
		},

		/**
		 * The values the evaluator enters per bid.
		 *
		 * @return {string[]}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		inputs() {
			return Array.isArray(this.sheet?.inputs) ? this.sheet.inputs : []
		},

		/**
		 * The bids in the picker, each with its price and the caller's total so far.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		bidOptions() {
			const rows = Array.isArray(this.sheet?.submissions) ? this.sheet.submissions : []
			return rows.map((row) => {
				const parts = [String(row.label ?? row.id)]
				if (row.price !== null && row.price !== undefined) {
					parts.push(t('dossiq', 'price {price}', { price: this.formatNumber(row.price) }))
				}
				if (row.total !== null && row.total !== undefined) {
					parts.push(t('dossiq', 'scored {total}', { total: this.formatNumber(row.total) }))
				}
				return { id: row.id, label: parts.join(' · ') }
			})
		},

		/**
		 * The chosen bid's row.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		selected() {
			const rows = Array.isArray(this.sheet?.submissions) ? this.sheet.submissions : []
			return rows.find((row) => row.id === this.bidId) ?? null
		},

		/**
		 * One line naming the values the case holds for the formula.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		fixedLine() {
			const fixed = this.sheet?.fixed ?? {}
			const parts = Object.keys(fixed).map((name) => `${this.labelOfInput(name)} ${this.formatNumber(fixed[name])}`)
			return parts.length === 0 ? '' : t('dossiq', 'From the case: {values}', { values: parts.join(', ') })
		},

		/**
		 * Whether a bid is chosen and every input holds a number.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		canScore() {
			if (this.saving || this.selected === null || this.refusal !== '') {
				return false
			}
			return this.inputs.every((input) => Number.isFinite(Number.parseFloat(this.scores[input])))
		},
	},

	watch: {
		/**
		 * Start from the scores the caller gave the chosen bid before.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		selected() {
			this.scores = { ...(this.selected?.scores ?? {}) }
			this.total = null
			this.error = ''
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * The scoring URL of the case.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		sheetUrl() {
			return generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/scoring`)
		},

		/**
		 * The words for a formula value.
		 *
		 * @param {string} name The value's name in the formula.
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		labelOfInput(name) {
			return inputLabels()[name] ?? name
		},

		/**
		 * A number as the reader writes it.
		 *
		 * @param {number} value The number.
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		formatNumber(value) {
			return Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })
		},

		/**
		 * Keep one entered value.
		 *
		 * @param {string} input The formula value.
		 * @param {string} value What the evaluator typed.
		 * @return {void}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		setScore(input, value) {
			this.scores = { ...this.scores, [input]: value }
			this.total = null
		},

		/**
		 * Read the case's scoring sheet.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(this.sheetUrl())
				this.sheet = data ?? null
				const rows = Array.isArray(this.sheet?.submissions) ? this.sheet.submissions : []
				if (rows.length === 1) {
					this.bidId = rows[0].id
				}
			} catch (e) {
				this.sheet = null
				this.error = e?.response?.data?.message || t('dossiq', 'The bids of this case could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Post the entered values; the server applies the formula and stores the evaluation.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async score() {
			if (this.canScore === false) {
				return
			}
			const entered = {}
			for (const input of this.inputs) {
				entered[input] = Number.parseFloat(this.scores[input])
			}
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(this.sheetUrl(), { bid: this.bidId, scores: entered })
				this.total = Number(data?.total)
				const row = this.selected
				if (row !== null) {
					row.total = this.total
					row.scores = entered
				}
				showSuccess(t('dossiq', 'Bid scored.'))
				this.$emit('done', data)
			} catch (e) {
				this.error = e?.response?.data?.message || t('dossiq', 'The bid could not be scored.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.bid-score {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.bid-score__formula,
.bid-score__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.bid-score__total {
	font-weight: bold;
	margin: 0;
}

.bid-score__error {
	color: var(--color-error-text);
}
</style>
