<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Archief e-Depot dashboard — KPI cards + triggers/batch tables + quick
  actions. Backed by `archief#dashboardStats` and `archief#auditLog`.
-->
<template>
	<div class="archief-dashboard">
		<div class="archief-dashboard__header">
			<h2>{{ t('procest', 'Archief e-Depot handover') }}</h2>
			<div class="archief-dashboard__controls">
				<NcButton type="secondary" @click="load">
					<template #icon><Refresh :size="18" /></template>
					{{ t('procest', 'Refresh') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<div v-if="!loading && stats" class="archief-dashboard__grid">
			<div class="kpi-card kpi-card--neutral">
				<div class="kpi-card__label">{{ t('procest', 'Ready') }}</div>
				<div class="kpi-card__value">{{ stats.ready }}</div>
			</div>
			<div class="kpi-card kpi-card--neutral">
				<div class="kpi-card__label">{{ t('procest', 'In progress') }}</div>
				<div class="kpi-card__value">{{ stats.inProgress }}</div>
			</div>
			<div class="kpi-card kpi-card--alert">
				<div class="kpi-card__label">{{ t('procest', 'Failed') }}</div>
				<div class="kpi-card__value">{{ stats.failed }}</div>
			</div>
			<div class="kpi-card kpi-card--good">
				<div class="kpi-card__label">{{ t('procest', 'Completed') }}</div>
				<div class="kpi-card__value">{{ stats.completed }}</div>
			</div>
			<div class="kpi-card kpi-card--neutral">
				<div class="kpi-card__label">{{ t('procest', 'Total transferred') }}</div>
				<div class="kpi-card__value">{{ stats.totalTransferred }}</div>
			</div>
		</div>

		<div class="archief-dashboard__actions">
			<NcButton type="primary" :disabled="initiating" @click="initiateBatch">
				<template #icon>
					<NcLoadingIcon v-if="initiating" :size="18" />
					<Play v-else :size="18" />
				</template>
				{{ t('procest', 'Initiate batch') }}
			</NcButton>
			<NcButton type="secondary" :disabled="retrying" @click="retryFailed">
				<template #icon><Replay :size="18" /></template>
				{{ t('procest', 'Retry failed') }}
			</NcButton>
		</div>

		<div class="archief-dashboard__section">
			<h3>{{ t('procest', 'Recent triggers') }}</h3>
			<table v-if="triggers.length > 0" class="archief-dashboard__table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Case ref') }}</th>
						<th>{{ t('procest', 'Zaaktype') }}</th>
						<th>{{ t('procest', 'Status') }}</th>
						<th>{{ t('procest', 'Triggered at') }}</th>
						<th>{{ t('procest', 'Acties') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="t2 in triggers" :key="t2.id">
						<td>{{ t2.caseRef || t2.zaakRef || '—' }}</td>
						<td>{{ t2.zaaktypeKey || '—' }}</td>
						<td>
							<span class="archief-dashboard__badge" :class="badgeClass(t2.status)">{{ t2.status }}</span>
						</td>
						<td>{{ t2.triggeredAt || t2.createdAt || '—' }}</td>
						<td>
							<NcButton v-if="t2.proofRef" size="small" @click="viewProof(t2)">
								{{ t('procest', 'View proof') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcEmptyContent v-else :name="t('procest', 'No triggers yet')">
				<template #icon><PackageVariantClosed :size="48" /></template>
			</NcEmptyContent>
		</div>

		<div class="archief-dashboard__section">
			<h3>{{ t('procest', 'Audit log') }}</h3>
			<table v-if="audit.length > 0" class="archief-dashboard__table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Timestamp') }}</th>
						<th>{{ t('procest', 'Actor') }}</th>
						<th>{{ t('procest', 'Action') }}</th>
						<th>{{ t('procest', 'Target') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in audit.slice(0, 50)" :key="row.id">
						<td>{{ row.timestamp || row.createdAt }}</td>
						<td>{{ row.actor || row.userId || '—' }}</td>
						<td>{{ row.action || row.event }}</td>
						<td>{{ row.targetId || row.target || '—' }}</td>
					</tr>
				</tbody>
			</table>
			<NcEmptyContent v-else :name="t('procest', 'No audit entries')">
				<template #icon><ScriptText :size="48" /></template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Replay from 'vue-material-design-icons/Replay.vue'
import Play from 'vue-material-design-icons/Play.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import ScriptText from 'vue-material-design-icons/ScriptText.vue'

export default {
	name: 'ArchiefDashboard',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, Refresh, Replay, Play, PackageVariantClosed, ScriptText },
	data() {
		return {
			loading: false,
			initiating: false,
			retrying: false,
			error: null,
			stats: null,
			triggers: [],
			audit: [],
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const [s, a] = await Promise.all([
					axios.get(generateUrl('/apps/procest/api/archief/dashboard/stats')),
					axios.get(generateUrl('/apps/procest/api/archief/audit-log')),
				])
				this.stats = s.data || null
				this.triggers = (s.data && s.data.recent) || []
				this.audit = Array.isArray(a.data) ? a.data : (a.data?.results || [])
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load dashboard')
			} finally {
				this.loading = false
			}
		},
		badgeClass(status) {
			const s = (status || '').toLowerCase()
			if (s === 'geslaagd' || s === 'completed') return 'archief-dashboard__badge--good'
			if (s.includes('mislukt') || s === 'failed') return 'archief-dashboard__badge--alert'
			if (s.includes('overdracht') || s.includes('bundling') || s === 'in-progress') return 'archief-dashboard__badge--neutral'
			return 'archief-dashboard__badge--neutral'
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		async initiateBatch() {
			this.initiating = true
			try {
				await axios.post(generateUrl('/apps/procest/api/archief/batch'))
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to initiate batch')
			} finally {
				this.initiating = false
			}
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		async retryFailed() {
			this.retrying = true
			try {
				await axios.post(generateUrl('/apps/procest/api/archief/retry-failed'))
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to retry')
			} finally {
				this.retrying = false
			}
		},
		viewProof(trigger) {
			const proof = trigger.proofRef
			if (!proof) return
			window.open(generateUrl('/apps/procest/api/archief/proof/' + encodeURIComponent(proof)), '_blank')
		},
	},
}
</script>

<style scoped>
.archief-dashboard {
	padding: 16px;
	max-width: 1200px;
	margin: 0 auto;
}

.archief-dashboard__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.archief-dashboard__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.kpi-card {
	padding: 16px;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.kpi-card--good { border-left: 4px solid var(--color-success); }
.kpi-card--alert { border-left: 4px solid var(--color-error); }
.kpi-card--neutral { border-left: 4px solid var(--color-primary-element); }

.kpi-card__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-bottom: 6px;
}

.kpi-card__value {
	font-size: 24px;
	font-weight: 600;
}

.archief-dashboard__actions {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.archief-dashboard__section {
	margin-top: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.archief-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.archief-dashboard__table th,
.archief-dashboard__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.archief-dashboard__table th {
	background: var(--color-background-dark);
	font-weight: 500;
}

.archief-dashboard__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	display: inline-block;
}

.archief-dashboard__badge--good {
	background: var(--color-success);
	color: var(--color-main-background);
}

.archief-dashboard__badge--alert {
	background: var(--color-error);
	color: var(--color-main-background);
}

.archief-dashboard__badge--neutral {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
