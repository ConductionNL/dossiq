<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnCountTablesReport
		v-bind="report"
		:objectId="startCase"
		data-testid="case-mail-parties" />
</template>

<script>
import { CnCountTablesReport } from '@conduction/nextcloud-vue'
import { mailPartiesReport } from './mailPartiesReport.js'

/**
 * The mail parties report of one case on the reports dashboard: nextcloud-vue's
 * CnCountTablesReport with this report's config, starting on the case the
 * address names (`?case=`).
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */
export default {
	name: 'MailPartiesReportWidget',

	components: { CnCountTablesReport },

	data() {
		return { report: mailPartiesReport }
	},

	computed: {
		/**
		 * The case the address names, or ''.
		 *
		 * @return {string}
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
		 */
		startCase() {
			const named = this.$route?.query?.case
			return typeof named === 'string' ? named : ''
		},
	},
}
</script>
