<template>
	<div class="case-properties-widget">
		<!-- Status badge -->
		<div class="property-row property-row--status">
			<span class="property-label">{{ t('procest', 'Status') }}</span>
			<span class="status-badge" :class="statusBadgeClass">
				{{ statusName }}
			</span>
		</div>

		<!-- Editable title -->
		<div class="property-row">
			<label class="property-label" for="case-properties-title">{{
				t('procest', 'Title')
			}}</label>
			<NcTextField
				v-if="!isReadOnly"
				id="case-properties-title"
				:model-value="form.title"
				:error="!!validationErrors.title"
				@update:model-value="
					(v) => {
						form.title = v
						validationErrors.title = ''
					}
				" />
			<span v-else class="property-value">{{ caseData.title || '---' }}</span>
			<p v-if="validationErrors.title" class="form-error">
				{{ validationErrors.title }}
			</p>
		</div>

		<!-- Description -->
		<div class="property-row">
			<label class="property-label" for="case-properties-description">{{
				t('procest', 'Description')
			}}</label>
			<textarea
				v-if="!isReadOnly"
				id="case-properties-description"
				v-model="form.description"
				rows="2"
				class="property-textarea" />
			<span v-else class="property-value property-value--muted">
				{{ caseData.description || '---' }}
			</span>
		</div>

		<!-- Metadata grid -->
		<div class="property-grid">
			<div class="property-cell">
				<span class="property-label">{{ t('procest', 'Case type') }}</span>
				<span class="property-value">{{ caseTypeName }}</span>
			</div>
			<div class="property-cell">
				<span class="property-label">{{ t('procest', 'Identifier') }}</span>
				<span class="property-value">{{
					caseData.identifier || '---'
				}}</span>
			</div>
			<div class="property-cell">
				<span class="property-label">{{ t('procest', 'Priority') }}</span>
				<NcSelect
					v-if="!isReadOnly"
					v-model="form.priority"
					:options="priorityOptions"
					:aria-label-combobox="t('procest', 'Priority')" />
				<span v-else class="property-value">{{
					caseData.priority || '---'
				}}</span>
			</div>
			<div class="property-cell">
				<label class="property-label" for="case-properties-assignee">{{
					t('procest', 'Handler')
				}}</label>
				<NcTextField
					v-if="!isReadOnly"
					id="case-properties-assignee"
					:model-value="form.assignee"
					:placeholder="t('procest', 'Assign handler...')"
					@update:model-value="(v) => (form.assignee = v)" />
				<span v-else class="property-value">{{
					caseData.assignee || '---'
				}}</span>
			</div>
			<div class="property-cell">
				<span class="property-label">{{ t('procest', 'Start date') }}</span>
				<span class="property-value">{{
					formatDate(caseData.startDate)
				}}</span>
			</div>
			<div class="property-cell">
				<span class="property-label">{{
					t('procest', 'Confidentiality')
				}}</span>
				<span class="property-value">{{
					caseData.confidentiality || '---'
				}}</span>
			</div>
		</div>

		<!-- Save button -->
		<div v-if="!isReadOnly" class="property-actions">
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving">
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('procest', 'Save') }}
			</NcButton>
		</div>

		<!-- Result section -->
		<ResultSection
			v-if="caseResult || isAtFinalStatus"
			:result="caseResult"
			:result-types="resultTypes"
			:show-empty="isAtFinalStatus && !caseResult" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { formatDate } from '../../../utils/caseHelpers.js'
import { validateCaseUpdate } from '../../../utils/caseValidation.js'
import ResultSection from '../components/ResultSection.vue'

export default {
	name: 'CasePropertiesWidget',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		ResultSection,
	},
	props: {
		caseData: {
			type: Object,
			default: () => ({}),
		},
		caseTypeName: {
			type: String,
			default: '---',
		},
		statusName: {
			type: String,
			default: '---',
		},
		statusBadgeClass: {
			type: String,
			default: 'status-badge--active',
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
		isAtFinalStatus: {
			type: Boolean,
			default: false,
		},
		caseResult: {
			type: Object,
			default: null,
		},
		resultTypes: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['save'],
	data() {
		return {
			form: {
				title: '',
				description: '',
				assignee: '',
				priority: 'normal',
			},
			validationErrors: {},
			saving: false,
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
		}
	},
	watch: {
		caseData: {
			immediate: true,
			/**
			 * @param data
			 * @spec openspec/specs/signalering-widgets/spec.md
			 */
			handler(data) {
				if (data && data.title !== undefined) {
					this.form = {
						title: data.title || '',
						description: data.description || '',
						assignee: data.assignee || '',
						priority: data.priority || 'normal',
					}
				}
			},
		},
	},
	methods: {
		formatDate,
		/** @spec openspec/specs/signalering-widgets/spec.md */
		async save() {
			const validation = validateCaseUpdate(this.form)
			if (!validation.valid) {
				this.validationErrors = validation.errors
				return
			}
			this.saving = true
			this.$emit('save', { ...this.form })
			this.saving = false
		},
	},
}
</script>

<style scoped>
.case-properties-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

.property-row {
	margin-bottom: 12px;
}

.property-row--status {
	display: flex;
	align-items: center;
	gap: 8px;
}

.property-label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.property-value {
	display: block;
	font-size: 14px;
	color: var(--color-main-text);
}

.property-value--muted {
	color: var(--color-text-maxcontrast);
}

.property-textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
	font-size: 14px;
}

.property-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 10px 16px;
}

.property-cell {
	min-width: 0;
}

.property-actions {
	margin-top: 12px;
	display: flex;
	gap: 8px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--final {
	background: var(--color-success);
	color: white;
}

.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 2px;
}
</style>
