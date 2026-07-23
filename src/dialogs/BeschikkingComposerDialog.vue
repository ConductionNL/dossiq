<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog v-if="open"
		:name="t('procest', 'Beschikking opstellen')"
		size="large"
		:can-close="!submitting"
		@closing="onClose">
		<div class="beschikking-composer">
			<div v-if="!composed" class="beschikking-composer__form">
				<div class="beschikking-composer__field">
					<NcSelect v-model="templateId"
						:options="templateOptions"
						:input-label="t('procest', 'Sjabloon')"
						label="label"
						:reduce="opt => opt.value"
						:placeholder="t('procest', 'Selecteer een sjabloon')" />
				</div>
				<div class="beschikking-composer__field">
					<NcTextField :model-value="geadresseerdeNaam"
						:label="t('procest', 'Geadresseerde')"
						@update:model-value="v => geadresseerdeNaam = v" />
				</div>
				<div class="beschikking-composer__field">
					<NcTextArea :model-value="motivering"
						:label="t('procest', 'Motivering')"
						@update:model-value="v => motivering = v" />
				</div>
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</div>

			<div v-else class="beschikking-composer__preview">
				<NcNoteCard type="success">
					{{ t('procest', 'De beschikking is samengesteld als concept.') }}
				</NcNoteCard>
				<dl class="beschikking-composer__meta">
					<dt>{{ t('procest', 'Kenmerk') }}</dt>
					<dd>{{ composed.kenmerk || '—' }}</dd>
					<dt>{{ t('procest', 'Sjabloon') }}</dt>
					<dd>{{ composed.templateId }}</dd>
					<dt>{{ t('procest', 'Status') }}</dt>
					<dd>{{ composed.huidigeStatus }}</dd>
				</dl>
				<NcNoteCard v-if="composed.motivering_required" type="warning">
					{{ t('procest', 'De motivering ontbreekt nog en is verplicht.') }}
				</NcNoteCard>
				<NcNoteCard v-if="composed.geadresseerde_required" type="warning">
					{{ t('procest', 'De geadresseerde ontbreekt nog en is verplicht.') }}
				</NcNoteCard>
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton v-if="!composed"
				type="primary"
				:disabled="submitting"
				@click="onCompose">
				{{ t('procest', 'Opstellen') }}
			</NcButton>
			<NcButton v-else type="primary" @click="onDone">
				{{ t('procest', 'Klaar') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { compose } from '../services/beschikkingApi.js'

export default {
	name: 'BeschikkingComposerDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		zaakId: {
			type: String,
			required: true,
		},
		templateOptions: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'composed'],
	data() {
		return {
			templateId: null,
			geadresseerdeNaam: '',
			motivering: '',
			composed: null,
			submitting: false,
			error: '',
		}
	},
	methods: {
		async onCompose() {
			this.submitting = true
			this.error = ''
			try {
				const overrides = {}
				if (this.geadresseerdeNaam) {
					overrides.geadresseerde = { naam: this.geadresseerdeNaam }
				}
				if (this.motivering) {
					overrides.motivering = this.motivering
				}
				this.composed = await compose(this.zaakId, this.templateId, overrides)
				this.$emit('composed', this.composed)
			} catch (e) {
				this.error = t('procest', 'De beschikking kon niet worden opgesteld.')
			} finally {
				this.submitting = false
			}
		},
		onDone() {
			this.$emit('composed', this.composed)
			this.onClose()
		},
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.beschikking-composer__field {
	margin-block-end: 12px;
}

.beschikking-composer__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-block-start: 12px;
}

.beschikking-composer__meta dt {
	font-weight: bold;
}
</style>
