<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -->
<template>
	<div class="woo-publication-panel">
		<div v-if="state === 'published'" class="woo-publication-panel__published">
			<span
				class="woo-publication-panel__badge woo-publication-panel__badge--published">
				{{ t('procest', 'Published') }}
			</span>
			<a
				v-if="publicationUrl"
				:href="publicationUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('procest', 'View publication') }}
			</a>
			<NcButton type="tertiary" :disabled="busy" @click="withdraw">
				{{ t('procest', 'Withdraw') }}
			</NcButton>
		</div>

		<div
			v-else-if="state === 'unavailable'"
			class="woo-publication-panel__unavailable">
			<span
				class="woo-publication-panel__badge woo-publication-panel__badge--unavailable">
				{{ t('procest', 'Publication unavailable') }}
			</span>
			<p>{{ unavailableMessage }}</p>
			<NcButton type="secondary" :disabled="busy" @click="publish">
				{{ t('procest', 'Retry') }}
			</NcButton>
		</div>

		<div v-else class="woo-publication-panel__pending">
			<NcButton type="primary" :disabled="busy" @click="publish">
				{{ t('procest', 'Publish (Woo)') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import {
	publishWooDecision,
	withdrawWooPublication,
} from '../../../services/wooPublicationApi.js'

export default {
	name: 'WooPublicationPanel',
	components: { NcButton },
	props: {
		caseId: {
			type: String,
			required: true,
		},
		decisionId: {
			type: String,
			required: true,
		},
		initialStatus: {
			type: String,
			default: 'pending',
		},
		initialPublicationUrl: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			state: this.initialStatus === 'published' ? 'published' : 'pending',
			publicationUrl: this.initialPublicationUrl,
			unavailableMessage: '',
			busy: false,
		}
	},
	methods: {
		/**
		 * Publish the WOO decision to OpenCatalogi.
		 *
		 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
		 */
		async publish() {
			this.busy = true
			try {
				const result = await publishWooDecision(this.caseId, this.decisionId)
				if (result && result.available) {
					this.state = 'published'
					this.publicationUrl =
						result.publicationUrl || this.publicationUrl
					this.$emit('published', result)
				} else {
					this.state = 'unavailable'
					this.unavailableMessage = this.mapReason(result && result.reason)
				}
			} catch (error) {
				this.state = 'unavailable'
				this.unavailableMessage = this.mapReason(null)
			} finally {
				this.busy = false
			}
		},
		/**
		 * Withdraw the WOO publication from OpenCatalogi.
		 *
		 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
		 */
		async withdraw() {
			this.busy = true
			try {
				const result = await withdrawWooPublication(
					this.caseId,
					this.decisionId,
				)
				if (result && result.available) {
					this.state = 'pending'
					this.publicationUrl = ''
					this.$emit('withdrawn', result)
				}
			} finally {
				this.busy = false
			}
		},
		/**
		 * Map a backend unavailability reason to a human message.
		 *
		 * @param {string} reason The reason code.
		 * @return {string} A localized message.
		 */
		mapReason(reason) {
			if (reason === 'opencatalogi_not_installed') {
				return this.t(
					'procest',
					'OpenCatalogi is not installed on this instance. Ask an administrator to enable it to publish Woo decisions.',
				)
			}
			if (reason === 'openregister_unavailable') {
				return this.t('procest', 'OpenRegister is not available.')
			}
			if (reason === 'no_publishable_documents') {
				return this.t(
					'procest',
					'No documents are ready to publish yet. Documents marked "not public" are never published, and partially public documents need a finalized redaction first.',
				)
			}
			return this.t('procest', 'The publication could not be sent.')
		},
	},
}
</script>

<style scoped>
.woo-publication-panel__badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	margin-bottom: 8px;
}

.woo-publication-panel__badge--published {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.woo-publication-panel__badge--unavailable {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.woo-publication-panel__published,
.woo-publication-panel__unavailable {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
}
</style>
