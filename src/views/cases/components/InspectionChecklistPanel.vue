<template>
	<div class="inspection-checklist">
		<h4 class="inspection-checklist__title">
			{{ t('procest', 'Inspection Checklist') }}
		</h4>

		<!-- Checklist selector -->
		<div v-if="!activeChecklist" class="inspection-checklist__select">
			<NcSelect
				v-model="selectedChecklist"
				:options="checklists"
				label="title"
				track-by="id"
				:placeholder="t('procest', 'Select checklist...')" />
			<NcButton
				type="primary"
				:disabled="!selectedChecklist"
				@click="startInspection">
				{{ t('procest', 'Start Inspection') }}
			</NcButton>
		</div>

		<!-- Active checklist -->
		<div v-else class="inspection-checklist__form">
			<h5>{{ activeChecklist.title }}</h5>

			<div
				v-for="(item, index) in activeChecklist.items"
				:key="index"
				class="inspection-checklist__item">
				<div class="inspection-checklist__item-header">
					<span class="inspection-checklist__item-number">{{ index + 1 }}.</span>
					<span class="inspection-checklist__item-label">{{ item.label }}</span>
					<span v-if="item.photoRequired" class="inspection-checklist__photo-badge">
						{{ t('procest', 'Photo required') }}
					</span>
				</div>

				<div class="inspection-checklist__item-controls">
					<div class="inspection-checklist__radio-group">
						<label>
							<input
								type="radio"
								:name="'item-' + index"
								value="ja"
								:checked="itemResults[index] && itemResults[index].result === 'ja'"
								@change="setResult(index, 'ja')">
							{{ t('procest', 'Yes') }}
						</label>
						<label>
							<input
								type="radio"
								:name="'item-' + index"
								value="nee"
								:checked="itemResults[index] && itemResults[index].result === 'nee'"
								@change="setResult(index, 'nee')">
							{{ t('procest', 'No') }}
						</label>
						<label>
							<input
								type="radio"
								:name="'item-' + index"
								value="nvt"
								:checked="itemResults[index] && itemResults[index].result === 'nvt'"
								@change="setResult(index, 'nvt')">
							{{ t('procest', 'N/A') }}
						</label>
					</div>

					<NcTextField
						:value="(itemResults[index] && itemResults[index].comment) || ''"
						:placeholder="t('procest', 'Notes...')"
						@update:value="v => setComment(index, v)" />
				</div>
			</div>

			<!-- Summary -->
			<div class="inspection-checklist__summary">
				<span :class="overallResult === 'conform' ? 'result--pass' : 'result--fail'">
					{{ overallResult === 'conform'
						? t('procest', 'Conform')
						: t('procest', 'Niet-conform ({count} failed)', { count: failedCount }) }}
				</span>
			</div>

			<div class="inspection-checklist__actions">
				<NcButton @click="activeChecklist = null">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!allItemsAnswered || saving"
					@click="submitInspection">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Submit Inspection') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'InspectionChecklistPanel',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		checklists: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			selectedChecklist: null,
			activeChecklist: null,
			itemResults: {},
			saving: false,
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		allItemsAnswered() {
			if (!this.activeChecklist) return false
			return this.activeChecklist.items.every(
				(item, idx) => this.itemResults[idx] && this.itemResults[idx].result,
			)
		},
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		failedCount() {
			return Object.values(this.itemResults).filter(r => r.result === 'nee').length
		},
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		overallResult() {
			return this.failedCount > 0 ? 'niet_conform' : 'conform'
		},
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		startInspection() {
			this.activeChecklist = { ...this.selectedChecklist }
			this.itemResults = {}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		setResult(index, value) {
			this.$set(this.itemResults, index, {
				...(this.itemResults[index] || {}),
				result: value,
			})
		},
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		setComment(index, value) {
			this.$set(this.itemResults, index, {
				...(this.itemResults[index] || {}),
				comment: value,
			})
		},
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		async submitInspection() {
			this.saving = true
			try {
				this.$emit('submit', {
					caseId: this.caseId,
					checklistId: this.activeChecklist.id,
					results: this.itemResults,
					outcome: this.overallResult,
				})
				this.activeChecklist = null
				this.itemResults = {}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.inspection-checklist__title {
	margin-bottom: 12px;
}

.inspection-checklist__select {
	display: flex;
	gap: 12px;
	align-items: flex-end;
}

.inspection-checklist__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.inspection-checklist__item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.inspection-checklist__item-number {
	font-weight: 600;
	min-width: 24px;
}

.inspection-checklist__item-label {
	flex: 1;
}

.inspection-checklist__photo-badge {
	font-size: 0.75rem;
	background: var(--color-warning-light, #fff3e0);
	color: var(--color-warning, #e65100);
	padding: 2px 6px;
	border-radius: var(--border-radius);
}

.inspection-checklist__item-controls {
	display: flex;
	gap: 16px;
	align-items: center;
}

.inspection-checklist__radio-group {
	display: flex;
	gap: 12px;
}

.inspection-checklist__radio-group label {
	display: flex;
	align-items: center;
	gap: 4px;
	cursor: pointer;
}

.inspection-checklist__summary {
	padding: 12px 0;
	font-weight: 600;
}

.result--pass {
	color: var(--color-success, #2e7d32);
}

.result--fail {
	color: var(--color-error, #c62828);
}

.inspection-checklist__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>
