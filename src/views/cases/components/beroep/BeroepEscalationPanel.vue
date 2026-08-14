<template>
	<div class="beroep-escalation-panel">
		<h4>{{ t('procest', 'Appeal to Court (Beroep)') }}</h4>

		<!-- Already escalated -->
		<template v-if="linkedBeroepCase">
			<NcNoteCard type="info">
				{{ t('procest', 'This case has been escalated to an appeal (beroep) case.') }}
			</NcNoteCard>
			<NcButton @click="navigateToBeroep">
				{{ t('procest', 'Go to appeal case') }}
			</NcButton>
		</template>

		<!-- Escalation available -->
		<template v-else-if="canEscalate && !isReadOnly">
			<p>{{ t('procest', 'If the objector disagrees with the decision, they can file an appeal (beroep) at the administrative court within 6 weeks.') }}</p>

			<div class="form-group">
				<NcCheckboxRadioSwitch
					:model-value="provisionRequested"
					@update:model-value="v => voorzieningRequested = v">
					{{ t('procest', 'Voorlopige voorziening (interim relief) requested') }}
				</NcCheckboxRadioSwitch>
			</div>

			<NcNoteCard v-if="provisionRequested" type="warning">
				{{ t('procest', 'Urgent: the appellant has also requested interim relief. This may require expedited handling.') }}
			</NcNoteCard>

			<div class="beroep-escalation-panel__actions">
				<NcButton type="primary" :disabled="escalating" @click="escalate">
					{{ escalating ? t('procest', 'Creating...') : t('procest', 'Create Appeal Case') }}
				</NcButton>
			</div>
		</template>

		<template v-else>
			<p class="section-empty">
				{{ t('procest', 'Escalation to appeal is available after the decision on objection.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'BeroepEscalationPanel',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},
	props: {
		caseData: {
			type: Object,
			required: true,
		},
		canEscalate: {
			type: Boolean,
			default: false,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
		linkedBeroepCase: {
			type: Object,
			default: null,
		},
	},
	emits: ['escalated'],
	data() {
		return {
			provisionRequested: false,
			escalating: false,
		}
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async escalate() {
			this.escalating = true
			const bezwaarStore = useBezwaarStore()

			const beroepCase = await bezwaarStore.escalateToBeroep(this.caseData, {
				provisionRequested: this.provisionRequested,
			})

			this.escalating = false

			if (beroepCase) {
				this.$emit('escalated', beroepCase)
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		navigateToBeroep() {
			if (this.linkedBeroepCase) {
				this.$router.push({
					name: 'CaseDetail',
					params: { id: this.linkedBeroepCase.id },
				})
			}
		},
	},
}
</script>

<style scoped>
.beroep-escalation-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.beroep-escalation-panel__actions {
	display: flex;
	justify-content: flex-end;
}

.form-group {
	margin: 8px 0;
}
</style>
