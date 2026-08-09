<template>
	<div class="complaint-analytics">
		<header class="complaint-analytics__header">
			<h2>{{ t('procest', 'Complaint analytics') }}</h2>
			<div class="complaint-analytics__period">
				<label>
					{{ t('procest', 'Period') }}:
					<select v-model="period" @change="fetch">
						<option value="q">{{ t('procest', 'This quarter') }}</option>
						<option value="ytd">{{ t('procest', 'Year to date') }}</option>
						<option value="12m">{{ t('procest', 'Trailing 12 months') }}</option>
					</select>
				</label>
			</div>
		</header>

		<div v-if="loading" class="complaint-analytics__empty">
			{{ t('procest', 'Loading analytics…') }}
		</div>
		<div v-else class="complaint-analytics__grid">
			<section class="complaint-analytics__card">
				<h3>{{ t('procest', 'By category') }}</h3>
				<table>
					<thead>
						<tr>
							<th scope="col">{{ t('procest', 'Category') }}</th>
							<th scope="col" class="num">{{ t('procest', 'Count') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in analytics.byCategory || []" :key="row.category">
							<td>{{ row.category }}</td>
							<td class="num">
								{{ row.count }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="complaint-analytics__card">
				<h3>{{ t('procest', 'Employee thresholds (≥3 in 6 months)') }}</h3>
				<ul v-if="(analytics.employeeAlerts || []).length">
					<li v-for="alert in analytics.employeeAlerts" :key="alert.employeeAnon">
						{{ alert.employeeAnon }} — {{ alert.count }} {{ t('procest', 'complaints') }}
					</li>
				</ul>
				<p v-else>
					{{ t('procest', 'No alerts above threshold.') }}
				</p>
			</section>

			<section class="complaint-analytics__card">
				<h3>{{ t('procest', 'Systemic issues (>50% QoQ)') }}</h3>
				<ul v-if="(analytics.systemic || []).length">
					<li v-for="issue in analytics.systemic" :key="issue.category">
						{{ issue.category }} — {{ Math.round(issue.qoqDelta * 100) }}%
					</li>
				</ul>
				<p v-else>
					{{ t('procest', 'No systemic issues detected.') }}
				</p>
			</section>

			<section class="complaint-analytics__card">
				<h3>{{ t('procest', 'Resolution time') }}</h3>
				<p>{{ t('procest', 'Average') }}: {{ analytics.avgDurationDays || '-' }} {{ t('procest', 'days') }}</p>
				<p>{{ t('procest', 'Within Awb deadline') }}: {{ analytics.onTimePct || '-' }}%</p>
			</section>
		</div>
	</div>
</template>

<script>
/**
 * Analytics dashboard for complaint management.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
 */
export default {
	name: 'ComplaintAnalyticsDashboard',

	data() {
		return {
			loading: true,
			period: 'q',
			analytics: {},
		}
	},

	mounted() {
		this.fetch()
	},

	methods: {
		/**
		 * Fetch analytics for the selected period.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		async fetch() {
			this.loading = true
			try {
				const res = await fetch(
					OC.generateUrl('/apps/procest/api/complaints/analytics?period=' + this.period),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (res.ok) {
					this.analytics = await res.json()
				}
			} catch (e) {
				this.analytics = {}
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.complaint-analytics {
	padding: var(--default-grid-baseline, 8px);
}

.complaint-analytics__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.complaint-analytics__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: var(--default-grid-baseline, 8px);
}

.complaint-analytics__card {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-main-background);
	border-radius: var(--border-radius);
}

.complaint-analytics__card table {
	width: 100%;
	border-collapse: collapse;
}

.complaint-analytics__card td {
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.complaint-analytics__card td.num {
	text-align: right;
	font-weight: 600;
}

.complaint-analytics__empty {
	padding: calc(var(--default-grid-baseline, 8px) * 4);
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
