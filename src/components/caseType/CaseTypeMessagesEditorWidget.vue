<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The messages a case type sends its applicant, on its CaseTypeDetail page: each change is a new version, with the variables to insert and a preview.
-->
<template>
	<EmailTemplateAdmin :caseTypeId="caseTypeId" />
</template>

<script>
import EmailTemplateAdmin from '../../views/casetypes/components/EmailTemplateAdmin.vue'

export default {
	name: 'CaseTypeMessagesEditorWidget',
	components: { EmailTemplateAdmin },

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},
	},
}
</script>
