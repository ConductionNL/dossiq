<template>
	<div class="bezwaar-intake-form">
		<h4>{{ t('procest', 'Objection Details') }}</h4>

		<!-- Contested Decision selector -->
		<div class="form-group">
			<label for="bezwaar-intake-contested-decision"
				>{{
					t('procest', 'Contested Decision (Bestreden Besluit)')
				}}
				*</label
			>
			<NcTextField
				id="bezwaar-intake-contested-decision"
				:model-value="form.contestedDecision"
				:disabled="isReadOnly"
				:placeholder="t('procest', 'UUID of the contested decision')"
				:error="!!errors.contestedDecision"
				@update:model-value="
					(v) => {
						form.contestedDecision = v
						errors.contestedDecision = ''
					}
				" />
			<p v-if="errors.contestedDecision" class="form-error">
				{{ errors.contestedDecision }}
			</p>
		</div>

		<!-- Grounds -->
		<div class="form-group">
			<label for="bezwaar-intake-grounds"
				>{{
					t('procest', 'Grounds for Objection (Gronden van Bezwaar)')
				}}
				*</label
			>
			<textarea
				id="bezwaar-intake-grounds"
				v-model="form.grounds"
				:disabled="isReadOnly"
				:placeholder="t('procest', 'Describe the grounds for objection...')"
				rows="4" />
			<p v-if="errors.grounds" class="form-error">
				{{ errors.grounds }}
			</p>
		</div>

		<!-- Requested Relief -->
		<div class="form-group">
			<label for="bezwaar-intake-requested-relief">{{
				t('procest', 'Requested Outcome')
			}}</label>
			<textarea
				id="bezwaar-intake-requested-relief"
				v-model="form.requestedRelief"
				:disabled="isReadOnly"
				:placeholder="t('procest', 'What outcome does the objector seek?')"
				rows="2" />
		</div>

		<div class="form-row">
			<!-- Received Date -->
			<div class="form-group">
				<label for="bezwaar-intake-received-date"
					>{{ t('procest', 'Date Received') }} *</label
				>
				<NcTextField
					id="bezwaar-intake-received-date"
					:model-value="form.receivedDate"
					:disabled="isReadOnly"
					type="date"
					:error="!!errors.receivedDate"
					@update:model-value="
						(v) => {
							form.receivedDate = v
							errors.receivedDate = ''
							checkTimeliness()
						}
					" />
				<p v-if="errors.receivedDate" class="form-error">
					{{ errors.receivedDate }}
				</p>
			</div>

			<!-- Received Channel -->
			<div class="form-group">
				<label>{{ t('procest', 'Received Via') }} *</label>
				<NcSelect
					v-model="form.receivedChannel"
					:options="channelOptions"
					:aria-label-combobox="t('procest', 'Received Via')"
					:disabled="isReadOnly" />
			</div>
		</div>

		<!-- Timeliness Warning -->
		<div
			v-if="timelinessResult && !timelinessResult.isTimely"
			class="bezwaar-intake-form__warning">
			<NcNoteCard type="warning">
				{{ timelinessResult.message }}
			</NcNoteCard>
		</div>

		<div
			v-if="timelinessResult && timelinessResult.isTimely"
			class="bezwaar-intake-form__info">
			<NcNoteCard type="success">
				{{ timelinessResult.message }}
			</NcNoteCard>
		</div>

		<!-- Timeliness Assessment (when late) -->
		<div
			v-if="timelinessResult && !timelinessResult.isTimely"
			class="form-group">
			<label for="bezwaar-intake-timeliness-assessment">{{
				t('procest', 'Timeliness Assessment')
			}}</label>
			<textarea
				id="bezwaar-intake-timeliness-assessment"
				v-model="form.timelinessAssessment"
				:disabled="isReadOnly"
				:placeholder="
					t('procest', 'E.g. verschoonbare termijnoverschrijding...')
				"
				rows="2" />
		</div>

		<div class="form-row">
			<!-- Voorlopige Voorziening -->
			<div class="form-group">
				<NcCheckboxRadioSwitch
					:model-value="form.proProvision"
					:disabled="isReadOnly"
					@update:model-value="(v) => (form.proProvision = v)">
					{{
						t(
							'procest',
							'Interim relief (voorlopige voorziening) requested',
						)
					}}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Deadline info -->
		<div v-if="deadlines" class="bezwaar-intake-form__deadlines">
			<h5>{{ t('procest', 'Calculated Deadlines') }}</h5>
			<div class="deadline-grid">
				<div class="deadline-item">
					<span class="deadline-label">{{
						t('procest', 'Acknowledgment')
					}}</span>
					<span class="deadline-value">{{
						deadlines.acknowledgementOfReceiptDeadline
					}}</span>
				</div>
				<div class="deadline-item">
					<span class="deadline-label">{{
						t('procest', 'Processing')
					}}</span>
					<span class="deadline-value">{{
						deadlines.afhandelDeadline
					}}</span>
				</div>
				<div class="deadline-item">
					<span class="deadline-label">{{
						t('procest', 'Max with extension')
					}}</span>
					<span class="deadline-value">{{
						deadlines.maxDeadlineWithExtension
					}}</span>
				</div>
			</div>
		</div>

		<!-- Actions -->
		<div v-if="!isReadOnly" class="bezwaar-intake-form__actions">
			<NcButton type="primary" :disabled="saving" @click="save">
				{{
					saving
						? t('procest', 'Saving...')
						: t('procest', 'Save Objection')
				}}
			</NcButton>
		</div>
	</div>
</template>

<script>
import {
	NcButton,
	NcTextField,
	NcSelect,
	NcCheckboxRadioSwitch,
	NcNoteCard,
} from '@nextcloud/vue'
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'BezwaarIntakeForm',
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
		caseData: {
			type: Object,
			default: () => ({}),
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
		besluitDate: {
			type: String,
			default: '',
		},
	},
	emits: ['saved', 'deadlines-calculated'],
	data() {
		return {
			form: {
				contestedDecision: '',
				grounds: '',
				requestedRelief: '',
				receivedDate: '',
				receivedChannel: 'brief',
				isTimely: null,
				timelinessAssessment: '',
				proProvision: false,
			},
			errors: {},
			saving: false,
			timelinessResult: null,
			deadlines: null,
			channelOptions: [
				{ id: 'brief', label: t('procest', 'Letter (brief)') },
				{ id: 'email', label: t('procest', 'Email') },
				{ id: 'formulier', label: t('procest', 'Online form (formulier)') },
				{ id: 'balie', label: t('procest', 'In person (balie)') },
			],
		}
	},
	mounted() {
		this.loadExistingObjection()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async loadExistingObjection() {
			const bezwaarStore = useBezwaarStore()
			if (bezwaarStore.currentObjection) {
				const obj = bezwaarStore.currentObjection
				this.form.contestedDecision = obj.contestedDecision || ''
				this.form.grounds = obj.grounds || ''
				this.form.requestedRelief = obj.requestedRelief || ''
				this.form.receivedDate = obj.receivedDate || ''
				this.form.receivedChannel = obj.receivedChannel || 'brief'
				this.form.isTimely = obj.isTimely
				this.form.timelinessAssessment = obj.timelinessAssessment || ''
				this.form.proProvision = obj.proProvision || false

				if (this.form.receivedDate) {
					this.checkTimeliness()
					this.calculateDeadlines()
				}
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		checkTimeliness() {
			if (!this.form.receivedDate || !this.besluitDate) {
				this.timelinessResult = null
				return
			}

			const bezwaarStore = useBezwaarStore()
			this.timelinessResult = bezwaarStore.checkTimeliness(
				this.besluitDate,
				this.form.receivedDate,
			)
			this.form.isTimely = this.timelinessResult.isTimely
			this.calculateDeadlines()
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		calculateDeadlines() {
			if (!this.form.receivedDate) {
				this.deadlines = null
				return
			}

			const bezwaarStore = useBezwaarStore()
			this.deadlines = bezwaarStore.calculateDeadlines(this.form.receivedDate)
			this.$emit('deadlines-calculated', this.deadlines)
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		validate() {
			this.errors = {}

			if (!this.form.contestedDecision) {
				this.errors.contestedDecision = t(
					'procest',
					'Contested decision is required',
				)
			}
			if (!this.form.grounds) {
				this.errors.grounds = t(
					'procest',
					'Grounds for objection are required',
				)
			}
			if (!this.form.receivedDate) {
				this.errors.receivedDate = t('procest', 'Date received is required')
			}

			return Object.keys(this.errors).length === 0
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async save() {
			if (!this.validate()) return

			this.saving = true
			const bezwaarStore = useBezwaarStore()

			const data = {
				...this.form,
				case: this.caseId,
			}

			if (bezwaarStore.currentObjection) {
				data.id = bezwaarStore.currentObjection.id
				await bezwaarStore.updateObjection(data)
			} else {
				await bezwaarStore.createObjection(data)
			}

			this.saving = false
			this.$emit('saved')
		},
	},
}
</script>

<style scoped>
.bezwaar-intake-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
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

.bezwaar-intake-form__warning,
.bezwaar-intake-form__info {
	margin: 8px 0;
}

.bezwaar-intake-form__deadlines {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.deadline-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 12px;
	margin-top: 8px;
}

.deadline-item {
	display: flex;
	flex-direction: column;
}

.deadline-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-value {
	font-weight: bold;
}

.bezwaar-intake-form__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 8px;
}

textarea {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	resize: vertical;
}
</style>
