<template>
	<div class="consultation-panel">
		<h4 class="consultation-panel__title">
			{{ t('procest', 'Consultations') }}
			<span v-if="consultations.length > 0" class="consultation-panel__count">
				({{ openCount }} {{ t('procest', 'open') }})
			</span>
		</h4>

		<!-- Consultation list -->
		<div v-if="consultations.length > 0" class="consultation-panel__list">
			<div
				v-for="cons in consultations"
				:key="cons.id"
				class="consultation-panel__item"
				:class="{ 'consultation-panel__item--overdue': isOverdue(cons) }">
				<div class="consultation-panel__item-header">
					<span class="consultation-panel__department">{{ cons.adviesInstantie }}</span>
					<span
						class="consultation-panel__status"
						:class="'consultation-panel__status--' + cons.status">
						{{ getStatusLabel(cons.status) }}
					</span>
				</div>
				<p class="consultation-panel__subject">
					{{ cons.onderwerp }}
				</p>
				<p v-if="cons.vraagstelling" class="consultation-panel__question">
					{{ cons.vraagstelling }}
				</p>

				<!-- Response section -->
				<div v-if="cons.advies" class="consultation-panel__response">
					<span class="consultation-panel__advice-label">{{ t('procest', 'Advice:') }}</span>
					<span
						class="consultation-panel__advice-value"
						:class="'consultation-panel__advice--' + cons.advies">
						{{ getAdviceLabel(cons.advies) }}
					</span>
					<p v-if="cons.toelichting" class="consultation-panel__explanation">
						{{ cons.toelichting }}
					</p>
					<!-- Conditions -->
					<div v-if="conditions(cons).length > 0" class="consultation-panel__conditions">
						<strong>{{ t('procest', 'Conditions:') }}</strong>
						<ul>
							<li v-for="(condition, idx) in conditions(cons)" :key="idx">
								{{ condition }}
							</li>
						</ul>
					</div>
				</div>

				<div class="consultation-panel__meta">
					<span>{{ t('procest', 'Deadline: {date}', { date: formatDate(cons.uiterlijkeReactiedatum) }) }}</span>
					<span v-if="cons.aanvrager">{{ t('procest', 'by {user}', { user: cons.aanvrager }) }}</span>
				</div>

				<!-- Actions -->
				<div v-if="!isReadOnly && cons.status !== 'afgesloten'" class="consultation-panel__actions">
					<NcButton
						v-if="cons.status === 'open'"
						type="secondary"
						@click="$emit('update-status', { id: cons.id, status: 'in_behandeling' })">
						{{ t('procest', 'Acknowledge') }}
					</NcButton>
					<NcButton
						v-if="cons.status === 'advies_uitgebracht'"
						type="secondary"
						@click="$emit('update-status', { id: cons.id, status: 'afgesloten' })">
						{{ t('procest', 'Close') }}
					</NcButton>
				</div>
			</div>
		</div>

		<div v-else class="consultation-panel__empty">
			{{ t('procest', 'No consultations for this case.') }}
		</div>

		<!-- Create form -->
		<div v-if="showCreateForm" class="consultation-panel__create-form">
			<h5>{{ t('procest', 'New Consultation') }}</h5>
			<div class="form-group">
				<label>{{ t('procest', 'Department / Organization') }} *</label>
				<NcTextField
					:value="newForm.adviesInstantie"
					:placeholder="t('procest', 'e.g., Brandweer, Welstandscommissie')"
					@update:value="v => newForm.adviesInstantie = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Subject') }} *</label>
				<NcTextField
					:value="newForm.onderwerp"
					@update:value="v => newForm.onderwerp = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Questions') }}</label>
				<textarea v-model="newForm.vraagstelling" rows="3" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Response deadline') }} *</label>
				<NcTextField
					:value="newForm.uiterlijkeReactiedatum"
					type="date"
					@update:value="v => newForm.uiterlijkeReactiedatum = v" />
			</div>
			<div class="consultation-panel__form-actions">
				<NcButton @click="showCreateForm = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!isFormValid" @click="submitCreate">
					{{ t('procest', 'Create Consultation') }}
				</NcButton>
			</div>
		</div>

		<NcButton v-if="!showCreateForm && !isReadOnly" @click="showCreateForm = true">
			{{ t('procest', 'New Consultation') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ConsultationPanel',
	components: {
		NcButton,
		NcTextField,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		consultations: {
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
			showCreateForm: false,
			newForm: {
				adviesInstantie: '',
				onderwerp: '',
				vraagstelling: '',
				uiterlijkeReactiedatum: '',
			},
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md */
		openCount() {
			return this.consultations.filter(
				c => c.status === 'open' || c.status === 'in_behandeling',
			).length
		},
		/** @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md */
		isFormValid() {
			return this.newForm.adviesInstantie.trim() !== ''
				&& this.newForm.onderwerp.trim() !== ''
				&& this.newForm.uiterlijkeReactiedatum !== ''
		},
	},
	methods: {
		/**
		 * @param status
		 * @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md
		 */
		getStatusLabel(status) {
			const labels = {
				open: this.t('procest', 'Open'),
				in_behandeling: this.t('procest', 'In progress'),
				advies_uitgebracht: this.t('procest', 'Advice received'),
				afgesloten: this.t('procest', 'Closed'),
			}
			return labels[status] || status
		},
		/**
		 * @param advies
		 * @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md
		 */
		getAdviceLabel(advies) {
			const labels = {
				positief: this.t('procest', 'Positive'),
				positief_met_voorwaarden: this.t('procest', 'Positive with conditions'),
				negatief: this.t('procest', 'Negative'),
				niet_van_toepassing: this.t('procest', 'Not applicable'),
			}
			return labels[advies] || advies
		},
		/**
		 * @param dateStr
		 * @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md
		 */
		formatDate(dateStr) {
			if (!dateStr) return '---'
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleDateString('nl-NL')
		},
		/**
		 * @param cons
		 * @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md
		 */
		isOverdue(cons) {
			if (!cons.uiterlijkeReactiedatum) return false
			if (cons.status === 'afgesloten' || cons.status === 'advies_uitgebracht') return false
			return new Date(cons.uiterlijkeReactiedatum) < new Date()
		},
		/**
		 * @param cons
		 * @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md
		 */
		conditions(cons) {
			if (!cons.voorwaarden) return []
			try {
				const parsed = typeof cons.voorwaarden === 'string'
					? JSON.parse(cons.voorwaarden)
					: cons.voorwaarden
				return Array.isArray(parsed) ? parsed : []
			} catch {
				return []
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-consultation-management/tasks.md */
		submitCreate() {
			this.$emit('create', {
				parentZaak: this.caseId,
				...this.newForm,
			})
			this.newForm = {
				adviesInstantie: '',
				onderwerp: '',
				vraagstelling: '',
				uiterlijkeReactiedatum: '',
			}
			this.showCreateForm = false
		},
	},
}
</script>

<style scoped>
.consultation-panel__title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.consultation-panel__count {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.consultation-panel__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.consultation-panel__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.consultation-panel__item--overdue {
	border-color: var(--color-error);
	background: var(--color-error-light, #fff5f5);
}

.consultation-panel__item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.consultation-panel__department {
	font-weight: 600;
}

.consultation-panel__status {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
}

.consultation-panel__status--open {
	background: var(--color-background-dark);
}

.consultation-panel__status--in_behandeling {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.consultation-panel__status--advies_uitgebracht {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.consultation-panel__status--afgesloten {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.consultation-panel__subject {
	font-weight: 500;
	margin: 4px 0;
}

.consultation-panel__question {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.consultation-panel__response {
	margin-top: 8px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.consultation-panel__advice-label {
	font-weight: 600;
	font-size: 0.875rem;
}

.consultation-panel__advice--positief {
	color: var(--color-success, #2e7d32);
}

.consultation-panel__advice--positief_met_voorwaarden {
	color: var(--color-warning, #e65100);
}

.consultation-panel__advice--negatief {
	color: var(--color-error, #c62828);
}

.consultation-panel__conditions {
	margin-top: 8px;
	font-size: 0.875rem;
}

.consultation-panel__conditions ul {
	margin: 4px 0 0 16px;
}

.consultation-panel__meta {
	display: flex;
	gap: 16px;
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
}

.consultation-panel__actions {
	margin-top: 8px;
	display: flex;
	gap: 8px;
}

.consultation-panel__create-form {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 12px;
}

.consultation-panel__form-actions {
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
