<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('procest', 'Ad-hoc stap toevoegen')"
		size="normal"
		:canClose="!submitting"
		@closing="onClose">
		<div class="add-step-dialog">
			<div class="add-step-dialog__field">
				<label class="add-step-dialog__label">
					{{ t('procest', 'Invoegen na stap') }}
				</label>
				<NcSelect
					v-model="afterStep"
					:options="insertionOptions"
					:aria-label-combobox="t('procest', 'Invoegen na stap')"
					label="label"
					:reduce="(opt) => opt.value"
					:placeholder="t('procest', 'Select insert position')" />
			</div>
			<div class="add-step-dialog__field">
				<label class="add-step-dialog__label">
					{{ t('procest', 'Stap type') }}
				</label>
				<NcSelect
					v-model="stepType"
					:options="stepTypeOptions"
					:aria-label-combobox="t('procest', 'Stap type')"
					:placeholder="t('procest', 'Select type')" />
			</div>
			<div class="add-step-dialog__field">
				<label class="add-step-dialog__label">
					{{ t('procest', 'Actor type') }}
				</label>
				<NcSelect
					v-model="actorType"
					:options="actorTypeOptions"
					:aria-label-combobox="t('procest', 'Actor type')"
					:placeholder="t('procest', 'Select actor type')" />
			</div>
			<div class="add-step-dialog__field">
				<NcTextField
					:modelValue="actor"
					:label="t('procest', 'Actor (UID, groep of rol)')"
					required
					@update:modelValue="(v) => (actor = v)" />
			</div>
			<div class="add-step-dialog__field">
				<NcCheckboxRadioSwitch
					:modelValue="mandatory"
					@update:modelValue="(v) => (mandatory = v)">
					{{ t('procest', 'Verplichte stap') }}
				</NcCheckboxRadioSwitch>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="onSubmit">
				{{
					submitting
						? t('procest', 'Bezig...')
						: t('procest', 'Stap toevoegen')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import parafeerRouteApi from '../services/parafeerRouteApi.js'

export default {
	name: 'AddStepDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		voorstelId: {
			type: String,
			required: true,
		},

		routeSnapshot: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			afterStep: null,
			stepType: 'advies',
			actorType: 'user',
			actor: '',
			mandatory: false,
			submitting: false,
			error: '',
			stepTypeOptions: ['advies', 'parafering', 'accordering'],
			actorTypeOptions: ['user', 'group', 'role'],
		}
	},

	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		insertionOptions() {
			return this.routeSnapshot.map((s) => ({
				label: this.t('procest', 'Na stap {n} — {actor}', {
					n: s.order,
					actor: s.actor,
				}),
				value: s.order,
			}))
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		canSubmit() {
			return (
				!this.submitting
				&& this.afterStep !== null
				&& this.actor.trim().length > 0
			)
		},
	},

	watch: {
		/**
		 * @param value
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		open(value) {
			if (value) {
				this.afterStep = null
				this.stepType = 'advies'
				this.actorType = 'user'
				this.actor = ''
				this.mandatory = false
				this.error = ''
			}
		},
	},

	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		async onSubmit() {
			if (!this.canSubmit) return
			this.submitting = true
			this.error = ''
			try {
				await parafeerRouteApi.addStep(this.voorstelId, {
					afterStep: this.afterStep,
					stepData: {
						type: this.stepType,
						actorType: this.actorType,
						actor: this.actor.trim(),
						mandatory: this.mandatory,
					},
				})
				this.$emit('step-added')
			} catch (err) {
				this.error = this.t('procest', 'Stap toevoegen mislukt')
				console.error('addStep failed', err)
			} finally {
				this.submitting = false
			}
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.add-step-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.add-step-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.add-step-dialog__label {
	font-weight: 600;
	font-size: 0.9em;
}
</style>
