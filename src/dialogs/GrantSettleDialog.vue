<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Settle a grant of the case.

  Reads the case's grant executions (`GET /api/cases/{caseId}/grant-chain`)
  and lists the settlements not determined yet, each with the amount its
  decision granted, the advances paid and the actual costs as stored. The
  handler checks the three figures and the dialog posts them to the existing
  grant route (`POST /api/subsidies/vaststellingen/{id}/vast`). The server
  computes the settled amount and, when the advances exceed it, proposes a
  recovery (draft, for the manager); the dialog only says what came back.

  @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Settle the grant')"
		data-testid="grant-settle-dialog"
		@closing="$emit('close')">
		<div class="grant-dialog">
			<p v-if="loading" data-testid="grant-settle-loading">
				{{ t('dossiq', 'Loading the settlements …') }}
			</p>
			<p v-else-if="settlements.length === 0 && !error" data-testid="grant-settle-empty">
				{{ t('dossiq', 'This case has no settlement to determine.') }}
			</p>
			<template v-else-if="settlements.length > 0">
				<NcSelect
					v-if="settlements.length > 1"
					v-model="settlementId"
					data-testid="grant-settle-settlement"
					:inputLabel="t('dossiq', 'Settlement')"
					:options="settlementOptions"
					:reduce="(option) => option.id"
					label="label" />
				<NcTextField
					:modelValue="figures.granted"
					data-testid="grant-settle-granted"
					:label="t('dossiq', 'Granted amount')"
					type="number"
					@update:modelValue="(value) => setFigure('granted', value)" />
				<NcTextField
					:modelValue="figures.actual"
					data-testid="grant-settle-actual"
					:label="t('dossiq', 'Actual costs')"
					type="number"
					@update:modelValue="(value) => setFigure('actual', value)" />
				<NcTextField
					:modelValue="figures.advances"
					data-testid="grant-settle-advances"
					:label="t('dossiq', 'Advances paid')"
					type="number"
					@update:modelValue="(value) => setFigure('advances', value)" />
				<p class="grant-dialog__note">
					{{ t('dossiq', 'When the advances exceed the settled amount, a recovery is proposed to the manager.') }}
				</p>
			</template>
			<p
				v-if="error"
				class="grant-dialog__error"
				role="alert"
				data-testid="grant-settle-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="grant-settle-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="grant-settle-submit"
				:disabled="!canSettle"
				variant="primary"
				@click="settle">
				<template #icon>
					<ScaleBalance :size="20" />
				</template>
				{{ saving ? t('dossiq', 'Settling …') : t('dossiq', 'Settle') }}
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
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import { executionLabel, formatAmount, loadGrantChain, refusalOf } from './grantChain.js'

/**
 * A stored amount as the text of a number field.
 *
 * @param {number|null|undefined} amount The amount.
 * @return {string}
 */
function fieldText(amount) {
	return amount === null || amount === undefined ? '' : String(amount)
}

export default {
	name: 'GrantSettleDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextField, ScaleBalance },

	props: {
		/** The case uuid whose grant is settled. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			settlements: [],
			settlementId: null,
			figures: { granted: '', actual: '', advances: '' },
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The open settlements in the picker.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		settlementOptions() {
			return this.settlements.map((row) => {
				const costs = formatAmount(row.actualCostTotal)
				return { id: row.id, label: costs === '' ? row.grant : `${row.grant} · ${costs}` }
			})
		},

		/**
		 * The chosen settlement.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		selected() {
			return this.settlements.find((row) => row.id === this.settlementId) ?? null
		},

		/**
		 * Whether a settlement is chosen and the three figures are numbers of zero or more.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		canSettle() {
			if (this.saving || this.selected === null) {
				return false
			}
			return Object.values(this.figures).every((value) => String(value).trim() !== '' && Number(value) >= 0)
		},
	},

	watch: {
		/**
		 * Start from the figures the chosen settlement's grant holds.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		selected() {
			this.figures = {
				granted: fieldText(this.selected?.grantedAmount),
				actual: fieldText(this.selected?.actualCostTotal),
				advances: fieldText(this.selected?.advancesPaid),
			}
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Keep one figure as typed.
		 *
		 * @param {string} name granted, actual or advances.
		 * @param {string} value What the handler typed.
		 * @return {void}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		setFigure(name, value) {
			this.figures = { ...this.figures, [name]: value }
		},

		/**
		 * Read the settlements of the case's grants that are not determined yet.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const executions = await loadGrantChain(this.caseId)
				this.settlements = executions.flatMap((execution) => (Array.isArray(execution.settlements) ? execution.settlements : [])
					.filter((row) => row.determined !== true)
					.map((row) => ({
						...row,
						grant: executionLabel(execution),
						grantedAmount: execution.grantedAmount ?? null,
						advancesPaid: execution.advancesPaid ?? null,
					})))
				if (this.settlements.length === 1) {
					this.settlementId = this.settlements[0].id
				}
			} catch (e) {
				this.settlements = []
				this.error = refusalOf(e, t('dossiq', 'The settlements of this case could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Post the three figures; the server settles and proposes any recovery.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async settle() {
			if (this.canSettle === false) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const url = generateUrl(`/apps/dossiq/api/subsidies/vaststellingen/${encodeURIComponent(this.settlementId)}/vast`)
				const { data } = await axios.post(url, {
					grantedAmount: Number(this.figures.granted),
					werkelijkeKosten: Number(this.figures.actual),
					totaalVoorschotten: Number(this.figures.advances),
				})
				showSuccess(this.outcomeOf(data))
				this.$emit('done', data)
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, t('dossiq', 'The grant could not be settled.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * What the settlement came to, in one sentence.
		 *
		 * @param {object} data The settle route's answer.
		 * @return {string}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		outcomeOf(data) {
			const settled = formatAmount(data?.determination?.determinedAmount)
			const recovery = formatAmount(data?.terugvordering?.amount)
			if (recovery !== '') {
				return t('dossiq', 'Grant settled at {settled}. A recovery of {recovery} is proposed to the manager.', { settled, recovery })
			}
			return settled === '' ? t('dossiq', 'Grant settled.') : t('dossiq', 'Grant settled at {settled}.', { settled })
		},
	},
}
</script>

<style scoped>
.grant-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.grant-dialog__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.grant-dialog__error {
	color: var(--color-error-text);
}
</style>
