<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnAuditTrailCard
		v-if="resolvedRegister && resolvedSchema && resolvedObjectId"
		:register="resolvedRegister"
		:schema="resolvedSchema"
		:object-id="resolvedObjectId"
		:title="resolvedTitle" />
</template>

<script>
import { CnAuditTrailCard } from '@conduction/nextcloud-vue'

/**
 * AuditTrailWidget — thin adapter that renders the library's CnAuditTrailCard
 * under the manifest `audit-trail` widget key. CnAuditTrailCard needs
 * register/schema/objectId as props, but the two detail render paths surface
 * the current object differently: CnDetailPage's config-grid provides a
 * `cnObjectContext` inject, while CnPageRenderer's slot CnWidgetGrid spreads
 * the object context as props. This adapter reads whichever is present so the
 * same widget key works in both paths.
 */
export default {
	name: 'AuditTrailWidget',
	components: { CnAuditTrailCard },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},
	props: {
		/** Object context spread as props by CnWidgetGrid (slot path). */
		register: { type: String, default: '' },
		schema: { type: [String, Object], default: '' },
		objectId: { type: String, default: '' },
		/** Catalog widget content blob (CnDetailPage body path). */
		content: { type: Object, default: () => ({}) },
		title: { type: String, default: '' },
	},
	computed: {
		/**
		 * The resolved object-context bag from inject (either shape) or {}.
		 * @spec exclude presentational widget adapter — no behavioural spec; pure prop/inject resolution.
		 */
		ctx() {
			const inj = this.cnObjectContext && (this.cnObjectContext.value || this.cnObjectContext)
			const holder = this.cnDetailObjectContext && this.cnDetailObjectContext.value
			return inj || holder || {}
		},
		/** @spec exclude presentational widget adapter — derives objectId from props/inject/content. */
		resolvedObjectId() {
			return this.objectId || this.ctx.objectId || this.content.objectId || ''
		},
		/** @spec exclude presentational widget adapter — derives register from props/inject/content. */
		resolvedRegister() {
			return this.register || this.ctx.register || this.content.register || ''
		},
		/** @spec exclude presentational widget adapter — derives schema slug from props/inject/content. */
		resolvedSchema() {
			const s = this.schema || this.ctx.schema || this.content.schema || ''
			// The context may carry the schema as an object; CnAuditTrailCard wants a slug.
			return typeof s === 'string' ? s : (s && (s.slug || s.name || s.id)) || ''
		},
		/** @spec exclude presentational widget adapter — optional card title passthrough. */
		resolvedTitle() {
			return this.title || this.content.title || ''
		},
	},
}
</script>
