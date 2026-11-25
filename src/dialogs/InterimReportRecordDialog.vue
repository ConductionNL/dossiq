<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Record an interim report on a grant of the case.

  Reads the case's grant executions (`GET /api/cases/{caseId}/grant-chain`),
  lets the handler pick one (the only one is picked for them), takes the
  report's number, period and progress, and posts it to the existing grant
  route (`POST /api/subsidies/uitvoeringen/{id}/tussenrapportages`).

  @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
-->
<template>
	<GrantStepDialog
		:name="t('dossiq', 'Record an interim report')"
		testid="interim-report-record"
		:submitLabel="t('dossiq', 'Record report')"
		:loading="loading"
		:empty="executions.length === 0 ? t('dossiq', 'This case has no grant in execution to report on.') : ''"
		:error="error"
		:canSubmit="canRecord"
		:saving="saving"
		@close="$emit('close')"
		@submit="record">
		<NcSelect
			v-if="executions.length > 1"
			v-model="executionId"
			data-testid="interim-report-record-execution"
			:inputLabel="t('dossiq', 'Grant')"
			:options="executionOptions"
			:reduce="(option) => option.id"
			label="label" />
		<NcTextField
			v-model="number"
			data-testid="interim-report-record-number"
			:label="t('dossiq', 'Report number')" />
		<NcTextField
			v-model="periodStart"
			data-testid="interim-report-record-start"
			:label="t('dossiq', 'Period from')"
			type="date" />
		<NcTextField
			v-model="periodEnd"
			data-testid="interim-report-record-end"
			:label="t('dossiq', 'Period until')"
			type="date" />
		<NcTextArea
			v-model="progress"
			data-testid="interim-report-record-progress"
			:label="t('dossiq', 'Progress')"
			resize="vertical" />
		<template #icon>
			<FileChartOutline :size="20" />
		</template>
	</GrantStepDialog>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import GrantStepDialog from './GrantStepDialog.vue'
import { executionLabel, grantStep } from './grantChain.js'

export default {
	name: 'InterimReportRecordDialog',

	components: { FileChartOutline, GrantStepDialog, NcSelect, NcTextArea, NcTextField },

	mixins: [grantStep],

	data() {
		return {
			executionId: null,
			number: '',
			periodStart: '',
			periodEnd: '',
			progress: '',
		}
	},

	computed: {
		/**
		 * The executions in the picker.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		executionOptions() {
			return this.executions.map((execution) => ({ id: execution.id, label: executionLabel(execution) }))
		},

		/**
		 * Whether a grant is chosen, the report has a number, and a period
		 * that is given ends on or after its start.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		canRecord() {
			if (!this.executionId || this.number.trim() === '') {
				return false
			}
			return this.periodStart === '' || this.periodEnd === '' || this.periodEnd >= this.periodStart
		},
	},

	methods: {
		/**
		 * The only grant of the case is the one reported on.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		afterLoad() {
			if (this.executions.length === 1) {
				this.executionId = this.executions[0].id
			}
		},

		/**
		 * Post the report to the chosen grant's execution.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async record() {
			if (this.canRecord === false) {
				return
			}
			const body = { rapportagenummer: this.number.trim() }
			const optional = {
				rapportagePeriodStart: this.periodStart,
				rapportagePeriodEnd: this.periodEnd,
				substantiveProgress: this.progress.trim(),
			}
			for (const [field, value] of Object.entries(optional)) {
				if (value !== '') {
					body[field] = value
				}
			}
			await this.send(
				`uitvoeringen/${encodeURIComponent(this.executionId)}/tussenrapportages`,
				body,
				() => this.t('dossiq', 'Interim report recorded.'),
				this.t('dossiq', 'The interim report could not be recorded.'),
			)
		},
	},
}
</script>
