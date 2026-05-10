<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: decisions linked to a parent case.

 STUB — TODO(procest-case-relation-tabs): port the inline decision list
 from the pre-manifest CaseDetail.vue. Should list decisions where
 decision.case === parent.id with full CRUD driven by the `decision`
 schema.
-->
<template>
	<div class="procest-tab procest-tab--decisions">
		<CnNoteCard type="info" :title="t('procest', 'Decisions')">
			{{ t('procest', 'Decision relation tab is being migrated. The full decision list will appear here once procest-case-relation-tabs lands.') }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'CaseDecisionsTab',
	components: { CnNoteCard },
	props: {
		object: { type: Object, default: () => ({}) },
	},
}
</script>
