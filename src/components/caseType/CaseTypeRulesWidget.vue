<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The OpenRegister rules of the case schema, with a try-it against a case, on
  the CaseTypeDetail page. The rules belong to the case schema, not to one case
  type, so this is a candidate to move to OpenRegister's schema page.
-->
<template>
	<RulesTab />
</template>

<script>
import RulesTab from '../../views/settings/tabs/RulesTab.vue'

export default {
	name: 'CaseTypeRulesWidget',
	components: { RulesTab },
}
</script>
