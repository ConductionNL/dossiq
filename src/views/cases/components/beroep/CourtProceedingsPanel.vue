<template>
	<div class="court-proceedings-panel">
		<h4>{{ t('procest', 'Court Proceedings (Beroep)') }}</h4>

		<!-- Parent bezwaar case link -->
		<div v-if="parentCase" class="court-proceedings-panel__parent">
			<NcNoteCard type="info">
				{{ t('procest', 'This appeal originates from bezwaar case:') }}
				<NcButton type="tertiary" @click="navigateToParent">
					{{ parentCase.title || parentCase.identifier }}
				</NcButton>
			</NcNoteCard>
		</div>

		<!-- Voorlopige voorziening flag -->
		<NcNoteCard v-if="caseData.provisionRequested" type="warning">
			{{
				t(
					'procest',
					'Voorlopige voorziening (interim relief) has been requested. Expedited handling required.',
				)
			}}
		</NcNoteCard>

		<!-- Ruling outcome (if decided) -->
		<template v-if="caseData.rulingOutcome">
			<div class="court-proceedings-panel__ruling">
				<div class="ruling-detail">
					<span class="ruling-detail__label">{{
						t('procest', 'Court Ruling')
					}}</span>
					<span
						class="ruling-detail__value status-badge"
						:class="'status-badge--' + caseData.rulingOutcome">
						{{ getRulingLabel(caseData.rulingOutcome) }}
					</span>
				</div>
			</div>

			<!-- Hoger beroep information -->
			<NcNoteCard type="info">
				{{
					t(
						'procest',
						'After the court ruling, an appeal (hoger beroep) can be filed at the Council of State (ABRvS) or the Central Appeals Tribunal (CRvB).',
					)
				}}
			</NcNoteCard>
		</template>

		<!-- Recording forms -->
		<template v-if="!isReadOnly">
			<!-- Ruling outcome recording -->
			<div v-if="showRulingForm" class="form-group">
				<label>{{ t('procest', 'Court Ruling Outcome') }}</label>
				<NcSelect
					v-model="rulingForm.outcome"
					:options="rulingOptions"
					:aria-label-combobox="t('procest', 'Court Ruling Outcome')" />
				<NcButton
					type="primary"
					class="court-proceedings-panel__save-btn"
					@click="saveRuling">
					{{ t('procest', 'Record Ruling') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../../../store/modules/object.js'

export default {
	name: 'CourtProceedingsPanel',
	components: {
		NcButton,
		NcSelect,
		NcNoteCard,
	},

	props: {
		caseData: {
			type: Object,
			required: true,
		},

		parentCase: {
			type: Object,
			default: null,
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},

		showRulingForm: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['ruling-recorded'],
	data() {
		return {
			rulingForm: {
				outcome: 'beroep_ongegrond',
			},

			rulingOptions: [
				{
					id: 'beroep_gegrond',
					label: t('procest', 'Appeal upheld (beroep gegrond)'),
				},
				{
					id: 'beroep_ongegrond',
					label: t('procest', 'Appeal rejected (beroep ongegrond)'),
				},
				{ id: 'deels_gegrond', label: t('procest', 'Partially upheld') },
				{ id: 'niet_ontvankelijk', label: t('procest', 'Inadmissible') },
			],
		}
	},

	methods: {
		/**
		 * @param outcome
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		getRulingLabel(outcome) {
			const labels = {
				beroep_gegrond: t('procest', 'Appeal upheld'),
				beroep_ongegrond: t('procest', 'Appeal rejected'),
				deels_gegrond: t('procest', 'Partially upheld'),
				niet_ontvankelijk: t('procest', 'Inadmissible'),
			}
			return labels[outcome] || outcome
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		navigateToParent() {
			if (this.parentCase) {
				this.$router.push({
					name: 'CaseDetail',
					params: { id: this.parentCase.id },
				})
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async saveRuling() {
			const objectStore = useObjectStore()
			await objectStore.saveObject('case', {
				...this.caseData,
				rulingOutcome: this.rulingForm.outcome,
			})
			this.$emit('ruling-recorded', this.rulingForm.outcome)
		},
	},
}
</script>

<style scoped>
.court-proceedings-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.court-proceedings-panel__parent {
	margin-bottom: 4px;
}

.court-proceedings-panel__ruling {
	margin: 8px 0;
}

.ruling-detail {
	display: flex;
	flex-direction: column;
}

.ruling-detail__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.court-proceedings-panel__save-btn {
	align-self: flex-end;
	margin-top: 8px;
}
</style>
