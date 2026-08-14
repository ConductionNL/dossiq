<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Doorlooptijd KPI strip — three at-a-glance cards: SLA compliance rate,
	number of at-risk cases, and number of completed cases in the selected
	period. Extracted from the monolithic DoorlooptijdDashboard.vue so the
	KPI presentation is reusable and independently testable. Behaviour is
	identical to the inline markup it replaces.

	Spec: openspec/specs/doorlooptijd-dashboard/spec.md
-->
<template>
	<div class="doorlooptijd-kpi-row">
		<div class="kpi-card kpi-card--primary">
			<div class="kpi-card__value">
				{{ slaData.overallRate !== null ? slaData.overallRate + '%' : '—' }}
			</div>
			<div class="kpi-card__label">
				{{ t('procest', 'SLA Compliance') }}
			</div>
			<div class="kpi-card__sub">
				{{
					slaData.total > 0
						? t('procest', '{within}/{total} within SLA', {
								within: slaData.withinSla,
								total: slaData.total,
							})
						: t('procest', 'No data')
				}}
			</div>
			<div v-if="slaData.excluded > 0" class="kpi-card__note">
				{{
					t('procest', '{count} cases excluded — no SLA target', {
						count: slaData.excluded,
					})
				}}
			</div>
		</div>

		<div class="kpi-card">
			<div class="kpi-card__value">
				{{ atRiskCount }}
			</div>
			<div class="kpi-card__label">
				{{ t('procest', 'At Risk') }}
			</div>
			<div class="kpi-card__sub">
				{{ t('procest', 'cases near or past deadline') }}
			</div>
		</div>

		<div class="kpi-card">
			<div class="kpi-card__value">
				{{ completedCount }}
			</div>
			<div class="kpi-card__label">
				{{ t('procest', 'Completed') }}
			</div>
			<div class="kpi-card__sub">
				{{ t('procest', 'in selected period') }}
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'DeadlineKpiRow',
	props: {
		/**
		 * SLA compliance summary as produced by computeSlaCompliance():
		 * { overallRate, withinSla, total, excluded }.
		 */
		slaData: {
			type: Object,
			required: true,
		},

		/** Count of cases near or past their deadline. */
		atRiskCount: {
			type: Number,
			default: 0,
		},

		/** Count of cases completed in the selected period. */
		completedCount: {
			type: Number,
			default: 0,
		},
	},
}
</script>

<style scoped>
.doorlooptijd-kpi-row {
	display: flex;
	gap: 16px;
	margin-bottom: 24px;
}

.kpi-card {
	flex: 1;
	padding: 16px 20px;
	border-radius: 8px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
}

.kpi-card--primary {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

.kpi-card__value {
	font-size: 32px;
	font-weight: 700;
	line-height: 1.2;
}

.kpi-card__label {
	font-size: 14px;
	font-weight: 600;
	margin-top: 4px;
}

.kpi-card__sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.kpi-card__note {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	font-style: italic;
}

@media (max-width: 768px) {
	.doorlooptijd-kpi-row {
		flex-direction: column;
	}
}
</style>
