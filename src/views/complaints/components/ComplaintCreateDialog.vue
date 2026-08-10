<template>
	<div class="dialog-overlay"
		role="button"
		tabindex="0"
		@click.self="$emit('close')"
		@keydown.enter.self="$emit('close')"
		@keydown.space.self.prevent="$emit('close')">
		<div class="dialog" role="dialog" :aria-label="t('procest', 'New Complaint')">
			<h2>{{ t('procest', 'Register New Complaint') }}</h2>

			<div class="form-group">
				<label for="complaint-create-onderwerp">{{ t('procest', 'Subject') }} *</label>
				<NcTextField
					id="complaint-create-onderwerp"
					:model-value="form.onderwerp"
					:error="!!errors.onderwerp"
					@update:model-value="v => { form.onderwerp = v; errors.onderwerp = '' }" />
				<p v-if="errors.onderwerp" class="form-error">
					{{ errors.onderwerp }}
				</p>
			</div>

			<div class="form-group">
				<label for="complaint-create-omschrijving">{{ t('procest', 'Description') }} *</label>
				<textarea
					id="complaint-create-omschrijving"
					v-model="form.omschrijving"
					rows="4"
					:class="{ 'input--error': errors.omschrijving }" />
				<p v-if="errors.omschrijving" class="form-error">
					{{ errors.omschrijving }}
				</p>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label for="complaint-create-klager-naam">{{ t('procest', 'Complainant name') }}</label>
					<NcTextField
						id="complaint-create-klager-naam"
						:model-value="form.klagerNaam"
						@update:model-value="v => form.klagerNaam = v" />
				</div>
				<div class="form-group">
					<label for="complaint-create-klager-email">{{ t('procest', 'Email') }}</label>
					<NcTextField
						id="complaint-create-klager-email"
						:model-value="form.klagerEmail"
						@update:model-value="v => form.klagerEmail = v" />
				</div>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label>{{ t('procest', 'Intake channel') }}</label>
					<NcSelect
						v-model="form.ontvangstkanaal"
						:options="channelOptions"
						:aria-label-combobox="t('procest', 'Intake channel')" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Priority') }}</label>
					<NcSelect
						v-model="form.prioriteit"
						:options="priorityOptions"
						:aria-label-combobox="t('procest', 'Priority')" />
				</div>
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Category') }}</label>
				<NcSelect
					v-model="form.categorie"
					:options="categories"
					:aria-label-combobox="t('procest', 'Category')"
					label="name"
					track-by="id"
					:placeholder="t('procest', 'Select category...')" />
			</div>

			<div class="dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="create">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Create Complaint') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'ComplaintCreateDialog',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
	},
	props: {
		categories: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'created'],
	data() {
		return {
			saving: false,
			form: {
				onderwerp: '',
				omschrijving: '',
				klagerNaam: '',
				klagerEmail: '',
				ontvangstkanaal: null,
				prioriteit: 'normaal',
				categorie: null,
			},
			errors: {},
		}
	},
	computed: {
		/** @spec openspec/specs/complaint-management/spec.md */
		channelOptions() {
			return [
				{ id: 'balie', label: this.t('procest', 'Counter') },
				{ id: 'telefoon', label: this.t('procest', 'Phone') },
				{ id: 'email', label: this.t('procest', 'Email') },
				{ id: 'brief', label: this.t('procest', 'Letter') },
				{ id: 'website', label: this.t('procest', 'Website') },
				{ id: 'socialmedia', label: this.t('procest', 'Social media') },
			]
		},
		/** @spec openspec/specs/complaint-management/spec.md */
		priorityOptions() {
			return [
				{ id: 'laag', label: this.t('procest', 'Low') },
				{ id: 'normaal', label: this.t('procest', 'Normal') },
				{ id: 'hoog', label: this.t('procest', 'High') },
				{ id: 'urgent', label: this.t('procest', 'Urgent') },
			]
		},
	},
	methods: {
		/** @spec openspec/specs/complaint-management/spec.md */
		validate() {
			this.errors = {}
			if (!this.form.onderwerp.trim()) {
				this.errors.onderwerp = this.t('procest', 'Subject is required')
			}
			if (!this.form.omschrijving.trim()) {
				this.errors.omschrijving = this.t('procest', 'Description is required')
			}
			return Object.keys(this.errors).length === 0
		},
		/** @spec openspec/specs/complaint-management/spec.md */
		async create() {
			if (!this.validate()) return
			this.saving = true
			try {
				const store = useObjectStore()
				const data = {
					...this.form,
					ontvangstdatum: new Date().toISOString().split('T')[0],
					status: 'ontvangen',
					prioriteit: this.form.prioriteit?.id || this.form.prioriteit || 'normaal',
					ontvangstkanaal: this.form.ontvangstkanaal?.id || this.form.ontvangstkanaal || null,
					categorie: this.form.categorie?.id || this.form.categorie || null,
				}
				await store.createObject('complaint', data)
				this.$emit('created')
			} catch (error) {
				console.error('Failed to create complaint:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.dialog-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	width: 600px;
	max-width: 90vw;
	max-height: 80vh;
	overflow-y: auto;
}

.dialog h2 {
	margin: 0 0 16px;
}

.dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-size: 13px;
	font-weight: 500;
	margin-bottom: 4px;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.input--error {
	border-color: var(--color-error);
}
</style>
