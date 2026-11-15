<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The OpenRegister rules of the case schema, with a try-it against a case, on
  the CaseTypeDetail page. The rules belong to the case schema, not to one case
  type, so this is a candidate to move to OpenRegister's schema page.
-->
<template>
	<RulesTab :caseTypeId="caseTypeId" :summary="content.mode === 'summary'" />
</template>

<script>
import RulesTab from '../../views/settings/tabs/RulesTab.vue'

export default {
	name: 'CaseTypeRulesWidget',
	components: { RulesTab },

	props: {
		/**
		 * The widget content. `mode: "summary"` lists only the case type's own
		 * rules, for the Overview tab (DqZaaktypeOverzicht Regels).
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},
	},
}
</script>
