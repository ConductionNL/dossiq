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
	<GrantStepDialog
		:name="t('dossiq', 'Assess an interim report')"
		testid="interim-report-assess"
		:submitLabel="t('dossiq', 'Assess')"
		:loading="loading"
		:empty="reports.length === 0 ? t('dossiq', 'This case has no interim report waiting for an assessment.') : ''"
		:error="error"
		:canSubmit="canAssess"
		:saving="saving"
		@close="$emit('close')"
		@submit="assess">
		<NcSelect
			v-model="reportId"
			data-testid="interim-report-assess-report"
			:inputLabel="t('dossiq', 'Interim report')"
			:options="reportOptions"
			:reduce="(option) => option.id"
			label="label" />
		<NcTextField
			v-model="approvedAmount"
			data-testid="interim-report-assess-amount"
			:label="t('dossiq', 'Approved amount (optional)')"
			type="number" />
		<template #note>
			{{ reports.length === 0 ? '' : t('dossiq', 'The report is approved with the assessment voldoende (sufficient).') }}
		</template>
		<template #icon>
			<ClipboardCheckOutline :size="20" />
		</template>
	</GrantStepDialog>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import GrantStepDialog from './GrantStepDialog.vue'
import { executionLabel, grantStep } from './grantChain.js'

/** The assessment the approve route records. */
const SUFFICIENT = 'voldoende'

export default {
	name: 'InterimReportAssessDialog',

	components: { ClipboardCheckOutline, GrantStepDialog, NcSelect, NcTextField },

	mixins: [grantStep],

	data() {
		return {
			reportId: null,
			approvedAmount: '',
		}
	},

	computed: {
		/**
		 * The interim reports of the case's grants not assessed yet, each with its grant.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		reports() {
			return this.executions.flatMap((execution) => (Array.isArray(execution.interimReports) ? execution.interimReports : [])
				.filter((report) => report.assessed !== true)
				.map((report) => ({ ...report, grant: executionLabel(execution) })))
		},

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
			const amount = String(this.approvedAmount).trim()
			return Boolean(this.reportId) && (amount === '' || Number(amount) >= 0)
		},
	},

	methods: {
		/**
		 * The only open report of the case is the one assessed.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		afterLoad() {
			if (this.reports.length === 1) {
				this.reportId = this.reports[0].id
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
			await this.send(
				`tussenrapportages/${encodeURIComponent(this.reportId)}/beoordelen`,
				body,
				() => this.t('dossiq', 'Interim report assessed as voldoende.'),
				this.t('dossiq', 'The interim report could not be assessed.'),
			)
		},
	},
}
</script>
