<template>
	<div class="complaint-detail">
		<header class="complaint-detail__header">
			<button type="button" class="complaint-detail__back" @click="$emit('close')">
				← {{ t('procest', 'Back') }}
			</button>
			<h2>{{ complaint.title || t('procest', 'Complaint detail') }}</h2>
		</header>

		<div v-if="loading" class="complaint-detail__empty">
			{{ t('procest', 'Loading complaint…') }}
		</div>
		<div v-else class="complaint-detail__body">
			<section class="complaint-detail__section">
				<h3>{{ t('procest', 'Summary') }}</h3>
				<dl>
					<dt>{{ t('procest', 'Status') }}</dt>
					<dd>{{ complaint.status }}</dd>
					<dt>{{ t('procest', 'Category') }}</dt>
					<dd>{{ complaint.category }}</dd>
					<dt>{{ t('procest', 'Handler') }}</dt>
					<dd>{{ complaint.handler || '-' }}</dd>
				</dl>
			</section>

			<DeadlinePanel
				v-if="complaint.deadline"
				:deadline="complaint.deadline"
				:warned-at="complaint.warnedAt" />

			<section class="complaint-detail__section">
				<h3>{{ t('procest', 'Hearings') }}</h3>
				<ul v-if="hearings.length">
					<li v-for="h in hearings" :key="h.id">
						{{ h.format }} — {{ h.scheduledAt }} — {{ h.outcome || t('procest', 'pending') }}
					</li>
				</ul>
				<p v-else>
					{{ t('procest', 'No hearings scheduled.') }}
				</p>
			</section>

			<ActivityTimeline :events="timeline" />
		</div>
	</div>
</template>

<script>
import ActivityTimeline from '../cases/components/ActivityTimeline.vue'
import DeadlinePanel from './components/DeadlinePanel.vue'

/**
 * Complaint detail view.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
 */
export default {
	name: 'ComplaintDetail',

	components: {
		ActivityTimeline,
		DeadlinePanel,
	},

	props: {
		complaintId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			complaint: {},
			hearings: [],
			timeline: [],
		}
	},

	watch: {
		complaintId: {
			immediate: true,
			/**
			 * Refetch the complaint on id change.
			 *
			 * @return {void}
			 *
			 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
			 */
			handler() {
				this.fetch()
			},
		},
	},

	methods: {
		/**
		 * Fetch the complaint detail.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		async fetch() {
			this.loading = true
			try {
				const base = OC.generateUrl('/apps/procest/api/complaints/' + this.complaintId)
				const [detailRes, hearingsRes] = await Promise.all([
					fetch(base, { headers: { requesttoken: OC.requestToken } }),
					fetch(base + '/hearings', { headers: { requesttoken: OC.requestToken } }),
				])
				if (detailRes.ok) {
					this.complaint = await detailRes.json()
				}
				if (hearingsRes.ok) {
					const hb = await hearingsRes.json()
					this.hearings = Array.isArray(hb) ? hb : []
				}
				this.timeline = this.complaint.timeline || []
			} catch (e) {
				this.complaint = {}
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.complaint-detail {
	padding: var(--default-grid-baseline, 8px);
}

.complaint-detail__header {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
}

.complaint-detail__back {
	background: transparent;
	border: 0;
	cursor: pointer;
}

.complaint-detail__section {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}

.complaint-detail__section dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
}

.complaint-detail__empty {
	padding: calc(var(--default-grid-baseline, 8px) * 4);
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
