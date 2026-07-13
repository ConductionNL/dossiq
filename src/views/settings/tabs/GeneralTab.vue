<template>
	<div class="general-tab">
		<!-- Title -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Title') }}</label>
			<NcTextField
				:value="form.title"
				:error="!!errors.title"
				:helper-text="errors.title"
				@update:value="v => $emit('update', 'title', v)" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<label>{{ t('procest', 'Description') }}</label>
			<textarea
				class="general-tab__textarea"
				:value="form.description"
				@input="$emit('update', 'description', $event.target.value)" />
		</div>

		<!-- Purpose -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Purpose') }}</label>
			<NcTextField
				:value="form.purpose"
				:error="!!errors.purpose"
				:helper-text="errors.purpose"
				@update:value="v => $emit('update', 'purpose', v)" />
		</div>

		<!-- Trigger -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Trigger') }}</label>
			<NcTextField
				:value="form.trigger"
				:error="!!errors.trigger"
				:helper-text="errors.trigger"
				@update:value="v => $emit('update', 'trigger', v)" />
		</div>

		<!-- Subject -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Subject') }}</label>
			<NcTextField
				:value="form.subject"
				:error="!!errors.subject"
				:helper-text="errors.subject"
				@update:value="v => $emit('update', 'subject', v)" />
		</div>

		<!-- Initiator Action -->
		<div class="form-group">
			<label>{{ t('procest', 'Initiator action') }}</label>
			<NcTextField
				:value="form.initiatorAction"
				@update:value="v => $emit('update', 'initiatorAction', v)" />
		</div>

		<!-- Handler Action -->
		<div class="form-group">
			<label>{{ t('procest', 'Handler action') }}</label>
			<NcTextField
				:value="form.handlerAction"
				@update:value="v => $emit('update', 'handlerAction', v)" />
		</div>

		<!-- Origin -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Origin') }}</label>
			<NcSelect
				:value="selectedOrigin"
				:options="originOptions"
				:aria-label-combobox="t('procest', 'Origin')"
				@input="v => $emit('update', 'origin', v ? v.id : '')" />
			<span v-if="errors.origin" class="field-error">{{ errors.origin }}</span>
		</div>

		<!-- Processing Deadline -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Processing deadline') }}</label>
			<DurationPicker
				:value="form.processingDeadline"
				preset-type="deadline"
				@input="v => $emit('update', 'processingDeadline', v)" />
			<span v-if="errors.processingDeadline" class="field-error">{{ errors.processingDeadline }}</span>
		</div>

		<!-- Service Target -->
		<div class="form-group">
			<label>{{ t('procest', 'Service target') }}</label>
			<DurationPicker
				:value="form.serviceTarget"
				preset-type="deadline"
				@input="v => $emit('update', 'serviceTarget', v)" />
			<span v-if="errors.serviceTarget" class="field-error">{{ errors.serviceTarget }}</span>
		</div>

		<!-- Extension Allowed -->
		<div class="form-group form-group--inline">
			<NcCheckboxRadioSwitch
				:checked="form.extensionAllowed"
				@update:checked="v => $emit('update', 'extensionAllowed', v)">
				{{ t('procest', 'Extension allowed') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Extension Period (conditional) -->
		<div v-if="form.extensionAllowed" class="form-group">
			<label class="required">{{ t('procest', 'Extension period') }}</label>
			<DurationPicker
				:value="form.extensionPeriod"
				preset-type="extension"
				@input="v => $emit('update', 'extensionPeriod', v)" />
			<span v-if="errors.extensionPeriod" class="field-error">{{ errors.extensionPeriod }}</span>
		</div>

		<!-- Confidentiality -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Confidentiality') }}</label>
			<NcSelect
				:value="selectedConfidentiality"
				:options="confidentialityOptions"
				:aria-label-combobox="t('procest', 'Confidentiality')"
				@input="v => $emit('update', 'confidentiality', v ? v.id : '')" />
			<span v-if="errors.confidentiality" class="field-error">{{ errors.confidentiality }}</span>
		</div>

		<!-- IV3 taakveld -->
		<div class="form-group">
			<label>{{ t('procest', 'IV3 taakveld') }}</label>
			<NcSelect
				:value="selectedIv3Taakveld"
				:options="iv3TaakveldOptions"
				:loading="iv3TaakveldenLoading"
				:placeholder="t('procest', 'No IV3 classification')"
				:aria-label-combobox="t('procest', 'IV3 taakveld')"
				@input="v => $emit('update', 'iv3Taakveld', v ? v.id : '')" />
			<p class="general-tab__hint">
				{{ t('procest', 'Classifies cases of this type for the quarterly IV3 (Informatie voor Derden) cost report to CBS. Leave empty if this case type has no taakveld — such cases are reported as uncategorized.') }}
			</p>
		</div>

		<!-- Publication Required -->
		<div class="form-group form-group--inline">
			<NcCheckboxRadioSwitch
				:checked="form.publicationRequired"
				@update:checked="v => $emit('update', 'publicationRequired', v)">
				{{ t('procest', 'Publication required') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Publication Text (conditional) -->
		<div v-if="form.publicationRequired" class="form-group">
			<label>{{ t('procest', 'Publication text') }}</label>
			<textarea
				class="general-tab__textarea"
				:value="form.publicationText"
				@input="$emit('update', 'publicationText', $event.target.value)" />
		</div>

		<!-- Responsible Unit -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Responsible unit') }}</label>
			<NcTextField
				:value="form.responsibleUnit"
				:error="!!errors.responsibleUnit"
				:helper-text="errors.responsibleUnit"
				@update:value="v => $emit('update', 'responsibleUnit', v)" />
		</div>

		<!-- Reference Process -->
		<div class="form-group">
			<label>{{ t('procest', 'Reference process') }}</label>
			<NcTextField
				:value="form.referenceProcess"
				@update:value="v => $emit('update', 'referenceProcess', v)" />
		</div>

		<!-- Keywords -->
		<div class="form-group">
			<label>{{ t('procest', 'Keywords') }}</label>
			<NcTextField
				:value="form.keywords"
				:placeholder="t('procest', 'Comma-separated keywords')"
				@update:value="v => $emit('update', 'keywords', v)" />
		</div>

		<!-- Valid From -->
		<div class="form-group">
			<label class="required">{{ t('procest', 'Valid from') }}</label>
			<input
				type="date"
				class="general-tab__date"
				:value="form.validFrom"
				@input="$emit('update', 'validFrom', $event.target.value)">
		</div>

		<!-- Valid Until -->
		<div class="form-group">
			<label>{{ t('procest', 'Valid until') }}</label>
			<input
				type="date"
				class="general-tab__date"
				:value="form.validUntil"
				@input="$emit('update', 'validUntil', $event.target.value)">
			<span v-if="errors.validUntil" class="field-error">{{ errors.validUntil }}</span>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { formatDuration } from '../../../utils/durationHelpers.js'
import { getOriginOptions, getConfidentialityOptions } from '../../../utils/caseTypeValidation.js'
import DurationPicker from '../components/DurationPicker.vue'

export default {
	name: 'GeneralTab',
	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		DurationPicker,
	},
	props: {
		form: {
			type: Object,
			required: true,
		},
		errors: {
			type: Object,
			default: () => ({}),
		},
	},
	data() {
		return {
			iv3Taakvelden: [],
			iv3TaakveldenLoading: false,
		}
	},
	mounted() {
		this.loadIv3Taakvelden()
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		originOptions() {
			return getOriginOptions()
		},
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		confidentialityOptions() {
			return getConfidentialityOptions()
		},
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		selectedOrigin() {
			if (!this.form.origin) return null
			return this.originOptions.find(o => o.id === this.form.origin) || null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		selectedConfidentiality() {
			if (!this.form.confidentiality) return null
			return this.confidentialityOptions.find(o => o.id === this.form.confidentiality) || null
		},
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.3 */
		iv3TaakveldOptions() {
			return this.iv3Taakvelden.map(tv => ({ id: tv.code, label: `${tv.code} — ${tv.label}` }))
		},
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.3 */
		selectedIv3Taakveld() {
			if (!this.form.iv3Taakveld) return null
			return this.iv3TaakveldOptions.find(o => o.id === this.form.iv3Taakveld) || null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		deadlinePreview() {
			return this.form.processingDeadline ? formatDuration(this.form.processingDeadline) : ''
		},
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		serviceTargetPreview() {
			return this.form.serviceTarget ? formatDuration(this.form.serviceTarget) : ''
		},
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		extensionPreview() {
			return this.form.extensionPeriod ? formatDuration(this.form.extensionPeriod) : ''
		},
	},
	methods: {
		/**
		 * Load the IV3 taakveld reference list once, for the picker's options.
		 *
		 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.3
		 */
		async loadIv3Taakvelden() {
			this.iv3TaakveldenLoading = true
			try {
				const res = await axios.get(generateUrl('/apps/procest/api/reports/iv3/taakvelden'))
				this.iv3Taakvelden = (res.data && res.data.taakvelden) || []
			} catch (e) {
				// Non-fatal: the picker just shows no options when this fails.
				this.iv3Taakvelden = []
			} finally {
				this.iv3TaakveldenLoading = false
			}
		},
	},
}
</script>

<style scoped>
.general-tab {
	max-width: 600px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group--inline {
	margin-bottom: 8px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-group label.required::after {
	content: ' *';
	color: var(--color-error);
}

.general-tab__textarea {
	width: 100%;
	min-height: 80px;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: inherit;
	resize: vertical;
}

.general-tab__textarea:focus {
	border-color: var(--color-primary);
	outline: none;
}

.general-tab__date {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: inherit;
}

.general-tab__date:focus {
	border-color: var(--color-primary);
	outline: none;
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.general-tab__hint {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin-top: 4px;
}
</style>
