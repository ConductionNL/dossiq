<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Record an interim report on a grant of the case.

  Reads the case's grant executions (`GET /api/cases/{caseId}/grant-chain`),
  lets the handler pick one (the only one is picked for them), takes the
  report's number, period and progress, and posts it to the existing grant
  route (`POST /api/subsidies/uitvoeringen/{id}/tussenrapportages`). A refusal
  stays in the dialog as the server words it.

  @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Record an interim report')"
		data-testid="interim-report-record-dialog"
		@closing="$emit('close')">
		<div class="grant-dialog">
			<p v-if="loading" data-testid="interim-report-record-loading">
				{{ t('dossiq', 'Loading the grants …') }}
			</p>
			<p v-else-if="executions.length === 0 && !error" data-testid="interim-report-record-empty">
				{{ t('dossiq', 'This case has no grant in execution to report on.') }}
			</p>
			<template v-else-if="executions.length > 0">
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
			</template>
			<p
				v-if="error"
				class="grant-dialog__error"
				role="alert"
				data-testid="interim-report-record-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="interim-report-record-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="interim-report-record-submit"
				:disabled="!canRecord"
				variant="primary"
				@click="record">
				<template #icon>
					<FileChartOutline :size="20" />
				</template>
				{{ saving ? t('dossiq', 'Recording …') : t('dossiq', 'Record report') }}
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
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import { executionLabel, loadGrantChain, refusalOf } from './grantChain.js'

export default {
	name: 'InterimReportRecordDialog',

	components: { FileChartOutline, NcButton, NcDialog, NcSelect, NcTextArea, NcTextField },

	props: {
		/** The case uuid whose grant the report is on. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			executions: [],
			executionId: null,
			number: '',
			periodStart: '',
			periodEnd: '',
			progress: '',
			loading: false,
			saving: false,
			error: '',
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
			if (this.saving || !this.executionId || this.number.trim() === '') {
				return false
			}
			return this.periodStart === '' || this.periodEnd === '' || this.periodEnd >= this.periodStart
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the case's grants; the only one is picked.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.executions = await loadGrantChain(this.caseId)
				if (this.executions.length === 1) {
					this.executionId = this.executions[0].id
				}
			} catch (e) {
				this.executions = []
				this.error = refusalOf(e, t('dossiq', 'The grants of this case could not be read.'))
			} finally {
				this.loading = false
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
			if (this.periodStart !== '') {
				body.rapportagePeriodStart = this.periodStart
			}
			if (this.periodEnd !== '') {
				body.rapportagePeriodEnd = this.periodEnd
			}
			if (this.progress.trim() !== '') {
				body.substantiveProgress = this.progress.trim()
			}
			this.saving = true
			this.error = ''
			try {
				const url = generateUrl(`/apps/dossiq/api/subsidies/uitvoeringen/${encodeURIComponent(this.executionId)}/tussenrapportages`)
				const { data } = await axios.post(url, body)
				showSuccess(t('dossiq', 'Interim report recorded.'))
				this.$emit('done', data)
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, t('dossiq', 'The interim report could not be recorded.'))
			} finally {
				this.saving = false
			}
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

.grant-dialog__error {
	color: var(--color-error-text);
}
</style>
