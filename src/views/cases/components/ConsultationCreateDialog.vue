<template>
	<NcDialog
		:name="t('procest', 'New consultation request')"
		:open="open"
		@close="$emit('close')">
		<div class="consultation-create-dialog__body">
			<!-- Error banner -->
			<div v-if="error" class="consultation-create-dialog__error">
				{{ error }}
			</div>

			<!-- Advisory body -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Advisory body') }} *
				</label>
				<NcSelect
					v-if="hasAdvisoryBodies"
					:value="selectedBody"
					:options="bodyOptions"
					:input-label="t('procest', 'Select advisory body')"
					label="name"
					track-by="id"
					@update:value="onBodySelect" />
				<NcTextField
					v-else
					:value="form.adviesInstantie"
					:placeholder="t('procest', 'e.g., Brandweer, Welstandscommissie')"
					:label="t('procest', 'Department or organization')"
					@update:value="v => form.adviesInstantie = v" />
			</div>

			<!-- Subject -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Subject') }} *
				</label>
				<NcTextField
					:value="form.onderwerp"
					:label="t('procest', 'Subject')"
					@update:value="v => form.onderwerp = v" />
			</div>

			<!-- Question -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Specific questions') }} *
				</label>
				<textarea
					v-model="form.vraagstelling"
					class="consultation-create-dialog__textarea"
					rows="4"
					:placeholder="t('procest', 'What specific questions do you want answered?')" />
			</div>

			<!-- Deadline -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Response deadline') }} *
				</label>
				<input
					v-model="form.uiterlijkeReactiedatum"
					class="consultation-create-dialog__date"
					type="date"
					:min="today" />
			</div>

			<!-- Priority -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Priority') }}
				</label>
				<NcSelect
					:value="form.prioriteit"
					:options="priorityOptions"
					:input-label="t('procest', 'Priority')"
					label="label"
					track-by="value"
					@update:value="v => form.prioriteit = v ? v.value : 'normaal'" />
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!isValid || saving"
				@click="submit">
				{{ saving ? t('procest', 'Saving...') : t('procest', 'Create consultation') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ConsultationCreateDialog',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			required: true,
		},
		caseId: {
			type: String,
			required: true,
		},
		caseTitle: {
			type: String,
			default: '',
		},
		advisoryBodies: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'create'],

	data() {
		return {
			saving: false,
			error: null,
			selectedBody: null,
			form: {
				adviesInstantie: '',
				advisoryBodyId: null,
				onderwerp: '',
				vraagstelling: '',
				uiterlijkeReactiedatum: '',
				prioriteit: 'normaal',
			},
		}
	},

	computed: {
		hasAdvisoryBodies() {
			return this.advisoryBodies.length !== 0
		},

		today() {
			return new Date().toISOString().split('T')[0]
		},

		defaultDeadline() {
			const d = new Date()
			d.setDate(d.getDate() + 28)
			return d.toISOString().split('T')[0]
		},

		isValid() {
			return this.form.adviesInstantie.trim() !== ''
				&& this.form.onderwerp.trim() !== ''
				&& this.form.vraagstelling.trim() !== ''
				&& this.form.uiterlijkeReactiedatum !== ''
		},

		bodyOptions() {
			return this.advisoryBodies.map(b => ({
				id: b.id || b.uuid,
				name: b.name,
				specializations: b.specializations || [],
			}))
		},

		priorityOptions() {
			return [
				{ value: 'normaal', label: t('procest', 'Normal') },
				{ value: 'spoed', label: t('procest', 'Urgent') },
			]
		},
	},

	mounted() {
		this.form.onderwerp = this.caseTitle
		this.form.uiterlijkeReactiedatum = this.defaultDeadline
	},

	methods: {
		t,

		onBodySelect(body) {
			this.selectedBody = body
			if (body) {
				this.form.adviesInstantie = body.name
				this.form.advisoryBodyId = body.id
			} else {
				this.form.adviesInstantie = ''
				this.form.advisoryBodyId = null
			}
		},

		async submit() {
			if (!this.isValid || this.saving) return
			this.saving = true
			this.error = null
			try {
				this.$emit('create', {
					parentZaak: this.caseId,
					...this.form,
				})
				this.$emit('close')
			} catch (err) {
				this.error = err.message || t('procest', 'Could not create consultation')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.consultation-create-dialog__body {
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.consultation-create-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-create-dialog__label {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.consultation-create-dialog__textarea {
	width: 100%;
	min-height: 80px;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 0.875rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
}

.consultation-create-dialog__date {
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 0.875rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-create-dialog__error {
	background: var(--color-error-soft, #fce4ec);
	color: var(--color-error, #c62828);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	font-size: 0.875rem;
}
</style>
