<template>
	<div class="consultation-dashboard">
		<header class="consultation-dashboard__header">
			<h1>{{ t('procest', 'Consultation inbox') }}</h1>
			<p class="consultation-dashboard__subtitle">
				{{ t('procest', 'Open consultation requests assigned to your department') }}
			</p>
		</header>

		<!-- Filter bar -->
		<div class="consultation-dashboard__filters">
			<NcTextField
				:value="filters.query"
				:label="t('procest', 'Search')"
				:placeholder="t('procest', 'Search consultations...')"
				@update:value="v => { filters.query = v; applyFilters() }" />
			<NcSelect
				:value="selectedStatus"
				:options="statusOptions"
				:input-label="t('procest', 'Status')"
				label="label"
				track-by="value"
				@update:value="v => { filters.status = v ? v.value : ''; applyFilters() }" />
			<NcSelect
				:value="selectedPriority"
				:options="priorityOptions"
				:input-label="t('procest', 'Priority')"
				label="label"
				track-by="value"
				@update:value="v => { filters.priority = v ? v.value : ''; applyFilters() }" />
		</div>

		<!-- Summary bar -->
		<div class="consultation-dashboard__summary">
			<span class="consultation-dashboard__badge">
				{{ t('procest', '{n} open', { n: openCount }) }}
			</span>
			<span v-if="overdueCount > 0" class="consultation-dashboard__badge consultation-dashboard__badge--overdue">
				{{ t('procest', '{n} overdue', { n: overdueCount }) }}
			</span>
		</div>

		<!-- Loading -->
		<div v-if="loading" class="consultation-dashboard__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- Empty state -->
		<div v-else-if="filtered.length === 0" class="consultation-dashboard__empty">
			{{ t('procest', 'No consultations found.') }}
		</div>

		<!-- Consultation list -->
		<div v-else class="consultation-dashboard__list">
			<div
				v-for="c in filtered"
				:key="c.id || c.uuid"
				class="consultation-dashboard__item"
				:class="{
					'consultation-dashboard__item--overdue': isOverdue(c),
					'consultation-dashboard__item--spoed': c.prioriteit === 'spoed',
				}">
				<div class="consultation-dashboard__item-header">
					<span class="consultation-dashboard__number">
						{{ c.consultationNumber || t('procest', 'No number') }}
					</span>
					<span
						class="consultation-dashboard__status"
						:class="'status--' + c.status">
						{{ getStatusLabel(c.status) }}
					</span>
					<span v-if="c.prioriteit === 'spoed'" class="consultation-dashboard__spoed">
						{{ t('procest', 'Urgent') }}
					</span>
				</div>

				<div class="consultation-dashboard__meta">
					<strong>{{ c.onderwerp }}</strong>
					<span class="consultation-dashboard__case">
						{{ t('procest', 'Case:') }} {{ c.parentZaak }}
					</span>
				</div>

				<div v-if="c.vraagstelling" class="consultation-dashboard__question">
					{{ c.vraagstelling }}
				</div>

				<div class="consultation-dashboard__deadline">
					<span :class="{ 'consultation-dashboard__deadline--overdue': isOverdue(c) }">
						{{ t('procest', 'Deadline: {date}', { date: formatDate(c.uiterlijkeReactiedatum) }) }}
					</span>
					<span v-if="c.assignee">
						· {{ t('procest', 'Handler: {user}', { user: c.assignee }) }}
					</span>
				</div>

				<!-- Response form (inline) -->
				<ConsultationResponseForm
					v-if="respondingId === (c.id || c.uuid)"
					:consultation="c"
					:saving="saving"
					@submit="onSubmitResponse(c, $event)"
					@cancel="respondingId = null" />

				<div v-else class="consultation-dashboard__actions">
					<NcButton
						v-if="!c.assignee"
						type="secondary"
						@click="claimConsultation(c)">
						{{ t('procest', 'Claim') }}
					</NcButton>
					<NcButton
						v-if="c.status === 'in_behandeling'"
						type="primary"
						@click="respondingId = (c.id || c.uuid)">
						{{ t('procest', 'Submit advice') }}
					</NcButton>
					<NcButton
						v-if="c.status === 'open'"
						type="secondary"
						@click="updateStatus(c, 'ontvangen')">
						{{ t('procest', 'Acknowledge') }}
					</NcButton>
					<NcButton
						v-if="c.status === 'ontvangen'"
						type="secondary"
						@click="updateStatus(c, 'in_behandeling')">
						{{ t('procest', 'Start processing') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import ConsultationResponseForm from './cases/components/ConsultationResponseForm.vue'

export default {
	name: 'ConsultationDashboard',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		ConsultationResponseForm,
	},

	data() {
		return {
			loading: false,
			saving: false,
			consultations: [],
			filtered: [],
			respondingId: null,
			filters: {
				query: '',
				status: '',
				priority: '',
			},
			selectedStatus: null,
			selectedPriority: null,
		}
	},

	computed: {
		openCount() {
			return this.consultations.filter(
				c => !['afgesloten', 'ingetrokken'].includes(c.status),
			).length
		},

		overdueCount() {
			return this.consultations.filter(c => this.isOverdue(c)).length
		},

		statusOptions() {
			return [
				{ value: '', label: t('procest', 'All statuses') },
				{ value: 'open', label: t('procest', 'Open') },
				{ value: 'ontvangen', label: t('procest', 'Acknowledged') },
				{ value: 'in_behandeling', label: t('procest', 'In progress') },
				{ value: 'advies_uitgebracht', label: t('procest', 'Advice submitted') },
				{ value: 'afgesloten', label: t('procest', 'Closed') },
			]
		},

		priorityOptions() {
			return [
				{ value: '', label: t('procest', 'All priorities') },
				{ value: 'normaal', label: t('procest', 'Normal') },
				{ value: 'spoed', label: t('procest', 'Urgent') },
			]
		},
	},

	mounted() {
		this.loadConsultations()
	},

	methods: {
		t,

		async loadConsultations() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/consultations/overdue')
				const { data } = await axios.get(url)
				// Combine overdue (sorted first) with regular consultations.
				const overdueIds = new Set((data.results || []).map(c => c.id || c.uuid))
				const allUrl = generateUrl('/apps/procest/api/consultations')
				const { data: allData } = await axios.get(allUrl)
				const all = allData.results || []
				const overdue = all.filter(c => overdueIds.has(c.id || c.uuid))
				const rest = all.filter(c => !overdueIds.has(c.id || c.uuid))
				this.consultations = [...overdue, ...rest]
				this.applyFilters()
			} catch (err) {
				console.error('Failed to load consultations', err)
			} finally {
				this.loading = false
			}
		},

		applyFilters() {
			let result = [...this.consultations]
			const q = this.filters.query.toLowerCase()
			if (q) {
				result = result.filter(
					c => (c.onderwerp || '').toLowerCase().includes(q)
						|| (c.consultationNumber || '').toLowerCase().includes(q)
						|| (c.adviesInstantie || '').toLowerCase().includes(q),
				)
			}

			if (this.filters.status) {
				result = result.filter(c => c.status === this.filters.status)
			}

			if (this.filters.priority) {
				result = result.filter(c => c.prioriteit === this.filters.priority)
			}

			this.filtered = result
		},

		isOverdue(c) {
			if (!c.uiterlijkeReactiedatum) return false
			if (['afgesloten', 'advies_uitgebracht', 'ingetrokken'].includes(c.status)) return false
			return new Date(c.uiterlijkeReactiedatum) < new Date()
		},

		getStatusLabel(status) {
			const labels = {
				open: t('procest', 'Open'),
				ontvangen: t('procest', 'Acknowledged'),
				in_behandeling: t('procest', 'In progress'),
				advies_uitgebracht: t('procest', 'Advice submitted'),
				afgesloten: t('procest', 'Closed'),
				ingetrokken: t('procest', 'Withdrawn'),
			}
			return labels[status] || status
		},

		formatDate(dateStr) {
			if (!dateStr) return '---'
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleDateString('nl-NL')
		},

		async claimConsultation(consultation) {
			const id = consultation.id || consultation.uuid
			try {
				await axios.post(generateUrl(`/apps/procest/api/consultations/${encodeURIComponent(id)}/claim`))
				await this.loadConsultations()
			} catch (err) {
				console.error('Failed to claim consultation', err)
			}
		},

		async updateStatus(consultation, newStatus) {
			const id = consultation.id || consultation.uuid
			try {
				await axios.put(
					generateUrl(`/apps/procest/api/consultations/${encodeURIComponent(id)}/status`),
					{ status: newStatus },
				)
				await this.loadConsultations()
			} catch (err) {
				console.error('Failed to update status', err)
			}
		},

		async onSubmitResponse(consultation, responseData) {
			const id = consultation.id || consultation.uuid
			this.saving = true
			try {
				await axios.post(
					generateUrl(`/apps/procest/api/consultations/${encodeURIComponent(id)}/response`),
					responseData,
				)
				this.respondingId = null
				await this.loadConsultations()
			} catch (err) {
				console.error('Failed to submit response', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.consultation-dashboard {
	max-width: 900px;
	margin: 0 auto;
	padding: 24px;
}

.consultation-dashboard__header {
	margin-bottom: 24px;
}

.consultation-dashboard__subtitle {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.consultation-dashboard__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.consultation-dashboard__summary {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.consultation-dashboard__badge {
	font-size: 0.8125rem;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.consultation-dashboard__badge--overdue {
	background: var(--color-error-soft, #fce4ec);
	color: var(--color-error, #c62828);
}

.consultation-dashboard__loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.consultation-dashboard__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 32px;
}

.consultation-dashboard__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 12px;
}

.consultation-dashboard__item--overdue {
	border-color: var(--color-error);
	background: var(--color-error-soft, #fff5f5);
}

.consultation-dashboard__item--spoed {
	border-left: 4px solid var(--color-warning, #e65100);
}

.consultation-dashboard__item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.consultation-dashboard__number {
	font-weight: 600;
}

.consultation-dashboard__status {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.status--in_behandeling {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.status--advies_uitgebracht {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.consultation-dashboard__spoed {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning-soft, #fff3e0);
	color: var(--color-warning, #e65100);
	font-weight: 600;
}

.consultation-dashboard__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-bottom: 4px;
}

.consultation-dashboard__case {
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
}

.consultation-dashboard__question {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.consultation-dashboard__deadline {
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.consultation-dashboard__deadline--overdue {
	color: var(--color-error, #c62828);
	font-weight: 600;
}

.consultation-dashboard__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
