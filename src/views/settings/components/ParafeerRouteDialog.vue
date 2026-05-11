<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog v-if="open"
		:name="title"
		size="large"
		:can-close="!saving"
		@closing="onClose">
		<div class="parafeer-route-dialog">
			<div class="parafeer-route-dialog__field">
				<NcTextField :value="form.name"
					:label="t('procest', 'Naam')"
					:placeholder="t('procest', 'Bijv. Collegeadvies - Omgevingsvergunning')"
					required
					@update:value="v => form.name = v" />
			</div>
			<div class="parafeer-route-dialog__field">
				<label class="parafeer-route-dialog__label">
					{{ t('procest', 'Type voorstel') }}
				</label>
				<NcSelect v-model="form.voorstelType"
					:options="voorstelTypeOptions"
					:placeholder="t('procest', 'Selecteer voorstel type')" />
			</div>
			<div class="parafeer-route-dialog__field">
				<label class="parafeer-route-dialog__label">
					{{ t('procest', 'Zaaktype (optioneel)') }}
				</label>
				<NcSelect v-model="form.caseType"
					:options="caseTypes"
					label="title"
					track-by="id"
					:reduce="ct => ct.id"
					:placeholder="t('procest', 'Selecteer zaaktype')" />
			</div>
			<div class="parafeer-route-dialog__field">
				<NcCheckboxRadioSwitch :checked="form.isDefault"
					@update:checked="v => form.isDefault = v">
					{{ t('procest', 'Standaard route voor dit type') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="parafeer-route-dialog__field">
				<NcTextArea :value="form.description"
					:label="t('procest', 'Beschrijving')"
					:placeholder="t('procest', 'Wanneer is deze route van toepassing?')"
					@update:value="v => form.description = v" />
			</div>

			<h4>{{ t('procest', 'Stappen') }}</h4>
			<ParafeerStapEditor :steps="form.steps"
				@update:steps="v => form.steps = v" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="saving" @click="onClose">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit || saving"
				@click="onSave">
				{{ saving ? t('procest', 'Opslaan...') : t('procest', 'Opslaan') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import ParafeerStapEditor from './ParafeerStapEditor.vue'
import parafeerRouteApi from '../../../services/parafeerRouteApi.js'

const emptyForm = () => ({
	id: null,
	name: '',
	voorstelType: 'collegeadvies',
	caseType: null,
	isDefault: false,
	description: '',
	steps: [],
})

export default {
	name: 'ParafeerRouteDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		ParafeerStapEditor,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		route: {
			type: Object,
			default: null,
		},
		caseTypes: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			form: emptyForm(),
			saving: false,
			error: '',
			voorstelTypeOptions: ['dt_advies', 'collegeadvies', 'raadsvoorstel'],
		}
	},
	computed: {
		isEdit() {
			return Boolean(this.form.id)
		},
		title() {
			return this.isEdit
				? this.t('procest', 'Parafeerroute bewerken')
				: this.t('procest', 'Nieuwe parafeerroute')
		},
		canSubmit() {
			return this.form.name.trim().length > 0 && this.form.steps.length > 0
		},
	},
	watch: {
		open(value) {
			if (value) {
				this.hydrate()
			}
		},
		route() {
			if (this.open) this.hydrate()
		},
	},
	methods: {
		hydrate() {
			this.error = ''
			if (!this.route) {
				this.form = emptyForm()
				return
			}
			const steps = typeof this.route.steps === 'string'
				? JSON.parse(this.route.steps || '[]')
				: (this.route.steps || [])
			this.form = {
				id: this.route.id || this.route.uuid || null,
				name: this.route.name || '',
				voorstelType: this.route.voorstelType || 'collegeadvies',
				caseType: this.route.caseType || null,
				isDefault: Boolean(this.route.isDefault),
				description: this.route.description || '',
				steps: steps.map(s => ({ ...s })),
			}
		},
		async onSave() {
			if (!this.canSubmit) return
			this.saving = true
			this.error = ''
			try {
				const payload = {
					name: this.form.name,
					voorstelType: this.form.voorstelType,
					caseType: this.form.caseType,
					isDefault: this.form.isDefault,
					description: this.form.description,
					steps: this.form.steps,
				}
				const saved = this.form.id
					? await parafeerRouteApi.updateRoute(this.form.id, payload)
					: await parafeerRouteApi.createRoute(payload)
				this.$emit('saved', saved)
			} catch (err) {
				this.error = this.t('procest', 'Opslaan van parafeerroute is mislukt')
				console.error('parafeerroute save failed', err)
			} finally {
				this.saving = false
			}
		},
		onClose() {
			if (this.saving) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.parafeer-route-dialog {
	padding: 12px 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.parafeer-route-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.parafeer-route-dialog__label {
	font-weight: 600;
	font-size: 0.9em;
}
</style>
