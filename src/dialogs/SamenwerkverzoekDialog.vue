<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('procest', 'Initiate Samenwerkverzoek')"
		:can-close="true"
		@close="$emit('close')">
		<template #default>
			<div class="samenwerk-dialog">
				<p class="samenwerk-dialog__intro">
					{{
						t(
							'procest',
							'Request collaboration from another bevoegd gezag for this vergunningaanvraag.',
						)
					}}
				</p>

				<NcTextField
					v-model="aangezochtBevoegdGezag"
					:label="t('procest', 'Aangezocht bevoegd gezag (OIN or name)')"
					:required="true"
					:placeholder="
						t('procest', 'e.g. Waterschap Amstel, Gooi en Vecht')
					" />

				<div class="samenwerk-dialog__suggestions">
					<NcButton
						v-for="org in commonOrganizations"
						:key="org"
						type="tertiary"
						@click="aangezochtBevoegdGezag = org">
						{{ org }}
					</NcButton>
				</div>

				<NcTextArea
					v-model="rationale"
					:label="t('procest', 'Rationale')"
					:placeholder="
						t('procest', 'Explain why collaboration is needed...')
					"
					rows="4" />

				<div v-if="error" class="samenwerk-dialog__error">
					{{ error }}
				</div>
			</div>
		</template>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!aangezochtBevoegdGezag || submitting"
				@click="submit">
				{{ t('procest', 'Initiate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'SamenwerkverzoekDialog',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		zaakId: {
			type: String,
			required: true,
		},
	},
	emits: ['close', 'initiated'],
	data() {
		return {
			aangezochtBevoegdGezag: '',
			rationale: '',
			submitting: false,
			error: null,
			commonOrganizations: [
				'Provincie Noord-Holland',
				'Waterschap Amstel, Gooi en Vecht',
				'Rijkswaterstaat',
			],
		}
	},
	methods: {
		t,
		async submit() {
			if (!this.aangezochtBevoegdGezag) {
				return
			}

			this.submitting = true
			this.error = null
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/procest/api/dso/cases/'
							+ encodeURIComponent(this.zaakId)
							+ '/samenwerking',
					),
					{
						aangezochtBevoegdGezag: this.aangezochtBevoegdGezag,
						rationale: this.rationale,
					},
				)
				this.$emit('initiated', data)
			} catch (e) {
				this.error = t(
					'procest',
					'Could not initiate samenwerkverzoek. Please try again.',
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.samenwerk-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.samenwerk-dialog__intro {
	color: var(--color-text-maxcontrast);
}

.samenwerk-dialog__suggestions {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.samenwerk-dialog__error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
