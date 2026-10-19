<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The fields of a case type on its CaseTypeDetail page, grouped by category, edited against OpenRegister's property type vocabulary.
-->
<template>
	<PropertiesTab :caseTypeId="caseTypeId" />
</template>

<script>
import PropertiesTab from '../../views/settings/tabs/PropertiesTab.vue'

export default {
	name: 'CaseTypeFieldsEditorWidget',
	components: { PropertiesTab },

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
