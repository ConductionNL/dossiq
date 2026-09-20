<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  ApprovalChainLeafTab — consumes decidiq's approval-chain render leaf on the
  document record a file row resolves to.

  "decidiq owns it; dossiq shows a leaf" (ADR-019 / ADR-022). A concept letter
  goes round three people before it is sent, and dossiq could hold that route
  for exactly one kind of document, a beschikking. Every other document went
  round by e-mail and the case kept no record of who agreed to what. Parafering
  was retired on the rule that sign-off belongs to decidiq, which was right,
  and it left a hole nobody filled: the engine moved and no surface took its
  place.

  This is built the way BesluitvormingLeafTab.vue is built, on purpose, so one
  pattern covers both decidiq leaves and neither drifts. Two render modes,
  because the registry carries both (ADR-066): a COMPONENT leaf exposes `tab`,
  and a MOUNT leaf exposes `mount(el, props)` / `unmount(el)` and no `tab` at
  all because it runs its own framework instance from its own bundle.

  🔑 decidiq's approval-chain leaf declares renderMode MOUNT. Read against
  decidiq `parity/round2`, `lib/Listener/RegisterApprovalChainLeafListener.php`:
  `renderMode: RENDER_MODE_MOUNT`, `kinds: [KIND_RENDER_SURFACE]`, no
  IntegrationProvider, and `loadStrategy: LOADS_VIA_OWN_SCRIPT` — the
  registration ships in `decidiq-integration-init.js`, added on every page by
  `Util::addInitScript` in `Application::boot`. THERE IS NO `decidiq-leaves.js`
  AND ITS ABSENCE IS NOT EVIDENCE THE SURFACE IS DARK; the listener's own
  docblock says so. That is the check task 1.1 asked for, answered from the
  registration rather than from a network log.

  🔴 IT SITS ON THE DOCUMENT RECORD, NOT THE FILE ROW. The leaf takes a
  register, a schema and an object id, and a file is not an object. The
  informatieobject is, so the surface renders as a section of the document
  properties, next to the metadata the same person maintains.

  When decidiq (or its leaf bundle) is not deployed the leaf is absent from the
  registry and a notice says so. It must NOT render an empty timeline: an empty
  timeline reads as "nobody has approved anything", which is a claim about the
  document that nobody made.
-->
<template>
	<div class="approval-chain-leaf-tab">
		<component
			:is="leafComponent"
			v-if="leafComponent"
			:integrationId="integrationId"
			:register="register"
			:schema="schema"
			:objectId="objectId"
			:objectLabel="title"
			:integrationContext="integrationContext" />
		<CnLeafMountHost
			v-else-if="isMountLeaf"
			:provider="leafEntry"
			:mountProps="mountProps" />
		<NcEmptyContent
			v-else
			:name="unavailableTitle"
			:description="unavailableDescription">
			<template #icon>
				<CheckDecagramOutline :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { CnLeafMountHost } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent } from '@nextcloud/vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'

/**
 * The approval-chain leaf id consuming apps reference.
 *
 * It is decidiq's `RegisterApprovalChainLeafListener::LEAF_ID` and its JS
 * half's `APPROVAL_CHAIN_INTEGRATION_ID`, which are the same string by
 * construction there. It carries the NEW app name, unlike the decisions leaf,
 * whose id predates the rename.
 *
 * @type {string}
 */
const APPROVAL_CHAIN_INTEGRATION_ID = 'decidiq-approval-chain'

/**
 * ApprovalChainLeafTab — render decidiq's approval chain for one document.
 */
export default {
	name: 'ApprovalChainLeafTab',

	components: { CnLeafMountHost, NcEmptyContent, CheckDecagramOutline },

	props: {
		/** UUID of the informatieobject the route is held against. */
		objectId: { type: [String, Number], default: '' },
		/** OpenRegister register id of the document (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the document (slug or uuid). */
		schema: { type: String, default: '' },
		/** Human label for the document. */
		title: { type: String, default: '' },
	},

	data() {
		return {
			integrationId: APPROVAL_CHAIN_INTEGRATION_ID,
		}
	},

	computed: {
		/**
		 * The decidiq provider entry from the shared OR integration registry,
		 * resolved AT RENDER TIME. `undefined` when decidiq's leaf bundle is
		 * not loaded on the page.
		 *
		 * @return {object|undefined} The registry entry.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		leafEntry() {
			const reg = window.OCA?.OpenRegister?.integrations
			if (!reg || typeof reg.get !== 'function') {
				return undefined
			}

			return reg.get(APPROVAL_CHAIN_INTEGRATION_ID) || undefined
		},

		/**
		 * The leaf's tab component, for a provider that ships one.
		 *
		 * @return {object|undefined} The leaf tab Vue component.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		leafComponent() {
			return this.leafEntry ? this.leafEntry.tab || undefined : undefined
		},

		/**
		 * Whether the provider is a mount-mode leaf (ADR-066). decidiq's
		 * approval chain declares this mode, so this is the branch that
		 * normally renders.
		 *
		 * @return {boolean} True for a mount-mode leaf.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		isMountLeaf() {
			const entry = this.leafEntry

			return Boolean(
				entry
				&& entry.renderMode === 'mount'
				&& typeof entry.mount === 'function'
				&& typeof entry.unmount === 'function',
			)
		},

		/**
		 * The context the leaf is given: the DOCUMENT record, not the case.
		 *
		 * @return {{register: string, schema: string, objectId: string}} The context.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		integrationContext() {
			return {
				register: this.register,
				schema: this.schema,
				objectId: this.objectId ? String(this.objectId) : '',
			}
		},

		/**
		 * The prop bag a mount-mode leaf receives.
		 *
		 * `surface` decides which of decidiq's two roots it mounts:
		 * `componentForSurface()` roots the WIDGET on the dashboard and
		 * detail-page surfaces and the TIMELINE everywhere else. A document
		 * properties section wants the widget, which is the one carrying the
		 * current step, its due date and approve and reject for the current
		 * actor, so the surface is named rather than left to a default.
		 *
		 * @return {object} The mount props.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		mountProps() {
			return {
				surface: 'detail-page',
				integrationId: this.integrationId,
				...this.integrationContext,
				objectLabel: this.title,
				integrationContext: this.integrationContext,
			}
		},

		/**
		 * The heading shown when decidiq's leaf is not loaded.
		 *
		 * @return {string} The heading.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		unavailableTitle() {
			return t('dossiq', 'Approval chain unavailable')
		},

		/**
		 * What to do about it.
		 *
		 * @return {string} The description.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		unavailableDescription() {
			return t(
				'dossiq',
				'The decidiq app holds the review route for a document. Install or enable decidiq to send this document round and to read who agreed to what.',
			)
		},
	},
}
</script>

<style scoped>
.approval-chain-leaf-tab {
	padding: 4px 0;
}
</style>
