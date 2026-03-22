<template>
	<div>
		<CnListPage
			:title="t('procest', 'Complaints')"
			:items="complaints"
			:loading="loading"
			:columns="columns"
			:filters="filters"
			:empty-label="t('procest', 'No complaints found')"
			:empty-description="t('procest', 'Register a new complaint to get started')"
			@row-click="onRowClick">
			<template #header-actions>
				<NcButton type="primary" @click="showCreateDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('procest', 'New Complaint') }}
				</NcButton>
			</template>

			<template #column-status="{ item }">
				<span class="status-badge" :class="'status-badge--' + item.status">
					{{ getStatusLabel(item.status) }}
				</span>
			</template>

			<template #column-prioriteit="{ item }">
				<span
					v-if="item.prioriteit && item.prioriteit !== 'normaal'"
					class="priority-badge"
					:class="'priority-badge--' + item.prioriteit">
					{{ getPriorityLabel(item.prioriteit) }}
				</span>
				<span v-else>{{ t('procest', 'Normal') }}</span>
			</template>

			<template #column-deadline="{ item }">
				<span :class="deadlineClass(item)">
					{{ formatDeadlineText(item) }}
				</span>
			</template>
		</CnListPage>

		<ComplaintCreateDialog
			v-if="showCreateDialog"
			:categories="categories"
			@close="showCreateDialog = false"
			@created="onComplaintCreated" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnListPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import ComplaintCreateDialog from './components/ComplaintCreateDialog.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'ComplaintList',
	components: {
		NcButton,
		CnListPage,
		ComplaintCreateDialog,
		Plus,
	},
	data() {
		return {
			loading: false,
			complaints: [],
			categories: [],
			showCreateDialog: false,
		}
	},
	computed: {
		columns() {
			return [
				{ key: 'klachtnummer', label: this.t('procest', 'Number'), sortable: true },
				{ key: 'onderwerp', label: this.t('procest', 'Subject'), sortable: true },
				{ key: 'categorie', label: this.t('procest', 'Category'), sortable: true },
				{ key: 'status', label: this.t('procest', 'Status'), sortable: true },
				{ key: 'ontvangstdatum', label: this.t('procest', 'Received'), sortable: true },
				{ key: 'deadline', label: this.t('procest', 'Deadline'), sortable: true },
				{ key: 'prioriteit', label: this.t('procest', 'Priority'), sortable: true },
			]
		},
		filters() {
			return [
				{
					key: 'status',
					label: this.t('procest', 'Status'),
					options: [
						{ value: 'ontvangen', label: this.t('procest', 'Received') },
						{ value: 'ontvangst_bevestigd', label: this.t('procest', 'Acknowledged') },
						{ value: 'in_behandeling', label: this.t('procest', 'In progress') },
						{ value: 'hoorgesprek_gepland', label: this.t('procest', 'Hearing planned') },
						{ value: 'hoorgesprek_afgerond', label: this.t('procest', 'Hearing completed') },
						{ value: 'afgehandeld', label: this.t('procest', 'Resolved') },
						{ value: 'ingetrokken', label: this.t('procest', 'Withdrawn') },
					],
				},
				{
					key: 'prioriteit',
					label: this.t('procest', 'Priority'),
					options: [
						{ value: 'laag', label: this.t('procest', 'Low') },
						{ value: 'normaal', label: this.t('procest', 'Normal') },
						{ value: 'hoog', label: this.t('procest', 'High') },
						{ value: 'urgent', label: this.t('procest', 'Urgent') },
					],
				},
			]
		},
	},
	mounted() {
		this.loadComplaints()
	},
	methods: {
		async loadComplaints() {
			this.loading = true
			try {
				const store = useObjectStore()
				const response = await store.fetchObjects('complaint')
				this.complaints = response?.results || []
			} catch (error) {
				console.error('Failed to load complaints:', error)
			} finally {
				this.loading = false
			}
		},
		getStatusLabel(status) {
			const labels = {
				ontvangen: this.t('procest', 'Received'),
				ontvangst_bevestigd: this.t('procest', 'Acknowledged'),
				in_behandeling: this.t('procest', 'In progress'),
				hoorgesprek_gepland: this.t('procest', 'Hearing planned'),
				hoorgesprek_afgerond: this.t('procest', 'Hearing completed'),
				afgehandeld: this.t('procest', 'Resolved'),
				ingetrokken: this.t('procest', 'Withdrawn'),
			}
			return labels[status] || status
		},
		getPriorityLabel(priority) {
			const labels = {
				laag: this.t('procest', 'Low'),
				normaal: this.t('procest', 'Normal'),
				hoog: this.t('procest', 'High'),
				urgent: this.t('procest', 'Urgent'),
			}
			return labels[priority] || priority
		},
		deadlineClass(item) {
			if (!item.afhandelDeadline) return ''
			const now = new Date()
			const deadline = new Date(item.afhandelDeadline)
			const daysLeft = Math.ceil((deadline - now) / (1000 * 60 * 60 * 24))
			if (daysLeft < 0) return 'deadline--overdue'
			if (daysLeft <= 7) return 'deadline--warning'
			return ''
		},
		formatDeadlineText(item) {
			if (!item.afhandelDeadline) return '\u2014'
			const now = new Date()
			const deadline = new Date(item.afhandelDeadline)
			const daysLeft = Math.ceil((deadline - now) / (1000 * 60 * 60 * 24))
			if (daysLeft < 0) {
				return this.t('procest', '{days} days overdue', { days: Math.abs(daysLeft) })
			}
			return this.t('procest', '{days} days left', { days: daysLeft })
		},
		onRowClick(item) {
			this.$router.push({ name: 'ComplaintDetail', params: { id: item.id } })
		},
		onComplaintCreated() {
			this.showCreateDialog = false
			this.loadComplaints()
		},
	},
}
</script>

<style scoped>
.deadline--overdue {
	color: var(--color-error);
	font-weight: bold;
}

.deadline--warning {
	color: var(--color-warning);
	font-weight: 500;
}
</style>
