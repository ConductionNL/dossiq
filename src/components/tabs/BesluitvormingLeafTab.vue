<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  BesluitvormingLeafTab — consumes decidesk's "Besluitvorming" (decisions)
  integration leaf on the procest case-detail sidebar.

  "decidesk owns it; procest shows a leaf" (ADR-019 / ADR-022). decidesk
  registers a `decidesk-decisions` provider on the shared OpenRegister
  integration registry (`window.OCA.OpenRegister.integrations`) via a
  global init script that loads on every Nextcloud page. This wrapper
  resolves that provider's `tab` component at render time and forwards the
  host case's `{ register, schema, objectId }` as the integration context.

  Procest's host `<CnObjectSidebar>` runs with `:use-registry="false"`
  (manifest `component:`-based tabs), so the registry-driven tab strip is
  off; this thin wrapper is how a single registry leaf is surfaced through
  the `sidebarTabs[].component` path without flipping the whole sidebar to
  registry mode. CnObjectSidebar injects `objectId` / `register` / `schema`
  / `apiBase` via `sharedTabProps` — exactly the contract the decidesk leaf
  tab expects — so the leaf does its own OR fetch / list / create with zero
  per-app glue. This is consumption, not re-implementation.

  When the decidesk app (or its leaf bundle) is not deployed, the provider
  is absent from the registry and a quiet unavailable notice renders instead
  of a broken tab.
-->
<template>
	<div class="besluitvorming-leaf-tab">
		<component
			:is="leafComponent"
			v-if="leafComponent"
			:integration-id="integrationId"
			:register="register"
			:schema="schema"
			:object-id="objectId"
			:object-label="title"
			:integration-context="integrationContext" />
		<NcEmptyContent
			v-else
			:name="unavailableTitle"
			:description="unavailableDescription">
			<template #icon>
				<Gavel :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent } from '@nextcloud/vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'

/**
 * The decidesk decisions leaf id consuming apps reference to surface the
 * "Besluitvorming" leaf on an object's detail page / sidebar.
 *
 * @type {string}
 */
const DECISIONS_INTEGRATION_ID = 'decidesk-decisions'

/**
 * BesluitvormingLeafTab — render the decidesk decisions leaf tab for a case.
 *
 * Resolves the `decidesk-decisions` provider's tab component from the live
 * OpenRegister integration registry and forwards the host case context.
 * Falls back to an unavailable notice when decidesk's leaf is not loaded.
 */
export default {
	name: 'BesluitvormingLeafTab',

	components: { NcEmptyContent, Gavel },

	props: {
		/** UUID of the host case the decisions are linked to (CnObjectSidebar sharedTabProps). */
		objectId: { type: [String, Number], default: '' },
		/** OpenRegister register id of the host case (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the host case (slug or uuid). */
		schema: { type: String, default: '' },
		/** Human label for the host case, stored as subjectLabel on created decisions. */
		title: { type: String, default: '' },
	},

	data() {
		return {
			integrationId: DECISIONS_INTEGRATION_ID,
		}
	},

	computed: {
		/**
		 * The decidesk leaf's tab component, resolved from the shared OR
		 * integration registry at render time. `undefined` when decidesk's
		 * leaf bundle is not loaded on the page.
		 *
		 * @return {object|undefined} The decidesk leaf tab Vue component.
		 */
		leafComponent() {
			const reg = window.OCA?.OpenRegister?.integrations
			if (!reg || typeof reg.get !== 'function') {
				return undefined
			}
			const entry = reg.get(DECISIONS_INTEGRATION_ID)
			return entry ? entry.tab : undefined
		},

		/**
		 * Whole-context fallback forwarded to the leaf when it prefers the
		 * single `integrationContext` object over discrete props.
		 *
		 * @return {{register: string, schema: string, objectId: string}}
		 */
		integrationContext() {
			return {
				register: this.register,
				schema: this.schema,
				objectId: this.objectId ? String(this.objectId) : '',
			}
		},

		unavailableTitle() {
			return t('procest', 'Besluitvorming unavailable')
		},

		unavailableDescription() {
			return t('procest', 'The decidesk app provides decision-making for this case. Install or enable decidesk to manage proposals, advice and decisions here.')
		},
	},
}
</script>

<style scoped>
.besluitvorming-leaf-tab {
	padding: 4px 0;
}
</style>
