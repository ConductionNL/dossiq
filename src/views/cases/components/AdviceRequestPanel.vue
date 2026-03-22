<template>
	<div class="advice-request">
		<h4 class="advice-request__title">
			{{ t('procest', 'Advice Requests') }}
		</h4>

		<!-- Existing requests -->
		<div v-if="requests.length > 0" class="advice-request__list">
			<div
				v-for="req in requests"
				:key="req.id"
				class="advice-request__item"
				:class="{ 'advice-request__item--overdue': isOverdue(req) }">
				<div class="advice-request__item-header">
					<span class="advice-request__department">{{ req.department }}</span>
					<span class="advice-request__status" :class="'advice-request__status--' + req.status">
						{{ getStatusLabel(req.status) }}
					</span>
				</div>
				<p class="advice-request__subject">{{ req.subject }}</p>
				<div class="advice-request__meta">
					<span>{{ t('procest', 'Deadline: {date}', { date: formatDate(req.deadline) }) }}</span>
					<span v-if="req.response">
						{{ t('procest', 'Response: {type}', { type: getResponseLabel(req.response) }) }}
					</span>
				</div>
			</div>
		</div>

		<div v-else class="advice-request__empty">
			{{ t('procest', 'No advice requests yet.') }}
		</div>

		<!-- New request form -->
		<div v-if="showForm" class="advice-request__form">
			<div class="form-group">
				<label>{{ t('procest', 'Department / Organization') }} *</label>
				<NcTextField
					:value="form.department"
					:placeholder="t('procest', 'e.g., Brandweer, Welstandscommissie')"
					@update:value="v => form.department = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Subject') }} *</label>
				<NcTextField
					:value="form.subject"
					@update:value="v => form.subject = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Question') }}</label>
				<textarea v-model="form.question" rows="3" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Deadline') }} *</label>
				<NcTextField
					:value="form.deadline"
					type="date"
					@update:value="v => form.deadline = v" />
			</div>
			<div class="advice-request__form-actions">
				<NcButton @click="showForm = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!isFormValid"
					@click="submitRequest">
					{{ t('procest', 'Send Request') }}
				</NcButton>
			</div>
		</div>

		<NcButton v-if="!showForm && !isReadOnly" @click="showForm = true">
			{{ t('procest', 'Request Advice') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AdviceRequestPanel',
	components: {
		NcButton,
		NcTextField,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		requests: {
			type: Array,
			default: () => [],
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			showForm: false,
			form: {
				department: '',
				subject: '',
				question: '',
				deadline: '',
			},
		}
	},
	computed: {
		isFormValid() {
			return this.form.department.trim() !== ''
				&& this.form.subject.trim() !== ''
				&& this.form.deadline !== ''
		},
	},
	methods: {
		getStatusLabel(status) {
			const labels = {
				open: this.t('procest', 'Open'),
				in_behandeling: this.t('procest', 'In progress'),
				advies_uitgebracht: this.t('procest', 'Advice received'),
				afgesloten: this.t('procest', 'Closed'),
			}
			return labels[status] || status
		},
		getResponseLabel(response) {
			const labels = {
				positief: this.t('procest', 'Positive'),
				positief_met_voorwaarden: this.t('procest', 'Positive with conditions'),
				negatief: this.t('procest', 'Negative'),
				niet_van_toepassing: this.t('procest', 'Not applicable'),
			}
			return labels[response] || response
		},
		formatDate(dateStr) {
			if (!dateStr) return '---'
			const date = new Date(dateStr)
			if (isNaN(date.getTime())) return dateStr
			return date.toLocaleDateString('nl-NL')
		},
		isOverdue(req) {
			if (!req.deadline || req.status === 'afgesloten' || req.status === 'advies_uitgebracht') {
				return false
			}
			return new Date(req.deadline) < new Date()
		},
		submitRequest() {
			this.$emit('create', {
				caseId: this.caseId,
				...this.form,
				status: 'open',
			})
			this.form = { department: '', subject: '', question: '', deadline: '' }
			this.showForm = false
		},
	},
}
</script>

<style scoped>
.advice-request__title {
	margin-bottom: 12px;
}

.advice-request__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.advice-request__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.advice-request__item--overdue {
	border-color: var(--color-error);
}

.advice-request__item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.advice-request__department {
	font-weight: 600;
}

.advice-request__status {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
}

.advice-request__status--open {
	background: var(--color-background-dark);
}

.advice-request__status--in_behandeling {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.advice-request__status--advies_uitgebracht {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.advice-request__status--afgesloten {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.advice-request__subject {
	margin: 4px 0;
}

.advice-request__meta {
	display: flex;
	gap: 16px;
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
}

.advice-request__form {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 12px;
}

.advice-request__form-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	font-size: 0.875rem;
	margin-bottom: 4px;
}
</style>
