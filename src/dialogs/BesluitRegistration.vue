<template>
	<NcDialog
		:name="t('procest', 'Register decision')"
		size="normal"
		@closing="$emit('close')">
		<div class="besluit-registration">
			<div class="form-group">
				<label for="besluit-registration-title">{{ t('procest', 'Title') }} *</label>
				<NcTextField
					id="besluit-registration-title"
					:model-value="form.title"
					:error="!!errors.title"
					:placeholder="t('procest', 'Title of the decision...')"
					@update:model-value="v => { form.title = v; errors.title = '' }" />
				<p v-if="errors.title" class="form-error">
					{{ errors.title }}
				</p>
			</div>

			<div class="form-group">
				<label for="besluit-registration-effective-date">{{ t('procest', 'Effective date') }}</label>
				<NcTextField
					id="besluit-registration-effective-date"
					type="date"
					:model-value="form.effectiveDate"
					@update:model-value="v => form.effectiveDate = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Decision type') }}</label>
				<NcSelect
					v-model="selectedDecisionType"
					:options="decisionTypes"
					:aria-label-combobox="t('procest', 'Decision type')"
					label="name"
					track-by="id"
					:placeholder="t('procest', 'Select decision type...')" />
			</div>

			<div class="form-group">
				<label for="besluit-registration-explanation">{{ t('procest', 'Explanation') }}</label>
				<textarea
					id="besluit-registration-explanation"
					v-model="form.explanation"
					:placeholder="t('procest', 'Explanation of the decision...')"
					rows="3" />
			</div>

			<div class="form-group">
				<label for="besluit-registration-governing-body">{{ t('procest', 'Administrative body') }}</label>
				<NcTextField
					id="besluit-registration-governing-body"
					:model-value="form.governingBody"
					:placeholder="t('procest', 'College van B&W')"
					@update:model-value="v => form.governingBody = v" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="register">
				<template v-if="saving">
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('procest', 'Register') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import { registerBesluit } from '../services/voorstelBesluitApi.js'

export default {
	name: 'BesluitRegistration',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},
	props: {
		voorstel: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			saving: false,
			decisionTypes: [],
			selectedDecisionType: null,
			form: {
				title: this.voorstel.onderwerp || '',
				effectiveDate: new Date().toISOString().split('T')[0],
				explanation: '',
				governingBody: 'College van B&W',
			},
			errors: {},
		}
	},
	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		objectStore() {
			return useObjectStore()
		},
	},
	/** @spec openspec/specs/parafering-actions/spec.md */
	async created() {
		try {
			const results = await this.objectStore.fetchCollection('decisionType', { _limit: 50 })
			this.decisionTypes = Array.isArray(results) ? results : (results?.results || [])
		} catch (error) {
			console.error('Failed to load decision types:', error)
		}
	},
	methods: {
		/**
		 * Register a besluit on the voorstel.
		 *
		 * The besluit is no longer authored locally as a procest `decision`
		 * object. It is raised as a decidesk `report-adoption` Decision via the
		 * ADR-019 integration registry (procest-delegate-remaining-decisions-to-decidesk,
		 * REQ-PDRD-001); the ZGW Besluit becomes a projection of the decidesk
		 * outcome. The server fails CLOSED (REQ-PDRD-002) when decidesk is
		 * unavailable — no local besluit is authored as a fallback.
		 *
		 * @spec openspec/specs/remaining-decision-delegation/spec.md
		 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
		 */
		async register() {
			this.errors = {}
			if (!this.form.title.trim()) {
				this.errors.title = t('procest', 'Title is required')
				return
			}

			this.saving = true
			try {
				// Raise a decidesk report-adoption Decision for this voorstel.
				// procest keeps the parafeerroute untouched; only the besluit
				// decision is delegated, and the besluit is materialised from
				// the decidesk outcome.
				await registerBesluit(this.voorstel.id || this.voorstel._self?.id, {
					title: this.form.title.trim(),
					effectiveDate: this.form.effectiveDate || undefined,
					explanation: this.form.explanation || undefined,
					governingBody: this.form.governingBody || undefined,
					decisionType: this.selectedDecisionType ? this.selectedDecisionType.id : undefined,
				})

				// Reflect the awaiting-decidesk state on the voorstel (projection;
				// the decided besluit lands when decidesk posts the outcome).
				await this.objectStore.saveObject('proposal', {
					...this.voorstel,
					status: 'awaiting-decidesk',
				})

				this.$emit('registered')
			} catch (error) {
				console.error('Failed to register besluit:', error)
				this.errors.title = error.response?.data?.error || error.message || t('procest', 'Registration failed')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.besluit-registration {
	padding: 8px 0;
}

.form-group {
	margin-bottom: 16px;
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
