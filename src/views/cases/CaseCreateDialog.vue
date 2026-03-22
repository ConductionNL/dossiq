<template>
	<div class="case-create-overlay" @click.self="$emit('close')">
		<div class="case-create-dialog">
			<div class="case-create-dialog__header">
				<h3>{{ dialogTitle }}</h3>
				<NcButton type="tertiary" @click="$emit('close')">
					&#10005;
				</NcButton>
			</div>

			<div class="case-create-dialog__body">
				<!-- Parent case context (sub-case mode) -->
				<div v-if="isSubCaseMode && parentCaseType" class="case-create-dialog__parent-context">
					<span class="parent-context-label">{{ t('procest', 'Parent case type') }}</span>
					<span>{{ parentCaseType.title }}</span>
				</div>

				<!-- Case Type Selection -->
				<div class="form-group">
					<label>{{ t('procest', 'Case type') }} *</label>
					<NcSelect
						v-model="selectedCaseType"
						:options="availableCaseTypes"
						label="title"
						track-by="id"
						:placeholder="t('procest', 'Select a case type...')"
						@input="onCaseTypeSelected" />
					<p v-if="errors.caseType" class="form-error">
						{{ errors.caseType }}
					</p>
				</div>

				<!-- Preview panel when case type selected -->
				<div v-if="selectedCaseType" class="case-create-dialog__preview">
					<div class="preview-row">
						<span class="preview-label">{{ t('procest', 'Processing deadline') }}</span>
						<span>{{ formattedDeadline }}</span>
					</div>
					<div class="preview-row">
						<span class="preview-label">{{ t('procest', 'Confidentiality') }}</span>
						<span>{{ selectedCaseType.confidentiality || t('procest', 'Not set') }}</span>
					</div>
					<div class="preview-row">
						<span class="preview-label">{{ t('procest', 'Initial status') }}</span>
						<span>{{ initialStatusName }}</span>
					</div>
					<div class="preview-row">
						<span class="preview-label">{{ t('procest', 'Calculated deadline') }}</span>
						<span>{{ calculatedDeadlineText }}</span>
					</div>
				</div>

				<!-- Title -->
				<div class="form-group">
					<label>{{ t('procest', 'Title') }} *</label>
					<NcTextField
						:value="form.title"
						:placeholder="t('procest', 'Enter case title...')"
						:error="!!errors.title"
						@update:value="v => { form.title = v; errors.title = '' }" />
					<p v-if="errors.title" class="form-error">
						{{ errors.title }}
					</p>
				</div>

				<!-- Description -->
				<div class="form-group">
					<label>{{ t('procest', 'Description') }}</label>
					<textarea
						v-model="form.description"
						:placeholder="t('procest', 'Optional description...')"
						rows="3" />
				</div>
			</div>

			<div class="case-create-dialog__footer">
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving"
					@click="submit">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ submitLabel }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { validateCaseCreate, isCaseTypeUsable } from '../../utils/caseValidation.js'
import { calculateDeadline, generateIdentifier, formatDate, formatDuration } from '../../utils/caseHelpers.js'

export default {
	name: 'CaseCreateDialog',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
	},
	props: {
		parentCase: {
			type: String,
			default: null,
		},
		parentCaseType: {
			type: Object,
			default: null,
		},
	},
	emits: ['created', 'close'],
	data() {
		return {
			form: {
				title: '',
				description: '',
				caseType: null,
			},
			selectedCaseType: null,
			caseTypes: [],
			statusTypes: [],
			errors: {},
			saving: false,
			loadingTypes: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isSubCaseMode() {
			return !!this.parentCase
		},
		dialogTitle() {
			return this.isSubCaseMode
				? t('procest', 'Create Sub-case')
				: t('procest', 'New Case')
		},
		submitLabel() {
			return this.isSubCaseMode
				? t('procest', 'Create sub-case')
				: t('procest', 'Create case')
		},
		availableCaseTypes() {
			const usable = this.caseTypes.filter(ct => isCaseTypeUsable(ct))

			// In sub-case mode, filter to only subCaseTypes from parent case type
			if (this.isSubCaseMode && this.parentCaseType) {
				const allowedTypes = this.parentCaseType.subCaseTypes || []
				if (allowedTypes.length > 0) {
					return usable.filter(ct =>
						allowedTypes.includes(ct.id) || allowedTypes.includes(ct.title),
					)
				}
			}

			return usable
		},
		formattedDeadline() {
			if (!this.selectedCaseType?.processingDeadline) return '\u2014'
			return formatDuration(this.selectedCaseType.processingDeadline)
		},
		initialStatusName() {
			if (this.statusTypes.length === 0) return '\u2014'
			const sorted = [...this.statusTypes].sort((a, b) => (a.order || 0) - (b.order || 0))
			return sorted[0]?.name || '\u2014'
		},
		initialStatusType() {
			if (this.statusTypes.length === 0) return null
			const sorted = [...this.statusTypes].sort((a, b) => (a.order || 0) - (b.order || 0))
			return sorted[0]
		},
		calculatedDeadlineText() {
			if (!this.selectedCaseType?.processingDeadline) return '\u2014'
			const deadline = calculateDeadline(new Date(), this.selectedCaseType.processingDeadline)
			return deadline ? formatDate(deadline.toISOString()) : '\u2014'
		},
	},
	async mounted() {
		await this.loadCaseTypes()
	},
	methods: {
		async loadCaseTypes() {
			this.loadingTypes = true
			const results = await this.objectStore.fetchCollection('caseType', { _limit: 100 })
			this.caseTypes = results || []
			this.loadingTypes = false
		},

		async onCaseTypeSelected(caseType) {
			this.form.caseType = caseType?.id || null
			this.errors.caseType = ''
			this.statusTypes = []

			if (caseType) {
				const results = await this.objectStore.fetchCollection('statusType', {
					'_filters[caseType]': caseType.id,
					_order: JSON.stringify({ order: 'asc' }),
					_limit: 100,
				})
				this.statusTypes = results || []
			}
		},

		async submit() {
			const validation = validateCaseCreate(this.form, this.caseTypes)
			if (!validation.valid) {
				this.errors = validation.errors
				return
			}

			this.saving = true
			const now = new Date()
			const startDate = now.toISOString().split('T')[0] + 'T00:00:00Z'
			const deadline = calculateDeadline(now, this.selectedCaseType.processingDeadline)
			const initialStatus = this.initialStatusType
			const currentUser = OC?.currentUser || 'unknown'

			const activityDescription = this.isSubCaseMode
				? t('procest', 'Sub-case created with type \'{type}\'', { type: this.selectedCaseType.title })
				: t('procest', 'Case created with type \'{type}\'', { type: this.selectedCaseType.title })

			const caseData = {
				title: this.form.title.trim(),
				description: this.form.description.trim(),
				identifier: generateIdentifier(),
				caseType: this.selectedCaseType.id,
				status: initialStatus?.id || null,
				startDate,
				deadline: deadline ? deadline.toISOString().split('T')[0] + 'T17:00:00Z' : null,
				confidentiality: this.selectedCaseType.confidentiality || 'public',
				assignee: null,
				priority: 'normal',
				endDate: null,
				result: null,
				extensionCount: 0,
				parentCase: this.parentCase || null,
				statusHistory: [
					{
						status: initialStatus?.id || null,
						date: now.toISOString(),
						changedBy: currentUser,
					},
				],
				activity: [
					{
						date: now.toISOString(),
						type: 'created',
						description: activityDescription,
						user: currentUser,
					},
				],
			}

			const result = await this.objectStore.saveObject('case', caseData)
			this.saving = false

			if (result) {
				this.$emit('created', result.id)
			}
		},
	},
}
</script>

<style scoped>
.case-create-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.case-create-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	width: 560px;
	max-width: 90vw;
	max-height: 85vh;
	overflow-y: auto;
}

.case-create-dialog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.case-create-dialog__header h3 {
	margin: 0;
}

.case-create-dialog__body {
	padding: 20px;
}

.case-create-dialog__parent-context {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	margin-bottom: 16px;
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.parent-context-label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.case-create-dialog__preview {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 12px 16px;
	margin-bottom: 16px;
}

.preview-row {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
}

.preview-label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.case-create-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 16px 20px;
	border-top: 1px solid var(--color-border);
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-error {
	color: var(--color-error);
	font-size: 13px;
	margin-top: 4px;
}
</style>
