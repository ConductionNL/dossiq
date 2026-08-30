<template>
	<NcDialog
		:name="t('dossiq', 'New B&W proposal')"
		size="normal"
		@closing="$emit('close')">
		<div class="voorstel-create">
			<div class="form-group">
				<label for="voorstel-create-onderwerp"
					>{{ t('dossiq', 'Onderwerp') }} *</label
				>
				<NcTextField
					id="voorstel-create-onderwerp"
					:modelValue="form.subject"
					:error="!!errors.subject"
					:placeholder="t('dossiq', 'Subject of the proposal...')"
					@update:modelValue="
						(v) => {
							form.subject = v
							errors.subject = ''
						}
					" />
				<p v-if="errors.subject" class="form-error">
					{{ errors.subject }}
				</p>
			</div>

			<div class="form-group">
				<label>{{ t('dossiq', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					:options="typeOptions"
					:aria-label-combobox="t('dossiq', 'Type')"
					:placeholder="t('dossiq', 'Select type...')" />
			</div>

			<div v-if="!caseId" class="form-group">
				<label>{{ t('dossiq', 'Case') }} *</label>
				<NcSelect
					v-model="selectedCase"
					:options="cases"
					:aria-label-combobox="t('dossiq', 'Case')"
					label="title"
					trackBy="id"
					:placeholder="t('dossiq', 'Select case...')"
					@update:modelValue="onCaseSelected" />
			</div>

			<div class="form-group">
				<label for="voorstel-create-portfolio-holder">{{
					t('dossiq', 'Portfolio holder')
				}}</label>
				<NcTextField
					id="voorstel-create-portfolio-holder"
					:modelValue="form.portfolioHolder"
					:placeholder="t('dossiq', 'Alderman user ID')"
					@update:modelValue="(v) => (form.portfolioHolder = v)" />
			</div>

			<div class="form-group">
				<label for="voorstel-create-department">{{
					t('dossiq', 'Department')
				}}</label>
				<NcTextField
					id="voorstel-create-department"
					:modelValue="form.department"
					:placeholder="t('dossiq', 'Department')"
					@update:modelValue="(v) => (form.department = v)" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dossiq', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="create">
				<template v-if="saving">
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'

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
				subject: this.caseTitle || '',
				type: 'collegeadvies',
				portfolioHolder: '',
				department: '',
			},

			errors: {},
			typeOptions: ['dt_advice', 'collegeadvies', 'raadsvoorstel'],
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
				const results = await this.objectStore.fetchCollection('case', {
					_limit: 200,
				})
				this.cases = Array.isArray(results)
					? results
					: results?.results || []
			} catch (error) {
				console.error('Failed to load cases:', error)
			}
		}
	},

	methods: {
		/**
		 * @param caseObj
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		onCaseSelected(caseObj) {
			if (caseObj && !this.form.subject) {
				this.form.subject = caseObj.title || ''
			}
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		async create() {
			this.errors = {}
			if (!this.form.subject.trim()) {
				this.errors.subject = t('dossiq', 'Subject is required')
				return
			}

			const caseRef = this.caseId || this.selectedCase?.id
			if (!caseRef) {
				this.errors.subject = t('dossiq', 'Select a case')
				return
			}

			this.saving = true
			try {
				// Try to find a default parafeerroute for this case type and voorstel type
				let routeId = null
				try {
					const routes = await this.objectStore.fetchCollection(
						'parafeerroute',
						{
							'_filters[voorstelType]': this.form.type,
							'_filters[isDefault]': true,
							_limit: 1,
						},
					)
					const routeList = Array.isArray(routes)
						? routes
						: routes?.results || []
					if (routeList.length > 0) {
						routeId = routeList[0].id
					}
				} catch {
					// No default route found, that's fine
				}

				const voorstelData = {
					case: caseRef,
					type: this.form.type,
					subject: this.form.subject.trim(),
					author: getCurrentUser()?.uid || '',
					department: this.form.department,
					portfolioHolder: this.form.portfolioHolder,
					status: 'draft',
					currentStep: 0,
					attachments: [],
				}

				if (routeId) {
					voorstelData.parafeerroute = routeId
				}

				await this.objectStore.saveObject('proposal', voorstelData)
				this.$emit('created')
			} catch (error) {
				console.error('Failed to create proposal:', error)
				this.errors.subject =
					error.message || t('dossiq', 'Failed to create')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.proposal-create {
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
