<template>
	<div class="advisory-report-panel">
		<h4>{{ t('procest', 'Advisory Committee Report') }}</h4>

		<!-- Existing report display -->
		<template v-if="hasReport">
			<div class="advisory-report-panel__details">
				<div class="report-detail">
					<span class="report-detail__label">{{
						t('procest', 'Advice Type')
					}}</span>
					<span
						class="report-detail__value status-badge"
						:class="'status-badge--' + report.adviceType">
						{{ getAdviceTypeLabel(report.adviceType) }}
					</span>
				</div>
				<div class="report-detail">
					<span class="report-detail__label">{{
						t('procest', 'Date')
					}}</span>
					<span class="report-detail__value">{{ report.adviceDate }}</span>
				</div>
				<div class="report-detail">
					<span class="report-detail__label">{{
						t('procest', 'Deviates from original')
					}}</span>
					<span class="report-detail__value">{{
						report.deviationFromPrimaryDecision
							? t('procest', 'Yes')
							: t('procest', 'No')
					}}</span>
				</div>
			</div>

			<div class="advisory-report-panel__content">
				<h5>{{ t('procest', 'Summary') }}</h5>
				<p>{{ report.summary }}</p>

				<h5>{{ t('procest', 'Grounds') }}</h5>
				<p>{{ report.grounds }}</p>

				<h5>{{ t('procest', 'Recommendation') }}</h5>
				<p>{{ report.recommendation }}</p>
			</div>
		</template>

		<!-- Create report form -->
		<template v-else-if="!isReadOnly">
			<div class="form-group">
				<label>{{ t('procest', 'Advice Type') }} *</label>
				<NcSelect
					v-model="form.adviceType"
					:options="adviceTypeOptions"
					:aria-label-combobox="t('procest', 'Advice Type')" />
			</div>

			<div class="form-group">
				<label for="advisory-report-advice-date"
					>{{ t('procest', 'Date') }} *</label
				>
				<NcTextField
					id="advisory-report-advice-date"
					:modelValue="form.adviceDate"
					type="date"
					@update:modelValue="(v) => (form.adviceDate = v)" />
			</div>

			<div class="form-group">
				<label for="advisory-report-summary"
					>{{ t('procest', 'Summary') }} *</label
				>
				<textarea
					id="advisory-report-summary"
					v-model="form.summary"
					:placeholder="t('procest', 'Summary of the committee advice...')"
					rows="3" />
			</div>

			<div class="form-group">
				<label for="advisory-report-grounds"
					>{{ t('procest', 'Legal Grounds') }} *</label
				>
				<textarea
					id="advisory-report-grounds"
					v-model="form.grounds"
					:placeholder="t('procest', 'Legal reasoning and grounds...')"
					rows="4" />
			</div>

			<div class="form-group">
				<label for="advisory-report-recommendation"
					>{{ t('procest', 'Recommendation') }} *</label
				>
				<textarea
					id="advisory-report-recommendation"
					v-model="form.recommendation"
					:placeholder="
						t('procest', 'Recommended action for the beslisser...')
					"
					rows="3" />
			</div>

			<div class="form-group">
				<NcCheckboxRadioSwitch
					:modelValue="form.deviationFromPrimaryDecision"
					@update:modelValue="
						(v) => (form.deviationFromPrimaryDecision = v)
					">
					{{
						t(
							'procest',
							'Committee advises differently from original decision',
						)
					}}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Committee composition warning -->
			<NcNoteCard v-if="showCompositionWarning" type="warning">
				{{
					t(
						'procest',
						'Best practice: the committee should have at least 3 members (voorzitter + 2 leden).',
					)
				}}
			</NcNoteCard>

			<!-- Conflict of interest warning -->
			<NcNoteCard v-if="hasConflictOfInterest" type="warning">
				{{
					t(
						'procest',
						'Warning: A committee member was involved in the original decision.',
					)
				}}
			</NcNoteCard>

			<div class="advisory-report-panel__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{
						saving
							? t('procest', 'Saving...')
							: t('procest', 'Save Advisory Report')
					}}
				</NcButton>
			</div>
		</template>

		<template v-else>
			<p class="section-empty">
				{{ t('procest', 'No advisory report has been created yet.') }}
			</p>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'AdvisoryReportPanel',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},

		committeeMembers: {
			type: Array,
			default: () => [],
		},

		primaryDecisionMaker: {
			type: String,
			default: '',
		},
	},

	emits: ['saved'],
	data() {
		return {
			form: {
				adviceType: 'ongegrond',
				adviceDate: new Date().toISOString().split('T')[0],
				summary: '',
				grounds: '',
				recommendation: '',
				deviationFromPrimaryDecision: false,
			},

			saving: false,
			adviceTypeOptions: [
				{ id: 'gegrond', label: t('procest', 'Upheld (gegrond)') },
				{ id: 'ongegrond', label: t('procest', 'Rejected (ongegrond)') },
				{
					id: 'deels_gegrond',
					label: t('procest', 'Partially upheld (deels gegrond)'),
				},
				{
					id: 'niet_ontvankelijk',
					label: t('procest', 'Inadmissible (niet-ontvankelijk)'),
				},
			],
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		hasReport() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.hasAdvisoryReport
		},

		/**
		 * The BAC advisory report read for display.
		 *
		 * The committee advice is *decided* in decidesk as an `advice` Decision
		 * (procest-delegate-remaining-decisions-to-decidesk, REQ-PDRD-001); the
		 * advice fields surfaced here (adviceType / summary / grounds /
		 * recommendation / deviation) are a projection of the decidesk advice
		 * outcome consumed by AdvisoryCommitteeService. The create-form Awb
		 * fields below remain procest input that feeds the raised Decision.
		 *
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 * @spec openspec/specs/remaining-decision-delegation/spec.md
		 */
		report() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.currentAdvisoryReport
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		showCompositionWarning() {
			return this.committeeMembers.length < 3
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		hasConflictOfInterest() {
			if (!this.primaryDecisionMaker) return false
			return this.committeeMembers.some(
				(m) => m.id === this.primaryDecisionMaker,
			)
		},
	},

	methods: {
		/**
		 * @param type
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		getAdviceTypeLabel(type) {
			const labels = {
				gegrond: t('procest', 'Upheld'),
				ongegrond: t('procest', 'Rejected'),
				deels_gegrond: t('procest', 'Partially upheld'),
				niet_ontvankelijk: t('procest', 'Inadmissible'),
			}
			return labels[type] || type
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async save() {
			this.saving = true
			const bezwaarStore = useBezwaarStore()

			await bezwaarStore.createAdvisoryReport({
				case: this.caseId,
				committeeChair: '',
				committeeMembers: JSON.stringify(
					this.committeeMembers.map((m) => m.id),
				),
				...this.form,
			})

			this.saving = false
			this.$emit('saved')
		},
	},
}
</script>

<style scoped>
.advisory-report-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.advisory-report-panel__details {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 12px;
}

.report-detail {
	display: flex;
	flex-direction: column;
}

.report-detail__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.advisory-report-panel__content {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.advisory-report-panel__content h5 {
	margin-top: 12px;
}

.advisory-report-panel__content h5:first-child {
	margin-top: 0;
}

.advisory-report-panel__actions {
	display: flex;
	justify-content: flex-end;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

textarea {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	resize: vertical;
}
</style>
