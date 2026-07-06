<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Initiator display section for the manifest CaseDetail overview
  (brp-kvk-register-sets / initiator-display). Self-fetching from the route
  case id; renders NOTHING when the case has no initiator fields ("no
  initiator, no clutter"). For person/company initiators the source id
  resolves to the seeded brpPerson/kvkCompany register object and links to
  its OpenRegister object view (#/objects/:register/:schema/:id); contact
  initiators show the reference as plain text.

  @spec openspec/specs/initiator-display/spec.md
-->
<template>
	<div v-if="hasInitiator" class="initiator-section" data-testid="initiator-section">
		<div class="initiator-section__row">
			<component :is="typeIcon" :size="20" class="initiator-section__icon" />
			<span class="initiator-section__name">{{ caseObject.initiatorDisplayName }}</span>
			<span class="initiator-section__type">{{ typeLabel }}</span>
		</div>
		<div class="initiator-section__source">
			<a v-if="sourceLink"
				:href="sourceLink"
				target="_blank"
				rel="noopener noreferrer">
				{{ caseObject.initiatorSourceId }}
			</a>
			<span v-else>{{ caseObject.initiatorSourceId }}</span>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import CardAccountMailOutline from 'vue-material-design-icons/CardAccountMailOutline.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'InitiatorSection',
	components: {
		AccountOutline,
		Domain,
		CardAccountMailOutline,
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
			return !!(this.caseObject && this.caseObject.initiatorType && this.caseObject.initiatorDisplayName)
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
			return generateUrl(`/apps/openregister/#/objects/procest/${this.typeSchema}/${this.sourceObjectId}`)
		},
	},
	async mounted() {
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
				this.caseObject = await this.objectStore.fetchObject('case', caseId) || {}
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
			const keyField = this.caseObject.initiatorType === 'person' ? 'burgerservicenummer' : 'kvkNummer'
			try {
				const rows = await this.objectStore.fetchCollection(this.typeSchema, {
					[keyField]: this.caseObject.initiatorSourceId,
					_limit: 1,
				})
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
}
</style>
