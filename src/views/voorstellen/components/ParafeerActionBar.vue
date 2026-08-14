<template>
	<CnDetailCard :title="actionTitle" class="parafeer-action-bar">
		<!-- Advisory step: Adviseren -->
		<template v-if="isAdvisoryStep">
			<div class="form-group">
				<label for="parafeer-action-advice">{{
					t('procest', 'Advice')
				}}</label>
				<textarea
					id="parafeer-action-advice"
					v-model="adviceText"
					:placeholder="t('procest', 'Provide your advice...')"
					rows="3" />
			</div>
			<div class="parafeer-action-bar__actions">
				<NcButton variant="primary" :disabled="acting" @click="doAdviseren">
					{{ t('procest', 'Advise') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="acting"
					@click="showReturnForm = true">
					{{ t('procest', 'Return') }}
				</NcButton>
			</div>
		</template>

		<!-- Parafering/Accordering step -->
		<template v-else>
			<div class="parafeer-action-bar__actions">
				<NcButton variant="primary" :disabled="acting" @click="doParaferen">
					{{ t('procest', 'Endorse') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="acting"
					@click="showReturnForm = true">
					{{ t('procest', 'Return') }}
				</NcButton>
			</div>

			<!-- Delegation option -->
			<div v-if="!showReturnForm" class="parafeer-action-bar__delegation">
				<label>
					<input v-model="isDelegating" type="checkbox" />
					{{ t('procest', 'Endorse on behalf of someone else') }}
				</label>
				<div
					v-if="isDelegating"
					class="parafeer-action-bar__delegation-fields">
					<NcTextField
						:modelValue="delegateFor"
						:placeholder="t('procest', 'User ID of principal')"
						:aria-label="t('procest', 'User ID of principal')"
						@update:modelValue="(v) => (delegateFor = v)" />
					<NcTextField
						:modelValue="mandateRef"
						:placeholder="t('procest', 'Mandate reference')"
						:aria-label="t('procest', 'Mandate reference')"
						@update:modelValue="(v) => (mandateRef = v)" />
				</div>
			</div>
		</template>

		<!-- Return form (shared) -->
		<div v-if="showReturnForm" class="parafeer-action-bar__return-form">
			<div class="form-group">
				<label for="parafeer-return-reason"
					>{{ t('procest', 'Reason for returning') }} *</label
				>
				<textarea
					id="parafeer-return-reason"
					v-model="returnComment"
					:placeholder="
						t(
							'procest',
							'Provide the reason why the proposal is being returned...',
						)
					"
					rows="3" />
				<p v-if="returnError" class="form-error">
					{{ returnError }}
				</p>
			</div>
			<div class="parafeer-action-bar__actions">
				<NcButton variant="error" :disabled="acting" @click="doTerugsturen">
					{{ t('procest', 'Return') }}
				</NcButton>
				<NcButton @click="showReturnForm = false">
					{{ t('procest', 'Annuleren') }}
				</NcButton>
			</div>
		</div>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { getNextStep, getStatusAfterAdvance } from '../../../utils/parafeerEngine.js'

export default {
	name: 'ParafeerActionBar',
	components: {
		NcButton,
		NcTextField,
		CnDetailCard,
	},

	props: {
		voorstel: {
			type: Object,
			required: true,
		},

		currentStepInfo: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			acting: false,
			adviceText: '',
			returnComment: '',
			returnError: '',
			showReturnForm: false,
			isDelegating: false,
			delegateFor: '',
			mandateRef: '',
		}
	},

	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		objectStore() {
			return useObjectStore()
		},

		isAdvisoryStep() {
			return this.currentStepInfo?.type === 'advies'
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		actionTitle() {
			const label = this.currentStepInfo?.label || t('procest', 'Your action')
			return `${label} — ${this.formatStepType(this.currentStepInfo?.type)}`
		},
	},

	methods: {
		/**
		 * @param type
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatStepType(type) {
			const labels = {
				advies: 'Advies',
				parafering: 'Parafering',
				accordering: 'Accordering',
			}
			return labels[type] || type || ''
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		async doParaferen() {
			this.acting = true
			try {
				const userId = getCurrentUser()?.uid || ''

				// Create parafeeractie
				const actieData = {
					voorstel: this.voorstel.id,
					step: this.voorstel.currentStep,
					actor: userId,
					actorType: this.isDelegating ? 'delegate' : 'user',
					action: 'parafered',
				}
				if (this.isDelegating && this.delegateFor) {
					actieData.onBehalfOf = this.delegateFor
					actieData.mandate = this.mandateRef
				}
				await this.objectStore.saveObject('parafeeractie', actieData)

				// Advance voorstel
				const nextStep = getNextStep(this.voorstel)
				const newStatus = getStatusAfterAdvance(this.voorstel)
				await this.objectStore.saveObject('voorstel', {
					...this.voorstel,
					currentStep: nextStep || this.voorstel.currentStep,
					status: newStatus,
				})

				this.$emit('action-completed')
			} catch (error) {
				console.error('Paraferen failed:', error)
			} finally {
				this.acting = false
			}
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		async doAdviseren() {
			this.acting = true
			try {
				const userId = getCurrentUser()?.uid || ''

				await this.objectStore.saveObject('parafeeractie', {
					voorstel: this.voorstel.id,
					step: this.voorstel.currentStep,
					actor: userId,
					actorType: 'user',
					action: 'advised',
					advice: this.adviceText,
				})

				const nextStep = getNextStep(this.voorstel)
				const newStatus = getStatusAfterAdvance(this.voorstel)
				await this.objectStore.saveObject('voorstel', {
					...this.voorstel,
					currentStep: nextStep || this.voorstel.currentStep,
					status: newStatus,
				})

				this.$emit('action-completed')
			} catch (error) {
				console.error('Adviseren failed:', error)
			} finally {
				this.acting = false
			}
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		async doTerugsturen() {
			if (!this.returnComment.trim()) {
				this.returnError = t('procest', 'Reason is required when returning')
				return
			}
			this.returnError = ''
			this.acting = true
			try {
				const userId = getCurrentUser()?.uid || ''

				await this.objectStore.saveObject('parafeeractie', {
					voorstel: this.voorstel.id,
					step: this.voorstel.currentStep,
					actor: userId,
					actorType: 'user',
					action: 'returned',
					comment: this.returnComment.trim(),
				})

				await this.objectStore.saveObject('voorstel', {
					...this.voorstel,
					status: 'teruggestuurd',
					returnedFromStep: this.voorstel.currentStep,
				})

				this.$emit('action-completed')
			} catch (error) {
				console.error('Terugsturen failed:', error)
			} finally {
				this.acting = false
			}
		},
	},
}
</script>

<style scoped>
.parafeer-action-bar__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.parafeer-action-bar__delegation {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.parafeer-action-bar__delegation-fields {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.parafeer-action-bar__return-form {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.form-group {
	margin-bottom: 8px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.form-group textarea {
	width: 100%;
	resize: vertical;
}

.form-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin-top: 4px;
}
</style>
