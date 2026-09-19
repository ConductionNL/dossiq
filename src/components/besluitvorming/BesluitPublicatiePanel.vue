<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -->
<template>
	<div class="besluit-publicatie-panel">
		<p
			v-if="refusalMessage"
			class="besluit-publicatie-panel__refusal"
			data-testid="besluit-publicatie-refusal">
			{{ refusalMessage }}
		</p>

		<div v-if="state === 'success'" class="besluit-publicatie-panel__success">
			<span
				class="besluit-publicatie-panel__badge besluit-publicatie-panel__badge--success">
				{{ t('dossiq', 'Gepubliceerd') }}
			</span>
			<a
				v-if="reference"
				:href="reference"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('dossiq', 'View publication in DROP/LVBB') }}
			</a>
		</div>

		<div v-else-if="state === 'failed'" class="besluit-publicatie-panel__failed">
			<span
				class="besluit-publicatie-panel__badge besluit-publicatie-panel__badge--failed">
				{{ t('dossiq', 'Publicatie mislukt') }}
			</span>
			<p>
				{{
					errorMessage || t('dossiq', 'The publication could not be sent.')
				}}
			</p>
			<NcButton type="primary" :disabled="busy || !!refusal" @click="retry">
				{{ t('dossiq', 'Opnieuw proberen') }}
			</NcButton>
		</div>

		<div v-else class="besluit-publicatie-panel__pending">
			<span class="besluit-publicatie-panel__badge">
				{{ t('dossiq', 'Publicatie in behandeling') }}
			</span>
			<NcButton type="secondary" :disabled="busy || !!refusal" @click="retry">
				{{ t('dossiq', 'Nu publiceren') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { publishBesluit } from '../../services/besluitvormingApi.js'
import {
	fetchCaseParties,
	fetchParty,
	indicatorsOf,
	publicationRefusal,
} from '../../services/caseParties.js'

export default {
	name: 'BesluitPublicatiePanel',
	components: { NcButton },
	props: {
		caseId: {
			type: String,
			required: true,
		},

		initialState: {
			type: String,
			default: 'pending',
		},

		publicationReference: {
			type: String,
			default: '',
		},
	},

	emits: ['published'],

	data() {
		return {
			state: this.initialState,
			reference: this.publicationReference,
			errorMessage: '',
			busy: false,
			/** The indicator on a party of this case that refuses publication. */
			refusal: null,
		}
	},

	computed: {
		/**
		 * Why publishing this decision is refused, in words.
		 *
		 * An indicator whose effect is refuse-publication lives on a PARTY of
		 * the case, so it reaches every case that party is on without any of
		 * them being written. Naming it here is what stops a handler pressing
		 * a button that is going to be refused and being told only that
		 * something went wrong.
		 *
		 * @return {string} The sentence, '' when nothing refuses it.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		refusalMessage() {
			if (!this.refusal) {
				return ''
			}
			return this.t(
				'dossiq',
				'Publishing is refused by the indicator {indicator} on {party}.',
				{
					indicator: this.refusal.label || this.refusal.key,
					party: this.refusal.partyName || this.refusal.party,
				},
			)
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * Read what the parties of this case refuse.
			 *
			 * @return {void}
			 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
			 */
			handler() {
				this.readRefusal()
			},
		},
	},

	methods: {
		/**
		 * Read the indicators of this case's parties, and keep the one that
		 * refuses publication.
		 *
		 * Best effort: an instance whose OpenRegister predates the party
		 * model answers nothing, and the publication then behaves exactly as
		 * it did before. The refusal is enforced inside OpenRegister at the
		 * publish itself, so this is the sentence a handler sees rather than
		 * the only thing standing in the way.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		async readRefusal() {
			this.refusal = null
			const listing = await fetchCaseParties(this.caseId)
			if (!listing) {
				return
			}
			const uuids = [
				...new Set(
					(listing.results || [])
						.map((row) => row && row.partyUuid)
						.filter(Boolean),
				),
			]
			const records = await Promise.all(uuids.map((uuid) => fetchParty(uuid)))
			this.refusal = publicationRefusal(indicatorsOf(records.filter(Boolean)))
		},

		/**
		 * Trigger (retry) the DROP/LVBB publication.
		 *
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		async retry() {
			// The refusal is checked HERE, at the act, and not only drawn
			// above the button: a guard that lives beside a button is a guard
			// the next caller of this method does not have.
			if (this.refusal) {
				this.state = 'failed'
				this.errorMessage = this.refusalMessage
				return
			}

			this.busy = true
			this.errorMessage = ''
			try {
				const result = await publishBesluit(this.caseId)
				if (result && result.ok) {
					this.state = 'success'
					this.reference = result.publicatieReferentie || this.reference
					this.$emit('published', result)
				} else {
					this.state = 'failed'
					this.errorMessage = this.mapError(result && result.error)
				}
			} catch (error) {
				this.state = 'failed'
				this.errorMessage = this.t(
					'dossiq',
					'The publication could not be sent.',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Map a backend error code to a human message.
		 *
		 * @param {string} code The error code.
		 * @return {string} A localized message.
		 *
		 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-opencatalogi-absence-is-handled-gracefully
		 */
		mapError(code) {
			if (code === 'not_configured') {
				return this.t('dossiq', 'No DROP/LVBB endpoint has been configured.')
			}
			if (code === 'no_decision') {
				return this.t(
					'dossiq',
					'No decision has been recorded to publish yet.',
				)
			}
			return this.t('dossiq', 'The publication could not be sent.')
		},
	},
}
</script>

<style scoped>
.besluit-publicatie-panel__refusal {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background: var(--color-error);
	color: var(--color-primary-element-text);
	margin-bottom: 8px;
}

.decision-publicatie-panel__badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	margin-bottom: 8px;
}

.decision-publicatie-panel__badge--success {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.decision-publicatie-panel__badge--failed {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}
</style>
