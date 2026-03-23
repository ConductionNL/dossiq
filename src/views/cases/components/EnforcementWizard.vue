<template>
	<div class="enforcement-wizard-overlay" @click.self="$emit('close')">
		<div class="enforcement-wizard">
			<h3>{{ t('procest', 'Start Enforcement Action') }}</h3>

			<!-- Step indicators -->
			<div class="enforcement-wizard__steps">
				<span
					v-for="s in 3"
					:key="s"
					class="enforcement-wizard__step-indicator"
					:class="{ 'enforcement-wizard__step-indicator--active': step === s, 'enforcement-wizard__step-indicator--done': step > s }">
					{{ s }}
				</span>
			</div>

			<!-- Step 1: Classification -->
			<div v-if="step === 1" class="enforcement-wizard__content">
				<h4>{{ t('procest', 'Step 1: Classification') }}</h4>
				<p>{{ t('procest', 'Classify the violation using the LHS matrix (severity x behavior).') }}</p>

				<div class="enforcement-wizard__field">
					<label>{{ t('procest', 'Severity (ernst)') }}</label>
					<div class="enforcement-wizard__radio-group">
						<label v-for="e in ernstOptions" :key="e.value">
							<input v-model="ernst" type="radio" :value="e.value">
							<span>{{ e.label }}</span>
						</label>
					</div>
				</div>

				<div class="enforcement-wizard__field">
					<label>{{ t('procest', 'Behavior (gedrag)') }}</label>
					<div class="enforcement-wizard__radio-group">
						<label v-for="g in gedragOptions" :key="g.value">
							<input v-model="gedrag" type="radio" :value="g.value">
							<span>{{ g.label }}</span>
						</label>
					</div>
				</div>

				<div v-if="suggestedIntervention" class="enforcement-wizard__suggestion">
					<strong>{{ t('procest', 'Suggested intervention:') }}</strong>
					{{ suggestedIntervention }}
				</div>
			</div>

			<!-- Step 2: Intervention details -->
			<div v-if="step === 2" class="enforcement-wizard__content">
				<h4>{{ t('procest', 'Step 2: Intervention Details') }}</h4>

				<div class="enforcement-wizard__field">
					<label>{{ t('procest', 'Intervention type') }}</label>
					<input v-model="interventie" type="text" class="enforcement-wizard__input">
				</div>

				<div v-if="isDwangsom" class="enforcement-wizard__dwangsom-fields">
					<div class="enforcement-wizard__field">
						<label>{{ t('procest', 'Penalty per violation (EUR)') }}</label>
						<input v-model.number="dwangsomBedrag" type="number" class="enforcement-wizard__input" min="0">
					</div>
					<div class="enforcement-wizard__field">
						<label>{{ t('procest', 'Maximum penalty (EUR)') }}</label>
						<input v-model.number="dwangsomMaximaal" type="number" class="enforcement-wizard__input" min="0">
					</div>
					<div class="enforcement-wizard__field">
						<label>{{ t('procest', 'Grace period (days)') }}</label>
						<input v-model.number="begunstigingstermijn" type="number" class="enforcement-wizard__input" min="1">
					</div>
				</div>

				<div v-if="isBestuursdwang" class="enforcement-wizard__bestuursdwang-fields">
					<div class="enforcement-wizard__field">
						<label>{{ t('procest', 'Execution date') }}</label>
						<input v-model="effectueringsDatum" type="date" class="enforcement-wizard__input">
					</div>
				</div>

				<div v-if="overrideReason || interventie !== suggestedIntervention" class="enforcement-wizard__field">
					<label>{{ t('procest', 'Override reason (required if different from suggestion)') }}</label>
					<textarea v-model="overrideReason" class="enforcement-wizard__textarea" rows="2" />
				</div>
			</div>

			<!-- Step 3: Vooraankondiging -->
			<div v-if="step === 3" class="enforcement-wizard__content">
				<h4>{{ t('procest', 'Step 3: Vooraankondiging') }}</h4>
				<p>{{ t('procest', 'A vooraankondiging letter will be generated and a zienswijze period will be set.') }}</p>

				<div class="enforcement-wizard__field">
					<label>{{ t('procest', 'Zienswijze period (days)') }}</label>
					<input v-model.number="zienswijzetermijn" type="number" class="enforcement-wizard__input" min="1">
				</div>

				<div class="enforcement-wizard__summary">
					<h5>{{ t('procest', 'Summary') }}</h5>
					<p><strong>{{ t('procest', 'Classification:') }}</strong> {{ ernst }} / {{ gedrag }}</p>
					<p><strong>{{ t('procest', 'Intervention:') }}</strong> {{ interventie }}</p>
					<p v-if="isDwangsom">
						<strong>{{ t('procest', 'Penalty:') }}</strong>
						EUR {{ dwangsomBedrag }} {{ t('procest', 'per violation, max') }} EUR {{ dwangsomMaximaal }}
					</p>
					<p v-if="begunstigingstermijn">
						<strong>{{ t('procest', 'Grace period:') }}</strong> {{ begunstigingstermijn }} {{ t('procest', 'days') }}
					</p>
				</div>
			</div>

			<!-- Actions -->
			<div class="enforcement-wizard__actions">
				<NcButton v-if="step > 1" @click="step--">
					{{ t('procest', 'Previous') }}
				</NcButton>
				<NcButton v-if="step < 3" type="primary" :disabled="!canProceed" @click="step++">
					{{ t('procest', 'Next') }}
				</NcButton>
				<NcButton v-if="step === 3" type="primary" :disabled="submitting" @click="submit">
					{{ submitting ? t('procest', 'Creating...') : t('procest', 'Create enforcement action') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useEnforcementStore } from '../../../store/modules/enforcement.js'

export default {
	name: 'EnforcementWizard',

	components: {
		NcButton,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			step: 1,
			ernst: null,
			gedrag: null,
			interventie: '',
			dwangsomBedrag: 5000,
			dwangsomMaximaal: 25000,
			begunstigingstermijn: 42,
			effectueringsDatum: '',
			overrideReason: '',
			zienswijzetermijn: 14,
			submitting: false,
		}
	},

	computed: {
		enforcementStore() {
			return useEnforcementStore()
		},

		ernstOptions() {
			return [
				{ value: 'gering', label: t('procest', 'Minor (gering)') },
				{ value: 'aanzienlijk', label: t('procest', 'Significant (aanzienlijk)') },
				{ value: 'ernstig', label: t('procest', 'Serious (ernstig)') },
			]
		},

		gedragOptions() {
			return [
				{ value: 'goedwillend', label: t('procest', 'Cooperative (goedwillend)') },
				{ value: 'onverschillig', label: t('procest', 'Indifferent (onverschillig)') },
				{ value: 'calculerend', label: t('procest', 'Calculating (calculerend)') },
				{ value: 'crimineel', label: t('procest', 'Criminal (crimineel)') },
			]
		},

		suggestedIntervention() {
			if (!this.ernst || !this.gedrag) {
				return null
			}
			return this.enforcementStore.lookupLhs(this.ernst, this.gedrag)
		},

		isDwangsom() {
			return this.interventie?.toLowerCase().includes('dwangsom')
		},

		isBestuursdwang() {
			return this.interventie?.toLowerCase().includes('bestuursdwang')
		},

		canProceed() {
			if (this.step === 1) {
				return this.ernst && this.gedrag
			}
			if (this.step === 2) {
				return this.interventie
			}
			return true
		},
	},

	watch: {
		suggestedIntervention(val) {
			if (val && !this.interventie) {
				this.interventie = val
			}
		},
	},

	async mounted() {
		await this.enforcementStore.loadLhsMatrix()
	},

	methods: {
		t,

		async submit() {
			this.submitting = true
			try {
				const action = await this.enforcementStore.createAction({
					case: this.caseId,
					type: this.mapInterventionToType(this.interventie),
					ernst: this.ernst,
					gedrag: this.gedrag,
					interventie: this.interventie,
					dwangsomBedrag: this.isDwangsom ? this.dwangsomBedrag : null,
					dwangsomMaximaal: this.isDwangsom ? this.dwangsomMaximaal : null,
					begunstigingstermijn: this.begunstigingstermijn || null,
					effectueringsDatum: this.isBestuursdwang ? this.effectueringsDatum : null,
					overrideReason: this.interventie !== this.suggestedIntervention ? this.overrideReason : null,
				})
				this.$emit('created', action)
				this.$emit('close')
			} finally {
				this.submitting = false
			}
		},

		mapInterventionToType(intervention) {
			const lower = (intervention || '').toLowerCase()
			if (lower.includes('bestuursdwang')) {
				return 'bestuursdwang'
			}
			if (lower.includes('dwangsom')) {
				return 'last_onder_dwangsom'
			}
			if (lower.includes('pv') || lower.includes('proces')) {
				return 'proces_verbaal'
			}
			if (lower.includes('vooraankondiging')) {
				return 'vooraankondiging'
			}
			return 'waarschuwing'
		},
	},
}
</script>

<style scoped>
.enforcement-wizard-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 1000;
}

.enforcement-wizard {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 600px;
	width: 90%;
	max-height: 80vh;
	overflow-y: auto;
}

.enforcement-wizard__steps {
	display: flex;
	justify-content: center;
	gap: 12px;
	margin-bottom: 20px;
}

.enforcement-wizard__step-indicator {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 2px solid var(--color-border);
	font-weight: bold;
}

.enforcement-wizard__step-indicator--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: white;
}

.enforcement-wizard__step-indicator--done {
	border-color: var(--color-success);
	background: var(--color-success);
	color: white;
}

.enforcement-wizard__field {
	margin-bottom: 14px;
}

.enforcement-wizard__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.enforcement-wizard__radio-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.enforcement-wizard__radio-group label {
	font-weight: normal;
	display: flex;
	align-items: center;
	gap: 6px;
}

.enforcement-wizard__input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.enforcement-wizard__textarea {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.enforcement-wizard__suggestion {
	padding: 12px;
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	margin-top: 12px;
}

.enforcement-wizard__summary {
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	margin-top: 12px;
}

.enforcement-wizard__actions {
	display: flex;
	gap: 8px;
	margin-top: 20px;
	justify-content: flex-end;
}
</style>
