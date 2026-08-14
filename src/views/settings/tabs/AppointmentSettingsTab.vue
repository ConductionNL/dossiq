<template>
	<div class="appointment-settings-tab">
		<h2>{{ t('procest', 'Appointment Scheduling') }}</h2>

		<div class="form-group">
			<label>{{ t('procest', 'Backend') }}</label>
			<NcSelect
				v-model="backend"
				:options="backendOptions"
				:aria-label-combobox="t('procest', 'Backend')"
				label="label"
				trackBy="value"
				@update:modelValue="saveBackend" />
		</div>

		<template v-if="backend && backend.value !== 'local'">
			<div class="form-group">
				<NcTextField
					:modelValue="backendUrl"
					:label="t('procest', 'API URL')"
					@update:modelValue="(v) => (backendUrl = v)" />
			</div>
			<div class="form-group">
				<NcPasswordField
					:modelValue="backendApiKey"
					:label="t('procest', 'API Key')"
					@update:modelValue="(v) => (backendApiKey = v)" />
			</div>
		</template>

		<div class="form-group">
			<NcTextField
				:modelValue="reminderDays"
				:label="t('procest', 'Reminder days before appointment')"
				type="number"
				@update:modelValue="(v) => (reminderDays = v)" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AppointmentSettingsTab',
	components: { NcSelect, NcTextField, NcPasswordField },
	data() {
		return {
			backend: {
				value: 'local',
				label: t('procest', 'Local (no external system)'),
			},

			backendOptions: [
				{
					value: 'local',
					label: t('procest', 'Local (no external system)'),
				},
				{ value: 'jcc', label: t('procest', 'JCC Afspraken') },
				{ value: 'qmatic', label: t('procest', 'Qmatic Orchestra') },
			],

			backendUrl: '',
			backendApiKey: '',
			reminderDays: '1',
		}
	},

	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		saveBackend() {
			// Save via settings API
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
</style>
