<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: documents linked to a parent case.

 STUB — TODO(procest-case-relation-tabs): port the inline document list
 from the pre-manifest CaseDetail.vue. Should list caseDocument records
 where caseDocument.case === parent.id with upload/replace/delete CRUD.
-->
<template>
	<div class="procest-tab procest-tab--documents">
		<CnNoteCard type="info" :title="t('procest', 'Documents')">
			{{ t('procest', 'Documents relation tab is being migrated. The full document list will appear here once procest-case-relation-tabs lands.') }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'CaseDocumentsTab',
	components: { CnNoteCard },
	props: {
		object: { type: Object, default: () => ({}) },
	},
}
</script>
