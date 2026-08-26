<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FlowDetailPage — the flow canvas, scoped to this app's flows.

  The SAME shared CnFlowDetail canvas OpenRegister, integriq and hermiq use,
  over the same native flow store — not a second, app-owned flow surface
  (ADR-065, ADR-098).

  Controls live in FlowDetailSidebar, rendered into Nextcloud's app sidebar by
  the manifest's `sidebarComponent`, so the canvas keeps the full width —
  mirrors openregister/src/views/flows/FlowDetailPage.vue exactly.

  @spec openspec/specs/automatic-actions/spec.md
-->
<template>
	<CnFlowDetail
		:id="$route.params.id"
		app="dossiq"
		@save="onSave"
		@run="onRun" />
</template>

<script>
import { CnFlowDetail, useFlowStore } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailPage',
	components: { CnFlowDetail },

	/**
	 * Share the one flow store with the toolbar's save/run handlers.
	 *
	 * @return {object} The setup bindings.
	 */
	setup() {
		return { store: useFlowStore() }
	},

	methods: {
		/**
		 * @return {Promise<void>}
		 */
		async onSave() {
			const saved = await this.store.save()
			// A newly created flow gets its id from the server, so the route has
			// to catch up or a reload would land back on `new`.
			if (saved?.id && this.$route.params.id === 'new') {
				this.$router.replace(`/flows/${saved.id}`)
			}
		},

		/**
		 * @return {Promise<void>}
		 */
		async onRun() {
			await this.store.run({})
		},
	},
}
</script>
