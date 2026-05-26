<template>
	<NcDialog
		:name="t('procest', 'Besluit registreren')"
		size="normal"
		@closing="$emit('close')">
		<div class="besluit-registration">
			<div class="form-group">
				<label>{{ t('procest', 'Titel') }} *</label>
				<NcTextField
					:value="form.title"
					:error="!!errors.title"
					:placeholder="t('procest', 'Titel van het besluit...')"
					@update:value="v => { form.title = v; errors.title = '' }" />
				<p v-if="errors.title" class="form-error">
					{{ errors.title }}
				</p>
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Ingangsdatum') }}</label>
				<NcTextField
					type="date"
					:value="form.effectiveDate"
					@update:value="v => form.effectiveDate = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Besluittype') }}</label>
				<NcSelect
					v-model="selectedDecisionType"
					:options="decisionTypes"
					:aria-label-combobox="t('procest', 'Besluittype')"
					label="name"
					track-by="id"
					:placeholder="t('procest', 'Selecteer besluittype...')" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Toelichting') }}</label>
				<textarea
					v-model="form.explanation"
					:placeholder="t('procest', 'Toelichting bij het besluit...')"
					rows="3" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Bestuursorgaan') }}</label>
				<NcTextField
					:value="form.governingBody"
					:placeholder="t('procest', 'College van B&W')"
					@update:value="v => form.governingBody = v" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="register">
				<template v-if="saving">
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('procest', 'Registreren') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { getCurrentUser } from '@nextcloud/auth'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'BesluitRegistration',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},
	props: {
		voorstel: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			saving: false,
			decisionTypes: [],
			selectedDecisionType: null,
			form: {
				title: this.voorstel.onderwerp || '',
				effectiveDate: new Date().toISOString().split('T')[0],
				explanation: '',
				governingBody: 'College van B&W',
			},
			errors: {},
		}
	},
	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		objectStore() {
			return useObjectStore()
		},
	},
	/** @spec openspec/specs/parafering-actions/spec.md */
	async created() {
		try {
			const results = await this.objectStore.fetchCollection('decisionType', { _limit: 50 })
			this.decisionTypes = Array.isArray(results) ? results : (results?.results || [])
		} catch (error) {
			console.error('Failed to load decision types:', error)
		}
	},
	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		async register() {
			this.errors = {}
			if (!this.form.title.trim()) {
				this.errors.title = t('procest', 'Titel is verplicht')
				return
			}

			this.saving = true
			try {
				// Create decision object using existing decision schema
				const decisionData = {
					title: this.form.title.trim(),
					case: this.voorstel.case,
					decisionDate: new Date().toISOString().split('T')[0],
					effectiveDate: this.form.effectiveDate || undefined,
					explanation: this.form.explanation || undefined,
					governingBody: this.form.governingBody || undefined,
					decidedBy: getCurrentUser()?.uid || '',
				}

				if (this.selectedDecisionType) {
					decisionData.decisionType = this.selectedDecisionType.id
				}

				const decision = await this.objectStore.saveObject('decision', decisionData)

				// Update voorstel status to 'besloten' and link the decision
				await this.objectStore.saveObject('voorstel', {
					...this.voorstel,
					status: 'besloten',
					decision: decision.id || decision._self?.id,
				})

				this.$emit('registered')
			} catch (error) {
				console.error('Failed to register besluit:', error)
				this.errors.title = error.message || t('procest', 'Registratie mislukt')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.besluit-registration {
	padding: 8px 0;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.form-group textarea {
	width: 100%;
	resize: vertical;
}

.form-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin-top: 4px;
}
</style>
