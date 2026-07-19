<template>
	<div class="case-timeline-widget">
		<!-- Status change dropdown -->
		<div v-if="!isReadOnly && orderedStatusTypes.length > 0" class="timeline-status-change">
			<NcSelect
				v-model="selectedStatus"
				:options="orderedStatusTypes"
				:input-label="t('procest', 'Change status')"
				label="name"
				track-by="id"
				:placeholder="t('procest', 'Change status...')"
				@update:model-value="onStatusSelected" />
		</div>

		<!-- Result prompt (shown when final status selected) -->
		<div v-if="showResultPrompt" class="result-prompt">
			<template v-if="resultTypes.length > 0">
				<NcSelect
					v-model="selectedResultType"
					:options="resultTypes"
					:input-label="t('procest', 'Select result type')"
					label="name"
					track-by="id"
					:placeholder="t('procest', 'Select result type...')" />
			</template>
			<template v-else>
				<NcTextField
					:model-value="resultText"
					:label="t('procest', 'Result (required)')"
					:error="!!resultError"
					@update:model-value="v => { resultText = v; resultError = '' }" />
			</template>
			<p v-if="resultError" class="form-error">
				{{ resultError }}
			</p>
			<div class="result-prompt__actions">
				<NcButton type="primary" @click="confirmStatusChange">
					{{ t('procest', 'Confirm') }}
				</NcButton>
				<NcButton @click="cancelStatusChange">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<!-- Timeline visualization -->
		<StatusTimeline
			v-if="orderedStatusTypes.length > 0"
			:status-types="orderedStatusTypes"
			:current-status-id="currentStatusId"
			:status-history="statusHistory" />

		<div v-else class="timeline-empty">
			{{ t('procest', 'No status types configured') }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import StatusTimeline from '../components/StatusTimeline.vue'

export default {
	name: 'CaseTimelineWidget',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		StatusTimeline,
	},
	props: {
		orderedStatusTypes: {
			type: Array,
			default: () => [],
		},
		currentStatusId: {
			type: String,
			default: null,
		},
		statusHistory: {
			type: Array,
			default: () => [],
		},
		resultTypes: {
			type: Array,
			default: () => [],
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['status-change', 'status-change-with-result'],
	data() {
		return {
			selectedStatus: null,
			showResultPrompt: false,
			pendingStatusChange: null,
			resultText: '',
			resultError: '',
			selectedResultType: null,
		}
	},
	methods: {
		/**
		 * @param status
		 * @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md
		 */
		onStatusSelected(status) {
			if (!status || status.id === this.currentStatusId) {
				this.selectedStatus = null
				return
			}
			if (status.isFinal === true || status.isFinal === 'true') {
				this.pendingStatusChange = status
				this.showResultPrompt = true
				this.resultText = ''
				this.resultError = ''
			} else {
				this.$emit('status-change', status)
				this.selectedStatus = null
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		confirmStatusChange() {
			let resultName = ''
			if (this.resultTypes.length > 0) {
				if (!this.selectedResultType) {
					this.resultError = t('procest', 'Please select a result type')
					return
				}
				resultName = this.selectedResultType.name
			} else {
				if (!this.resultText.trim()) {
					this.resultError = t('procest', 'Result is required when closing a case')
					return
				}
				resultName = this.resultText.trim()
			}
			this.$emit('status-change-with-result', {
				status: this.pendingStatusChange,
				resultName,
				selectedResultType: this.selectedResultType,
			})
			this.showResultPrompt = false
			this.pendingStatusChange = null
			this.resultText = ''
			this.selectedResultType = null
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		cancelStatusChange() {
			this.showResultPrompt = false
			this.pendingStatusChange = null
			this.selectedStatus = null
			this.resultText = ''
			this.resultError = ''
			this.selectedResultType = null
		},
	},
}
</script>

<style scoped>
.case-timeline-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

.timeline-status-change {
	margin-bottom: 12px;
	max-width: 280px;
}

.timeline-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px;
}

.result-prompt {
	margin-bottom: 12px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.result-prompt__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}
</style>
