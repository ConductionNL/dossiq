<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Initiator + handoff-provenance display for the manifest CaseDetail overview
  (brp-kvk-register-sets / initiator-display + semantic-case-intake).
  Self-fetching from the route case id; renders NOTHING when the case has
  neither initiator fields nor a handoff source ("no initiator, no clutter").
  For person/company initiators the source id resolves to the seeded
  brpPerson/kvkCompany register object and links to its OpenRegister object
  view (#/objects/:register/:schema/:id); contact initiators show the
  reference as plain text. When the case arrived via the ns#Case semantic
  handoff it carries a handoffSource back-link — surfaced with an origin
  badge, the received-at timestamp, and a link to the source object.

  @spec openspec/specs/initiator-display/spec.md
  @spec openspec/specs/semantic-case-intake/spec.md
-->
<template>
	<div
		v-if="hasInitiator || hasHandoff"
		class="initiator-section"
		data-testid="initiator-section">
		<template v-if="hasInitiator">
			<div class="initiator-section__row">
				<component
					:is="typeIcon"
					:size="20"
					class="initiator-section__icon" />
				<span class="initiator-section__name">{{
					caseObject.initiatorDisplayName
				}}</span>
				<span class="initiator-section__type">{{ typeLabel }}</span>
			</div>
			<div class="initiator-section__source">
				<a
					v-if="sourceLink"
					:href="sourceLink"
					target="_blank"
					rel="noopener noreferrer">
					{{ caseObject.initiatorSourceId }}
				</a>
				<span v-else>{{ caseObject.initiatorSourceId }}</span>
			</div>
		</template>

		<!-- Handoff provenance (semantic-case-intake): when the case arrived
		     via the ns#Case handoff it carries a handoffSource back-link to the
		     originating object; surface the origin badge + the received-at
		     timestamp (the case's creation time = the handoff moment). -->
		<div
			v-if="hasHandoff"
			class="initiator-section__handoff"
			data-testid="handoff-provenance">
			<TransitConnectionVariant :size="18" class="initiator-section__icon" />
			<span class="initiator-section__handoff-label">{{
				t('procest', 'Received via handoff')
			}}</span>
			<span v-if="handoffReceivedAt" class="initiator-section__type">{{
				handoffReceivedAt
			}}</span>
			<a
				class="initiator-section__handoff-link"
				:href="handoffSourceLink"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('procest', 'Open source object') }}
			</a>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import CardAccountMailOutline from 'vue-material-design-icons/CardAccountMailOutline.vue'
import TransitConnectionVariant from 'vue-material-design-icons/TransitConnectionVariant.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'InitiatorSection',
	components: {
		AccountOutline,
		Domain,
		CardAccountMailOutline,
		TransitConnectionVariant,
	},
	data() {
		return {
			caseObject: {},
			sourceObjectId: null,
		}
	},
	computed: {
		/** @spec openspec/specs/initiator-display/spec.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/specs/initiator-display/spec.md */
		hasInitiator() {
			return !!(
				this.caseObject
				&& this.caseObject.initiatorType
				&& this.caseObject.initiatorDisplayName
			)
		},
		/** @spec openspec/specs/initiator-display/spec.md */
		typeLabel() {
			switch (this.caseObject.initiatorType) {
				case 'company':
					return t('procest', 'Company')
				case 'contact':
					return t('procest', 'Contact')
				default:
					return t('procest', 'Person')
			}
		},
		/** @spec openspec/specs/initiator-display/spec.md */
		typeIcon() {
			switch (this.caseObject.initiatorType) {
				case 'company':
					return 'Domain'
				case 'contact':
					return 'CardAccountMailOutline'
				default:
					return 'AccountOutline'
			}
		},
		/** @spec openspec/specs/initiator-display/spec.md */
		typeSchema() {
			switch (this.caseObject.initiatorType) {
				case 'person':
					return 'brpPerson'
				case 'company':
					return 'kvkCompany'
				default:
					return null
			}
		},
		/** @spec openspec/specs/initiator-display/spec.md */
		sourceLink() {
			if (!this.typeSchema || !this.sourceObjectId) {
				return null
			}
			return generateUrl(
				`/apps/openregister/#/objects/procest/${this.typeSchema}/${this.sourceObjectId}`,
			)
		},
		/** @spec openspec/specs/semantic-case-intake/spec.md */
		hasHandoff() {
			return !!(this.caseObject && this.caseObject.handoffSource)
		},
		/**
		 * Deep-link to the originating object behind the handoffSource
		 * back-link, via OpenRegister's URN resolver (app-agnostic — the
		 * source may live in any register/app per ADR-051).
		 *
		 * @spec openspec/specs/semantic-case-intake/spec.md
		 */
		handoffSourceLink() {
			if (!this.caseObject.handoffSource) {
				return null
			}
			return generateUrl(
				`/apps/openregister/api/urn/resolve?urn=${encodeURIComponent(this.caseObject.handoffSource)}`,
			)
		},
		/**
		 * The handoff moment = the case's creation timestamp (the case is
		 * created at handoff execution). Formatted for display; empty when
		 * unavailable.
		 *
		 * @spec openspec/specs/semantic-case-intake/spec.md
		 */
		handoffReceivedAt() {
			const raw =
				this.caseObject['@self']?.created
				|| this.caseObject.created
				|| this.caseObject.startDate
			if (!raw) {
				return ''
			}
			const date = new Date(raw)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},
	},
	async mounted() {
		// CnAppRoot mounts manifest slot widgets before App.vue's
		// initializeStores() has resolved the app-config, so the 'case'
		// object type may not be registered yet — await it here
		// (idempotent), same pattern as OverdueCasesWidget.
		await initializeStores()
		await this.load()
	},
	methods: {
		/**
		 * Load the case and resolve the initiator's source register object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async load() {
			const caseId = this.$route?.params?.id
			if (!caseId) {
				return
			}
			try {
				this.caseObject =
					(await this.objectStore.fetchObject('case', caseId)) || {}
			} catch (err) {
				console.error('[InitiatorSection] case load failed', err)
				this.caseObject = {}
				return
			}
			await this.resolveSource()
		},
		/**
		 * Resolve the source register object id behind the initiatorSourceId
		 * so the number can deep-link to the seeded record.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async resolveSource() {
			if (!this.typeSchema || !this.caseObject.initiatorSourceId) {
				return
			}
			const keyField =
				this.caseObject.initiatorType === 'person'
					? 'citizen_service_number'
					: 'kvkNumber'
			try {
				const rows = await this.objectStore.fetchCollection(
					this.typeSchema,
					{
						[keyField]: this.caseObject.initiatorSourceId,
						_limit: 1,
					},
				)
				const match = (rows || [])[0]
				this.sourceObjectId = match?.id || match?.['@self']?.id || null
			} catch (err) {
				// Link resolution is best-effort — the plain value still renders.
				this.sourceObjectId = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.initiator-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);

	&__row {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__name {
		font-weight: bold;
	}

	&__type {
		color: var(--color-text-maxcontrast);
	}

	&__source {
		color: var(--color-text-maxcontrast);
	}

	&__handoff {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding-top: var(--default-grid-baseline);
	}

	&__handoff-label {
		font-weight: bold;
	}
}
</style>
