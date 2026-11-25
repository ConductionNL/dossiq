<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Assess an interim report of a grant of the case as sufficient.

  Reads the case's grant executions (`GET /api/cases/{caseId}/grant-chain`)
  and lists the interim reports not assessed yet. The handler picks one (the
  only one is picked for them), may name the approved amount, and the dialog
  posts the assessment "voldoende" to the existing grant route
  (`POST /api/subsidies/tussenrapportages/{id}/beoordelen`), which stores the
  assessor from the session and the date. That route approves; a rejection or
  a partial approval has no route, so this dialog does not offer one.

  @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Assess an interim report')"
		data-testid="interim-report-assess-dialog"
		@closing="$emit('close')">
		<div class="grant-dialog">
			<p v-if="loading" data-testid="interim-report-assess-loading">
				{{ t('dossiq', 'Loading the interim reports …') }}
			</p>
			<p v-else-if="reports.length === 0 && !error" data-testid="interim-report-assess-empty">
				{{ t('dossiq', 'This case has no interim report waiting for an assessment.') }}
			</p>
			<template v-else-if="reports.length > 0">
				<NcSelect
					v-model="reportId"
					data-testid="interim-report-assess-report"
					:inputLabel="t('dossiq', 'Interim report')"
					:options="reportOptions"
					:reduce="(option) => option.id"
					label="label" />
				<p class="grant-dialog__note" data-testid="interim-report-assess-opinion">
					{{ t('dossiq', 'The report is approved with the assessment voldoende (sufficient).') }}
				</p>
				<NcTextField
					v-model="approvedAmount"
					data-testid="interim-report-assess-amount"
					:label="t('dossiq', 'Approved amount (optional)')"
					type="number" />
			</template>
			<p
				v-if="error"
				class="grant-dialog__error"
				role="alert"
				data-testid="interim-report-assess-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="interim-report-assess-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="interim-report-assess-submit"
				:disabled="!canAssess"
				variant="primary"
				@click="assess">
				<template #icon>
					<CheckDecagramOutline :size="20" />
				</template>
				{{ saving ? t('dossiq', 'Assessing …') : t('dossiq', 'Assess') }}
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
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import { executionLabel, loadGrantChain, refusalOf } from './grantChain.js'

/** The assessment the approve route records. */
const SUFFICIENT = 'voldoende'

export default {
	name: 'InterimReportAssessDialog',

	components: { CheckDecagramOutline, NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		/** The case uuid whose grant reports are assessed. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			reports: [],
			reportId: null,
			approvedAmount: '',
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The reports in the picker: number, period and grant.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		reportOptions() {
			return this.reports.map((report) => {
				const parts = [report.number || report.id]
				if (report.periodStart || report.periodEnd) {
					parts.push(`${report.periodStart} – ${report.periodEnd}`)
				}
				parts.push(report.grant)
				return { id: report.id, label: parts.join(' · ') }
			})
		},

		/**
		 * Whether a report is chosen and an amount, when given, is a number of zero or more.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		canAssess() {
			if (this.saving || !this.reportId) {
				return false
			}
			const amount = String(this.approvedAmount).trim()
			return amount === '' || (Number.isFinite(Number(amount)) && Number(amount) >= 0)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the reports of the case's grants that wait for an assessment.
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
				this.reports = executions.flatMap((execution) => (Array.isArray(execution.interimReports) ? execution.interimReports : [])
					.filter((report) => report.assessed !== true)
					.map((report) => ({ ...report, grant: executionLabel(execution) })))
				if (this.reports.length === 1) {
					this.reportId = this.reports[0].id
				}
			} catch (e) {
				this.reports = []
				this.error = refusalOf(e, t('dossiq', 'The interim reports of this case could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Post the assessment of the chosen report.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async assess() {
			if (this.canAssess === false) {
				return
			}
			const body = { beoordelingsoordeel: SUFFICIENT }
			const amount = String(this.approvedAmount).trim()
			if (amount !== '') {
				body.approvedAmount = Number(amount)
			}
			this.saving = true
			this.error = ''
			try {
				const url = generateUrl(`/apps/dossiq/api/subsidies/tussenrapportages/${encodeURIComponent(this.reportId)}/beoordelen`)
				const { data } = await axios.post(url, body)
				showSuccess(t('dossiq', 'Interim report assessed as voldoende.'))
				this.$emit('done', data)
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, t('dossiq', 'The interim report could not be assessed.'))
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

.grant-dialog__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.grant-dialog__error {
	color: var(--color-error-text);
}
</style>
