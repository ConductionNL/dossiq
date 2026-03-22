<template>
	<div class="appointment-settings-tab">
		<h2>{{ t('procest', 'Appointment Scheduling') }}</h2>

		<div class="form-group">
			<label>{{ t('procest', 'Backend') }}</label>
			<NcSelect
				v-model="backend"
				:options="backendOptions"
				label="label"
				track-by="value"
				@input="saveBackend" />
		</div>

		<template v-if="backend && backend.value !== 'local'">
			<div class="form-group">
				<NcTextField :value="backendUrl" :label="t('procest', 'API URL')"
					@update:value="v => backendUrl = v" />
			</div>
			<div class="form-group">
				<NcPasswordField :value="backendApiKey" :label="t('procest', 'API Key')"
					@update:value="v => backendApiKey = v" />
			</div>
		</template>

		<div class="form-group">
			<NcTextField :value="reminderDays" :label="t('procest', 'Reminder days before appointment')"
				type="number"
				@update:value="v => reminderDays = v" />
		</div>
	</div>
</template>

<script>
import { NcSelect, NcTextField, NcPasswordField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'AppointmentSettingsTab',
	components: { NcSelect, NcTextField, NcPasswordField },
	data() {
		return {
			backend: { value: 'local', label: t('procest', 'Local (no external system)') },
			backendOptions: [
				{ value: 'local', label: t('procest', 'Local (no external system)') },
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
		saveBackend() {
			// Save via settings API
		},
	},
}
</script>

<style scoped>
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
</style>
