<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The statuses of a case type on its CaseTypeDetail page: add, edit (checklist and field rules included), delete and drag into order.
-->
<template>
	<StatusesTab :caseTypeId="caseTypeId" />
</template>

<script>
import StatusesTab from '../../views/settings/tabs/StatusesTab.vue'

export default {
	name: 'CaseTypeStatusesEditorWidget',
	components: { StatusesTab },

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/changes/case-type-legacy-editor-retire/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},
	},
}
</script>
