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
	<GrantStepDialog
		:name="t('dossiq', 'Settle the grant')"
		testid="grant-settle"
		:submitLabel="t('dossiq', 'Settle')"
		:loading="loading"
		:empty="settlements.length === 0 ? t('dossiq', 'This case has no settlement to determine.') : ''"
		:error="error"
		:canSubmit="canSettle"
		:saving="saving"
		@close="$emit('close')"
		@submit="settle">
		<NcSelect
			v-if="settlements.length > 1"
			v-model="settlementId"
			data-testid="grant-settle-settlement"
			:inputLabel="t('dossiq', 'Settlement')"
			:options="settlementOptions"
			:reduce="(option) => option.id"
			label="label" />
		<NcTextField
			v-for="figure in FIGURES"
			:key="figure.name"
			:modelValue="figures[figure.name]"
			:data-testid="`grant-settle-${figure.name}`"
			:label="figure.label"
			type="number"
			@update:modelValue="(value) => setFigure(figure.name, value)" />
		<template #note>
			{{ settlements.length === 0 ? '' : t('dossiq', 'When the advances exceed the settled amount, a recovery is proposed to the manager.') }}
		</template>
		<template #icon>
			<ScaleBalance :size="20" />
		</template>
	</GrantStepDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import GrantStepDialog from './GrantStepDialog.vue'
import { executionLabel, formatAmount, grantStep } from './grantChain.js'

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

	components: { GrantStepDialog, NcSelect, NcTextField, ScaleBalance },

	mixins: [grantStep],

	data() {
		return {
			settlementId: null,
			figures: { granted: '', actual: '', advances: '' },
			FIGURES: [
				{ name: 'granted', label: t('dossiq', 'Granted amount') },
				{ name: 'actual', label: t('dossiq', 'Actual costs') },
				{ name: 'advances', label: t('dossiq', 'Advances paid') },
			],
		}
	},

	computed: {
		/**
		 * The settlements of the case's grants not determined yet, each with
		 * the granted amount and the advances of its grant.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		settlements() {
			return this.executions.flatMap((execution) => (Array.isArray(execution.settlements) ? execution.settlements : [])
				.filter((row) => row.status !== 'determined')
				.map((row) => ({
					...row,
					grant: executionLabel(execution),
					grantedAmount: execution.grantedAmount ?? null,
					advancesPaid: execution.advancesPaid ?? null,
				})))
		},

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
			return this.selected !== null
				&& Object.values(this.figures).every((value) => String(value).trim() !== '' && Number(value) >= 0)
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

	methods: {
		/**
		 * The only open settlement of the case is the one settled.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		afterLoad() {
			if (this.settlements.length === 1) {
				this.settlementId = this.settlements[0].id
			}
		},

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
			await this.send(
				`vaststellingen/${encodeURIComponent(this.settlementId)}/vast`,
				{
					grantedAmount: Number(this.figures.granted),
					werkelijkeKosten: Number(this.figures.actual),
					totaalVoorschotten: Number(this.figures.advances),
				},
				(data) => this.outcomeOf(data),
				t('dossiq', 'The grant could not be settled.'),
			)
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
