<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Flows — Dossiq's scoped view over the one shared flow store.

  Passes `app: 'dossiq'`: this app sees only its own flows. OpenRegister's own
  Flows page passes no app filter and shows every app's (that surface is the
  fleet-wide one; this is the leaf-app one).

  This page replaces the `/apps/openregister/#/flows` deep link the settings
  menu used to carry. Per ADR-110 Decision 4 a flow is app-specific — a dossiq
  flow operates on cases — so the authoring surface belongs in the app whose
  objects it drives, not behind a link to somebody else's list.

  Rendered on CnIndexPage: a flow list is an ordinary index surface. The SOURCE
  is external (`:objects` from useFlowStore) because a flow is not an
  OpenRegister object, so there is no register/schema pair for a `type:index`
  page to bind. Both built-in row actions are replaced: the built-in Edit opens
  the schema-driven form dialog (nothing to render without a schema), and a
  flow has no read-only detail page for View — the canvas IS the flow.

  Mirrors openconnector/src/views/Flow/FlowsIndex.vue.

  @spec openspec/specs/automatic-actions/spec.md
-->
<template>
	<CnIndexPage
		:title="t('dossiq', 'Flows')"
		:description="
			t(
				'dossiq',
				'A flow runs a series of steps when something happens — an object changes, a schedule fires, or you run it yourself.',
			)
		"
		:columns="columns"
		:objects="rows"
		:loading="store.loading"
		:selectable="false"
		:showAdd="false"
		:showViewAction="false"
		:showEditAction="false"
		:actions="rowActions"
		rowClickToView
		@rowClick="openFlow">
		<template #header-actions>
			<NcButton variant="primary" @click="createFlow">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('dossiq', 'New flow') }}
			</NcButton>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, useFlowStore } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'FlowsIndex',

	components: {
		CnIndexPage,
		NcButton,
		// Pencil is deliberately NOT registered: it is passed as an icon
		// COMPONENT in `rowActions`, never used as a tag in this template.
		Plus,
	},

	/**
	 * Share the one flow store with the editor pages.
	 *
	 * @return {object} The setup bindings.
	 */
	setup() {
		return { store: useFlowStore() }
	},

	computed: {
		/**
		 * @return {Array<object>} The row-action menu: Edit, and only Edit.
		 */
		rowActions() {
			return [
				{
					label: this.t('dossiq', 'Edit'),
					icon: Pencil,
					handler: (row) => this.openFlow(row),
				},
			]
		},

		/**
		 * @return {Array<object>} The column definitions.
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('dossiq', 'Name') },
				{ key: 'description', label: this.t('dossiq', 'Description') },
				{ key: 'trigger', label: this.t('dossiq', 'Trigger') },
				{ key: 'cron', label: this.t('dossiq', 'Schedule') },
				{ key: 'statusLabel', label: this.t('dossiq', 'Status') },
			]
		},

		/**
		 * The flows with the status rendered for display.
		 *
		 * @return {Array<object>} The rows.
		 */
		rows() {
			return (this.store.flows || []).map((flow) => ({
				...flow,
				statusLabel: this.statusLabel(flow),
			}))
		},
	},

	created() {
		this.store.load({ app: 'dossiq' })
	},

	methods: {
		/**
		 * Enabled and dispatchable are NOT the same thing: a trigger fires with
		 * no acting user, so a flow with no owner has no identity to run as and
		 * will not start however enabled it looks.
		 *
		 * @param {object} flow The flow.
		 * @return {string} The label.
		 */
		statusLabel(flow) {
			if (!flow.enabled) {
				return this.t('dossiq', 'Disabled')
			}
			if (!flow.owner) {
				return this.t(
					'dossiq',
					'Enabled, but has no owner — it will not start',
				)
			}

			return this.t('dossiq', 'Enabled')
		},

		/**
		 * @param {object} flow The activated flow.
		 * @return {void}
		 */
		openFlow(flow) {
			const id = flow?.id || flow?.uuid
			if (!id) {
				return
			}

			this.$router.push(`/flows/${id}`)
		},

		/**
		 * @return {void}
		 */
		createFlow() {
			this.$router.push('/flows/new')
		},
	},
}
</script>
