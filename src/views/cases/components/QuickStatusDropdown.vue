<template>
	<div class="quick-status" @click.stop>
		<NcSelect
			v-model="selectedStatus"
			:options="statusOptions"
			:input-label="t('procest', 'Change status')"
			label="name"
			track-by="id"
			:placeholder="t('procest', 'Change status')"
			:disabled="saving"
			class="quick-status__select"
			@input="onStatusChange" />
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { parseJsonArray } from '../../../utils/caseHelpers.js'

export default {
	name: 'QuickStatusDropdown',
	components: {
		NcSelect,
	},
	props: {
		caseObj: {
			type: Object,
			required: true,
		},
		statusTypes: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['changed'],
	data() {
		return {
			selectedStatus: null,
			saving: false,
		}
	},
	computed: {
		/** @spec openspec/specs/status-transition-engine/spec.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/specs/status-transition-engine/spec.md */
		statusOptions() {
			return [...this.statusTypes].sort((a, b) => (a.order || 0) - (b.order || 0))
		},
	},
	mounted() {
		this.selectedStatus = this.statusTypes.find(st => st.id === this.caseObj.status) || null
	},
	methods: {
		/**
		 * @param newStatus
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async onStatusChange(newStatus) {
			if (!newStatus || newStatus.id === this.caseObj.status) return

			this.saving = true
			const now = new Date().toISOString()
			const currentUser = OC?.currentUser || 'unknown'

			// statusHistory/activity are JSON-encoded strings per the case
			// schema (procest_register.json); parse tolerantly, append,
			// re-encode.
			const statusHistory = parseJsonArray(this.caseObj.statusHistory)
			statusHistory.push({
				status: newStatus.id,
				date: now,
				changedBy: currentUser,
			})

			const activity = parseJsonArray(this.caseObj.activity)
			activity.push({
				date: now,
				type: 'status_change',
				description: t('procest', 'Status changed to \'{status}\'', { status: newStatus.name }),
				user: currentUser,
			})

			const updateData = {
				...this.caseObj,
				status: newStatus.id,
				statusHistory: JSON.stringify(statusHistory),
				activity: JSON.stringify(activity),
			}

			if (newStatus.isFinal) {
				updateData.endDate = now.split('T')[0] + 'T17:00:00Z'
			}

			const result = await this.objectStore.saveObject('case', updateData)
			this.saving = false

			if (result) {
				this.$emit('changed', result)
			}
		},
	},
}
</script>

<style scoped>
.quick-status {
	min-width: 160px;
}

.quick-status__select {
	width: 100%;
}
</style>
