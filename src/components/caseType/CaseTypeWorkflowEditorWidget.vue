<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The workflow canvas of a case type on its CaseTypeDetail page: statuses as nodes, transitions, steps, guards and publishing a workflow version.
-->
<template>
	<WorkflowTab :caseTypeId="caseTypeId" />
</template>

<script>
import WorkflowTab from '../../views/settings/tabs/WorkflowTab.vue'
import { caseTypeWidget } from '../../mixins/caseTypeWidget.js'

export default {
	name: 'CaseTypeWorkflowEditorWidget',
	components: { WorkflowTab },

	mixins: [caseTypeWidget],
}
</script>
