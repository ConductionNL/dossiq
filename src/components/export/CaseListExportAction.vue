<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Cases-page actions-slot component (case-list-export-via-or-export-leaf):
  an "Export" menu offering CSV and Excel downloads of the (filtered) case
  list via OpenRegister's export leaf.

  Wired as `pages[].actionsComponent` on the Cases index page — the v2
  CnPageRenderer resolves it into CnIndexPage's unscoped `#actions` slot, so
  this component receives no props. Filters come from the current route's
  query string (`$route.query`), falling back to `{}` when `$route` is
  unavailable (e.g. if ever mounted outside a router context). Clicking an
  entry builds the OpenRegister export-leaf URL (see caseExportHelpers.js)
  and navigates the browser to it — openregister serialises the CSV/Excel
  and enforces access as the current user (ADR-022: no procest-side
  serialization).

  @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
-->
<template>
	<NcActions :aria-label="t('procest', 'Export')">
		<template #icon>
			<TrayArrowDown :size="20" />
		</template>
		<NcActionButton @click="exportAs('csv')">
			{{ t('procest', 'Export as CSV') }}
		</NcActionButton>
		<NcActionButton @click="exportAs('excel')">
			{{ t('procest', 'Export as Excel') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { NcActionButton, NcActions } from '@nextcloud/vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import { buildCaseExportUrl } from '../../utils/caseExportHelpers.js'

export default {
	name: 'CaseListExportAction',
	components: {
		NcActionButton,
		NcActions,
		TrayArrowDown,
	},
	methods: {
		/**
		 * Build the export-leaf URL for the given format from the current
		 * route query and navigate the browser to it, triggering the
		 * download.
		 *
		 * @param {string} format `'csv'` or `'excel'`.
		 * @return {void}
		 */
		exportAs(format) {
			const query = this.$route?.query ?? {}
			window.location.assign(buildCaseExportUrl(format, query))
		},
	},
}
</script>
