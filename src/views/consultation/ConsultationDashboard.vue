<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="consultation-dashboard">
		<h2 class="consultation-dashboard__title">
			{{ t('procest', 'Consultations') }}
		</h2>

		<!-- Summary bar -->
		<div class="consultation-dashboard__summary">
			<span class="consultation-dashboard__summary-item">
				<strong>{{ openCount }}</strong> {{ t('procest', 'open') }}
			</span>
			<span
				v-if="overdueCount > 0"
				class="consultation-dashboard__summary-item consultation-dashboard__summary-item--overdue">
				<strong>{{ overdueCount }}</strong> {{ t('procest', 'expired') }}
			</span>
		</div>

		<!-- Filter bar -->
		<div class="consultation-dashboard__filters">
			<NcTextField
				:model-value="filters.search"
				:label="t('procest', 'Search')"
				:placeholder="t('procest', 'Search by subject, department...')"
				@update:model-value="(v) => (filters.search = v)" />

			<NcSelect
				v-model="filters.status"
				:options="statusOptions"
				:aria-label-combobox="t('procest', 'Status filter')"
				label="label"
				:reduce="(opt) => opt.value"
				:placeholder="t('procest', 'All statuses')" />

			<div class="consultation-dashboard__date-range">
				<label
					class="consultation-dashboard__filter-label"
					for="consultation-dashboard-deadline-from">
					{{ t('procest', 'Deadline from') }}
				</label>
				<input
					id="consultation-dashboard-deadline-from"
					v-model="filters.dateFrom"
					type="date"
					class="consultation-dashboard__date-input" />
				<label
					class="consultation-dashboard__filter-label"
					for="consultation-dashboard-deadline-to">
					{{ t('procest', 'to') }}
				</label>
				<input
					id="consultation-dashboard-deadline-to"
					v-model="filters.dateTo"
					type="date"
					class="consultation-dashboard__date-input" />
			</div>

			<NcButton @click="loadConsultations">
				{{ t('procest', 'Filter') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="consultation-dashboard__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- Error state -->
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- Empty state -->
		<div
			v-else-if="displayItems.length === 0"
			class="consultation-dashboard__empty">
			{{ t('procest', 'No consultations found.') }}
		</div>

		<!-- Consultation table -->
		<div v-else class="consultation-dashboard__table-wrap">
			<table class="consultation-dashboard__table">
				<thead>
					<tr>
						<th scope="col">{{ t('procest', 'Number') }}</th>
						<th scope="col">{{ t('procest', 'Case') }}</th>
						<th scope="col">{{ t('procest', 'Onderwerp') }}</th>
						<th scope="col">{{ t('procest', 'Department') }}</th>
						<th scope="col">{{ t('procest', 'Deadline') }}</th>
						<th scope="col">{{ t('procest', 'Status') }}</th>
						<th scope="col">{{ t('procest', 'Acties') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="item in displayItems"
						:key="item.id"
						:class="{
							'consultation-dashboard__row--overdue': isOverdue(item),
						}">
						<td class="consultation-dashboard__cell--number">
							{{ item.nummer || item.id }}
						</td>
						<td>{{ item.parentZaak }}</td>
						<td>{{ item.onderwerp }}</td>
						<td>{{ item.adviesInstantie }}</td>
						<td
							:class="{
								'consultation-dashboard__cell--overdue':
									isOverdue(item),
							}">
							{{ formatDate(item.uiterlijkeReactiedatum) }}
						</td>
						<td>
							<span
								class="consultation-dashboard__status-badge"
								:class="
									'consultation-dashboard__status-badge--'
									+ item.status
								">
								{{ getStatusLabel(item.status) }}
							</span>
						</td>
						<td>
							<NcButton
								v-if="item.status === 'open'"
								type="secondary"
								:title="t('procest', 'Take on consultation')"
								@click="claimConsultation(item)">
								{{ t('procest', 'Take on') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'ConsultationDashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			loading: false,
			error: '',
			consultations: [],
			overdueItems: [],
			filters: {
				search: '',
				status: null,
				dateFrom: '',
				dateTo: '',
			},
			statusOptions: [
				{ label: this.t('procest', 'Open'), value: 'open' },
				{
					label: this.t('procest', 'In behandeling'),
					value: 'in_behandeling',
				},
				{
					label: this.t('procest', 'Advice issued'),
					value: 'advies_uitgebracht',
				},
				{ label: this.t('procest', 'Closed'), value: 'afgesloten' },
			],
		}
	},
	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		openCount() {
			return this.consultations.filter(
				(c) => c.status === 'open' || c.status === 'in_behandeling',
			).length
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		overdueCount() {
			return this.overdueItems.length
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		overdueIds() {
			return new Set(this.overdueItems.map((c) => c.id))
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		displayItems() {
			let items = [...this.consultations]

			if (this.filters.search.trim()) {
				const term = this.filters.search.trim().toLowerCase()
				items = items.filter(
					(c) =>
						(c.onderwerp || '').toLowerCase().includes(term)
						|| (c.adviesInstantie || '').toLowerCase().includes(term)
						|| (c.parentZaak || '').toLowerCase().includes(term),
				)
			}

			if (this.filters.status) {
				items = items.filter((c) => c.status === this.filters.status)
			}

			if (this.filters.dateFrom) {
				items = items.filter(
					(c) => c.uiterlijkeReactiedatum >= this.filters.dateFrom,
				)
			}

			if (this.filters.dateTo) {
				items = items.filter(
					(c) => c.uiterlijkeReactiedatum <= this.filters.dateTo,
				)
			}

			// Overdue items first.
			items.sort((a, b) => {
				const aOverdue = this.overdueIds.has(a.id) ? 0 : 1
				const bOverdue = this.overdueIds.has(b.id) ? 0 : 1
				if (aOverdue !== bOverdue) return aOverdue - bOverdue
				return (a.uiterlijkeReactiedatum || '')
					< (b.uiterlijkeReactiedatum || '')
					? -1
					: 1
			})

			return items
		},
	},
	async mounted() {
		await this.loadConsultations()
	},
	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		async loadConsultations() {
			this.loading = true
			this.error = ''
			try {
				const [overdueRes, allRes] = await Promise.all([
					axios.get('/apps/procest/api/consultations/overdue'),
					axios.get('/apps/procest/api/consultations', {
						params: {
							status: this.filters.status || undefined,
							dateFrom: this.filters.dateFrom || undefined,
							dateTo: this.filters.dateTo || undefined,
						},
					}),
				])
				this.overdueItems = overdueRes.data?.items || overdueRes.data || []
				this.consultations = allRes.data?.items || allRes.data || []
			} catch (err) {
				this.error = this.t('procest', 'Consultations could not be loaded.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param item
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		isOverdue(item) {
			return this.overdueIds.has(item.id)
		},
		/**
		 * @param dateStr
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		formatDate(dateStr) {
			if (!dateStr) return '—'
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleDateString('nl-NL')
		},
		/**
		 * @param status
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		getStatusLabel(status) {
			const labels = {
				open: this.t('procest', 'Open'),
				in_behandeling: this.t('procest', 'In behandeling'),
				advies_uitgebracht: this.t('procest', 'Advice issued'),
				afgesloten: this.t('procest', 'Closed'),
			}
			return labels[status] || status
		},
		/**
		 * @param item
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		async claimConsultation(item) {
			try {
				await axios.post(
					`/apps/procest/api/consultations/${encodeURIComponent(item.id)}/claim`,
				)
				await this.loadConsultations()
			} catch (err) {
				this.error = this.t('procest', 'Could not take on the consultation.')
			}
		},
	},
}
</script>

<style scoped>
.consultation-dashboard {
	padding: 16px;
}

.consultation-dashboard__title {
	margin: 0 0 16px;
}

.consultation-dashboard__summary {
	display: flex;
	gap: 20px;
	margin-bottom: 16px;
	font-size: 0.95em;
}

.consultation-dashboard__summary-item--overdue {
	color: var(--color-error);
}

.consultation-dashboard__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 20px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.consultation-dashboard__date-range {
	display: flex;
	align-items: center;
	gap: 6px;
}

.consultation-dashboard__filter-label {
	font-size: 0.875em;
	white-space: nowrap;
}

.consultation-dashboard__date-input {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 5px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-dashboard__loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.consultation-dashboard__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
	text-align: center;
}

.consultation-dashboard__table-wrap {
	overflow-x: auto;
}

.consultation-dashboard__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.consultation-dashboard__table th {
	text-align: left;
	font-weight: 600;
	padding: 8px 10px;
	border-bottom: 2px solid var(--color-border);
}

.consultation-dashboard__table td {
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.consultation-dashboard__row--overdue {
	background: var(--color-error-light, #fff5f5);
}

.consultation-dashboard__cell--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.consultation-dashboard__cell--number {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.consultation-dashboard__status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.8em;
	background: var(--color-background-dark);
}

.consultation-dashboard__status-badge--open {
	background: var(--color-background-dark);
}

.consultation-dashboard__status-badge--in_behandeling {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.consultation-dashboard__status-badge--advies_uitgebracht {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.consultation-dashboard__status-badge--afgesloten {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
