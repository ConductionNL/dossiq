<template>
	<div class="complaint-dashboard-widget">
		<header class="complaint-dashboard-widget__header">
			<h3>{{ t('procest', 'Complaints') }}</h3>
			<a :href="moreUrl">{{ t('procest', 'View all') }}</a>
		</header>

		<div class="complaint-dashboard-widget__stats">
			<div class="complaint-dashboard-widget__stat">
				<span class="stat-label">{{ t('procest', 'Open') }}</span>
				<span class="stat-value">{{ stats.open || 0 }}</span>
			</div>
			<div class="complaint-dashboard-widget__stat">
				<span class="stat-label">{{ t('procest', 'Due ≤ 7d') }}</span>
				<span class="stat-value">{{ stats.dueSoon || 0 }}</span>
			</div>
			<div class="complaint-dashboard-widget__stat">
				<span class="stat-label">{{ t('procest', 'Overdue') }}</span>
				<span class="stat-value">{{ stats.overdue || 0 }}</span>
			</div>
		</div>

		<ul class="complaint-dashboard-widget__list">
			<li v-for="item in topItems" :key="item.id" class="complaint-dashboard-widget__item">
				<a :href="itemUrl(item.id)">{{ item.title }}</a>
				<span class="complaint-dashboard-widget__deadline">{{ item.deadline }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
/**
 * Compact dashboard widget for complaints.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
 */
export default {
	name: 'ComplaintDashboardWidget',

	data() {
		return {
			stats: { open: 0, dueSoon: 0, overdue: 0 },
			topItems: [],
		}
	},

	computed: {
		/**
		 * Link to the complaint list.
		 *
		 * @return {string} URL.
		 */
		moreUrl() {
			return OC.generateUrl('/apps/procest/#/complaints')
		},
	},

	mounted() {
		this.fetch()
	},

	methods: {
		/**
		 * Fetch KPI + top items.
		 *
		 * @return {Promise<void>}
		 */
		async fetch() {
			try {
				const [kpiRes, alertsRes] = await Promise.all([
					fetch(OC.generateUrl('/apps/procest/api/complaints/kpi'), {
						headers: { requesttoken: OC.requestToken },
					}),
					fetch(OC.generateUrl('/apps/procest/api/complaints/deadline-alerts'), {
						headers: { requesttoken: OC.requestToken },
					}),
				])
				if (kpiRes.ok) {
					this.stats = await kpiRes.json()
				}
				if (alertsRes.ok) {
					const list = await alertsRes.json()
					this.topItems = (Array.isArray(list) ? list : []).slice(0, 5)
				}
			} catch (e) {
				this.stats = { open: 0, dueSoon: 0, overdue: 0 }
				this.topItems = []
			}
		},

		/**
		 * Link to a complaint detail page.
		 *
		 * @param {string} id Complaint id.
		 * @return {string} URL.
		 */
		itemUrl(id) {
			return OC.generateUrl('/apps/procest/#/complaints/' + id)
		},
	},
}
</script>

<style scoped>
.complaint-dashboard-widget {
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-main-background);
	border-radius: var(--border-radius);
}

.complaint-dashboard-widget__header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
}

.complaint-dashboard-widget__stats {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: var(--default-grid-baseline, 8px);
	margin: var(--default-grid-baseline, 8px) 0;
}

.complaint-dashboard-widget__stat {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.stat-value {
	font-size: 1.5rem;
	font-weight: 600;
}

.stat-label {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.complaint-dashboard-widget__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.complaint-dashboard-widget__item {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
