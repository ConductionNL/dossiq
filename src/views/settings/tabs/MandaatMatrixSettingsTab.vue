<template>
	<div class="mandaat-settings">
		<NcNoteCard type="info">
			{{ t('procest', 'The mandate matrix (Awb art. 10:3) is being delivered in the mandaat-matrix chain. This panel will host role hierarchy, Decidesk imports and waarnemer assignments.') }}
		</NcNoteCard>

		<div class="setting-row">
			<label for="mandaat_decidesk_connection">
				{{ t('procest', 'Decidesk connection (openconnector)') }}
			</label>
			<NcInputField
				id="mandaat_decidesk_connection"
				:value.sync="decideskConnection"
				:disabled="!writable"
				:placeholder="'decidesk-default'" />
			<p class="setting-help">
				{{ t('procest', 'Identifier of the openconnector connection used to fetch mandateringsbesluiten from Decidesk.') }}
			</p>
		</div>

		<div class="setting-row">
			<label for="mandaat_default_extension_days">
				{{ t('procest', 'Default extension days for waarnemer assignments') }}
			</label>
			<NcInputField
				id="mandaat_default_extension_days"
				:value.sync="defaultExtensionDays"
				type="number"
				:disabled="!writable"
				:placeholder="'14'" />
			<p class="setting-help">
				{{ t('procest', 'Used as a hint when a waarnemer assignment is created without an explicit end date.') }}
			</p>
		</div>

		<NcCheckboxRadioSwitch
			:checked.sync="autoFinalizeApproved"
			:disabled="!writable">
			{{ t('procest', 'Automatically activate a mandate import after approval') }}
		</NcCheckboxRadioSwitch>

		<NcButton
			type="primary"
			:disabled="!writable || saving"
			@click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
			</template>
			{{ saving ? t('procest', 'Saving...') : t('procest', 'Save mandate matrix settings') }}
		</NcButton>

		<p class="docs-link">
			<a :href="adminDocsUrl" target="_blank" rel="noopener">
				{{ t('procest', 'Read the mandate matrix administrator guide') }}
			</a>
		</p>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

/**
 * Mandate matrix admin settings tab.
 *
 * @spec openspec/changes/mandaat-matrix-07-admin-ui/proposal.md
 */
export default {
	name: 'MandaatMatrixSettingsTab',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
	},
	data() {
		const initial = loadState('procest', 'mandaatSettings', {})
		return {
			decideskConnection: initial.decideskConnection ?? 'decidesk-default',
			defaultExtensionDays: initial.defaultExtensionDays ?? 14,
			autoFinalizeApproved: initial.autoFinalizeApproved ?? false,
			writable: initial.writable ?? true,
			saving: false,
		}
	},
	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/proposal.md */
		adminDocsUrl() {
			return 'https://docs.procest.nl/user/mandate-matrix-admin'
		},
	},
	methods: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/proposal.md */
		async save() {
			this.saving = true
			try {
				await fetch(generateUrl('/apps/procest/api/settings/mandaat'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						decideskConnection: this.decideskConnection,
						defaultExtensionDays: Number(this.defaultExtensionDays),
						autoFinalizeApproved: this.autoFinalizeApproved,
					}),
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.mandaat-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 600px;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.setting-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.docs-link {
	margin-top: 8px;
	font-size: 0.9em;
}
</style>
