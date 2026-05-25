<template>
	<NcDialog
		:name="t('procest', 'Nieuw B&W-voorstel')"
		size="normal"
		@closing="$emit('close')">
		<div class="voorstel-create">
			<div class="form-group">
				<label>{{ t('procest', 'Onderwerp') }} *</label>
				<NcTextField
					:value="form.onderwerp"
					:error="!!errors.onderwerp"
					:placeholder="t('procest', 'Onderwerp van het voorstel...')"
					@update:value="v => { form.onderwerp = v; errors.onderwerp = '' }" />
				<p v-if="errors.onderwerp" class="form-error">
					{{ errors.onderwerp }}
				</p>
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					:options="typeOptions"
					:placeholder="t('procest', 'Selecteer type...')" />
			</div>

			<div v-if="!caseId" class="form-group">
				<label>{{ t('procest', 'Zaak') }} *</label>
				<NcSelect
					v-model="selectedCase"
					:options="cases"
					label="title"
					track-by="id"
					:placeholder="t('procest', 'Selecteer zaak...')"
					@input="onCaseSelected" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Portefeuillehouder') }}</label>
				<NcTextField
					:value="form.portefeuillehouder"
					:placeholder="t('procest', 'Gebruikers-ID wethouder')"
					@update:value="v => form.portefeuillehouder = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Afdeling') }}</label>
				<NcTextField
					:value="form.afdeling"
					:placeholder="t('procest', 'Afdeling')"
					@update:value="v => form.afdeling = v" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="create">
				<template v-if="saving">
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('procest', 'Aanmaken') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { getCurrentUser } from '@nextcloud/auth'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'VoorstelCreateDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},
	props: {
		caseId: {
			type: String,
			default: null,
		},
		caseTitle: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			saving: false,
			cases: [],
			selectedCase: null,
			form: {
				onderwerp: this.caseTitle || '',
				type: 'collegeadvies',
				portefeuillehouder: '',
				afdeling: '',
			},
			errors: {},
			typeOptions: [
				'dt_advies',
				'collegeadvies',
				'raadsvoorstel',
			],
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
		if (!this.caseId) {
			try {
				const results = await this.objectStore.fetchCollection('case', { _limit: 200 })
				this.cases = Array.isArray(results) ? results : (results?.results || [])
			} catch (error) {
				console.error('Failed to load cases:', error)
			}
		}
	},
	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		onCaseSelected(caseObj) {
			if (caseObj && !this.form.onderwerp) {
				this.form.onderwerp = caseObj.title || ''
			}
		},
		/** @spec openspec/specs/parafering-actions/spec.md */
		async create() {
			this.errors = {}
			if (!this.form.onderwerp.trim()) {
				this.errors.onderwerp = t('procest', 'Onderwerp is verplicht')
				return
			}

			const caseRef = this.caseId || this.selectedCase?.id
			if (!caseRef) {
				this.errors.onderwerp = t('procest', 'Selecteer een zaak')
				return
			}

			this.saving = true
			try {
				// Try to find a default parafeerroute for this case type and voorstel type
				let routeId = null
				try {
					const routes = await this.objectStore.fetchCollection('parafeerroute', {
						'_filters[voorstelType]': this.form.type,
						'_filters[isDefault]': true,
						_limit: 1,
					})
					const routeList = Array.isArray(routes) ? routes : (routes?.results || [])
					if (routeList.length > 0) {
						routeId = routeList[0].id
					}
				} catch {
					// No default route found, that's fine
				}

				const voorstelData = {
					case: caseRef,
					type: this.form.type,
					onderwerp: this.form.onderwerp.trim(),
					steller: getCurrentUser()?.uid || '',
					afdeling: this.form.afdeling,
					portefeuillehouder: this.form.portefeuillehouder,
					status: 'concept',
					currentStep: 0,
					bijlagen: [],
				}

				if (routeId) {
					voorstelData.parafeerroute = routeId
				}

				await this.objectStore.saveObject('voorstel', voorstelData)
				this.$emit('created')
			} catch (error) {
				console.error('Failed to create voorstel:', error)
				this.errors.onderwerp = error.message || t('procest', 'Aanmaken mislukt')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.voorstel-create {
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

.form-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin-top: 4px;
}
</style>
