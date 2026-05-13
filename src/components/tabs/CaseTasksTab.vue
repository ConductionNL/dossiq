<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: tasks linked to a parent case.

 STUB — TODO(procest-case-relation-tabs): port the inline task list
 from the pre-manifest CaseDetail.vue. Should list tasks where
 task.case === parent.id with full CRUD via CnFormDialog driven by
 the `task` schema. Until then this renders a placeholder so the
 manifest's customComponents resolution succeeds.
-->
<template>
	<div class="procest-tab procest-tab--tasks">
		<CnNoteCard type="info" :title="t('procest', 'Tasks')">
			{{ t('procest', 'Task relation tab is being migrated. The full task list will appear here once procest-case-relation-tabs lands.') }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'CaseTasksTab',
	components: { CnNoteCard },
	props: {
		object: { type: Object, default: () => ({}) },
	},
}
</script>
