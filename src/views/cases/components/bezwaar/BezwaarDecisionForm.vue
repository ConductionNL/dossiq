<template>
	<div class="bezwaar-decision-form">
		<h4>{{ t('procest', 'Decision on Objection (Beslissing op Bezwaar)') }}</h4>

		<!-- Existing decision display -->
		<template v-if="hasDecision">
			<div class="bezwaar-decision-form__details">
				<div class="decision-detail">
					<span class="decision-detail__label">{{ t('procest', 'Disposition') }}</span>
					<span class="decision-detail__value status-badge" :class="'status-badge--' + decision.dispositionType">
						{{ getDispositionLabel(decision.dispositionType) }}
					</span>
				</div>
				<div class="decision-detail">
					<span class="decision-detail__label">{{ t('procest', 'Decision Date') }}</span>
					<span class="decision-detail__value">{{ decision.decisionDate }}</span>
				</div>
				<div v-if="decision.followsAdvice !== null" class="decision-detail">
					<span class="decision-detail__label">{{ t('procest', 'Follows advice') }}</span>
					<span class="decision-detail__value">{{ decision.followsAdvice ? t('procest', 'Yes') : t('procest', 'No') }}</span>
				</div>
			</div>

			<div class="bezwaar-decision-form__content">
				<h5>{{ t('procest', 'Motivation') }}</h5>
				<p>{{ decision.dispositionDetails }}</p>

				<template v-if="decision.remedialAction">
					<h5>{{ t('procest', 'Remedial Action') }}</h5>
					<p>{{ decision.remedialAction }}</p>
				</template>

				<h5>{{ t('procest', 'Appeal Information (Rechtsmiddelenclausule)') }}</h5>
				<p>{{ decision.appealInformation }}</p>
			</div>
		</template>

		<!-- Create decision form -->
		<template v-else-if="!isReadOnly">
			<!-- Reformatio in peius warning -->
			<NcNoteCard type="warning">
				{{ t('procest', 'Note: the reconsideration (heroverweging) must be complete (ex nunc). The objection may not lead to a worse outcome for the objector (reformatio in peius).') }}
			</NcNoteCard>

			<div class="form-group">
				<label>{{ t('procest', 'Disposition Type') }} *</label>
				<NcSelect
					v-model="form.dispositionType"
					:options="dispositionOptions"
					:aria-label-combobox="t('procest', 'Disposition Type')" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Motivation (Motivering)') }} *</label>
				<textarea
					v-model="form.dispositionDetails"
					:placeholder="t('procest', 'Detailed motivation for the decision (art. 7:12 Awb)...')"
					rows="5" />
				<p v-if="errors.dispositionDetails" class="form-error">
					{{ errors.dispositionDetails }}
				</p>
			</div>

			<!-- Follows advice toggle (if advisory report exists) -->
			<template v-if="hasAdvisoryReport">
				<div class="form-group">
					<NcCheckboxRadioSwitch
						:checked="form.followsAdvice"
						@update:checked="v => form.followsAdvice = v">
						{{ t('procest', 'Decision follows committee advice') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="!form.followsAdvice" class="form-group">
					<label>{{ t('procest', 'Reason for deviating from advice') }} *</label>
					<textarea
						v-model="form.deviationReason"
						:placeholder="t('procest', 'Per art. 7:13 lid 7, explain why the decision deviates...')"
						rows="3" />
					<p v-if="errors.deviationReason" class="form-error">
						{{ errors.deviationReason }}
					</p>
				</div>
			</template>

			<!-- Remedial action (for gegrond / deels_gegrond) -->
			<div v-if="form.dispositionType === 'gegrond' || form.dispositionType === 'deels_gegrond'" class="form-group">
				<label>{{ t('procest', 'Remedial Action') }}</label>
				<textarea
					v-model="form.remedialAction"
					:placeholder="t('procest', 'What corrective action will be taken...')"
					rows="3" />
			</div>

			<div class="form-row">
				<div class="form-group">
					<label>{{ t('procest', 'Decision Date') }} *</label>
					<NcTextField
						:value="form.decisionDate"
						type="date"
						@update:value="v => form.decisionDate = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Effective Date') }} *</label>
					<NcTextField
						:value="form.effectiveDate"
						type="date"
						@update:value="v => form.effectiveDate = v" />
				</div>
			</div>

			<!-- Rechtsmiddelenclausule -->
			<div class="form-group">
				<label>{{ t('procest', 'Appeal Information (Rechtsmiddelenclausule)') }} *</label>
				<textarea
					v-model="form.appealInformation"
					:placeholder="defaultAppealInformation"
					rows="3" />
				<p v-if="errors.appealInformation" class="form-error">
					{{ errors.appealInformation }}
				</p>
				<NcNoteCard v-if="!form.appealInformation" type="warning">
					{{ t('procest', 'Rechtsmiddelenclausule is required: inform the objector about appeal options.') }}
				</NcNoteCard>
			</div>

			<div class="bezwaar-decision-form__actions">
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('procest', 'Saving...') : t('procest', 'Record Decision') }}
				</NcButton>
			</div>
		</template>

		<template v-else>
			<p class="section-empty">
				{{ t('procest', 'No decision has been recorded yet.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'BezwaarDecisionForm',
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
		contestedDecisionId: {
			type: String,
			default: '',
		},
	},
	emits: ['saved'],
	data() {
		return {
			form: {
				dispositionType: 'ongegrond',
				dispositionDetails: '',
				followsAdvice: true,
				deviationReason: '',
				remedialAction: '',
				decisionDate: new Date().toISOString().split('T')[0],
				effectiveDate: new Date().toISOString().split('T')[0],
				appealInformation: '',
			},
			errors: {},
			saving: false,
			dispositionOptions: [
				{ id: 'gegrond', label: t('procest', 'Upheld (gegrond)') },
				{ id: 'ongegrond', label: t('procest', 'Rejected (ongegrond)') },
				{ id: 'deels_gegrond', label: t('procest', 'Partially upheld (deels gegrond)') },
				{ id: 'niet_ontvankelijk', label: t('procest', 'Inadmissible (niet-ontvankelijk)') },
			],
			defaultAppealInformation: t(
				'procest',
				'Tegen deze beslissing op bezwaar kunt u binnen zes weken na de dag van verzending van deze beslissing beroep instellen bij de rechtbank.',
			),
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		hasDecision() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.hasAppealDecision
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		decision() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.currentAppealDecision
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		hasAdvisoryReport() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.hasAdvisoryReport
		},
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		getDispositionLabel(type) {
			const labels = {
				gegrond: t('procest', 'Upheld'),
				ongegrond: t('procest', 'Rejected'),
				deels_gegrond: t('procest', 'Partially upheld'),
				niet_ontvankelijk: t('procest', 'Inadmissible'),
			}
			return labels[type] || type
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		validate() {
			this.errors = {}

			if (!this.form.dispositionDetails) {
				this.errors.dispositionDetails = t('procest', 'Motivation is required (art. 7:12 Awb)')
			}
			if (!this.form.appealInformation) {
				this.errors.appealInformation = t('procest', 'Rechtsmiddelenclausule is required')
			}
			if (this.hasAdvisoryReport && !this.form.followsAdvice && !this.form.deviationReason) {
				this.errors.deviationReason = t('procest', 'Reason for deviating from advice is required (art. 7:13 lid 7)')
			}

			return Object.keys(this.errors).length === 0
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async save() {
			if (!this.validate()) return

			this.saving = true
			const bezwaarStore = useBezwaarStore()

			await bezwaarStore.createAppealDecision({
				case: this.caseId,
				contestedDecision: this.contestedDecisionId,
				advisoryReport: bezwaarStore.currentAdvisoryReport?.id || '',
				decisionMaker: '',
				...this.form,
			})

			this.saving = false
			this.$emit('saved')
		},
	},
}
</script>

<style scoped>
.bezwaar-decision-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.bezwaar-decision-form__details {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 12px;
}
.decision-detail {
	display: flex;
	flex-direction: column;
}
.decision-detail__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
.bezwaar-decision-form__content {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 12px;
}
.bezwaar-decision-form__actions {
	display: flex;
	justify-content: flex-end;
}
.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}
.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin: 0;
}
textarea {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	resize: vertical;
}
</style>
