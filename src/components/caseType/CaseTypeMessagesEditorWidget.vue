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
import { caseTypeWidget } from '../../mixins/caseTypeWidget.js'

export default {
	name: 'CaseTypeMessagesEditorWidget',
	components: { EmailTemplateAdmin },

	mixins: [caseTypeWidget],
}
</script>
