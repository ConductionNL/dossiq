<template>
	<div class="archief-settings">
		<NcNoteCard type="info">
			{{ t('procest', 'The archival pipeline (e-Depot, GiHandover/MDTO) is being delivered in the archief-edepot-handover chain. This panel will host retention rules, dashboard, batch controls and proof viewer.') }}
		</NcNoteCard>

		<div class="setting-row">
			<label for="archief_max_concurrent">
				{{ t('procest', 'Maximum concurrent SIP submissions') }}
			</label>
			<NcInputField
				id="archief_max_concurrent"
				:value.sync="maxConcurrent"
				type="number"
				:disabled="!writable"
				:placeholder="'5'" />
			<p class="setting-help">
				{{ t('procest', 'Caps how many SIP bundles are transmitted in parallel during batch runs.') }}
			</p>
		</div>

		<div class="setting-row">
			<label for="archief_retry_attempts">
				{{ t('procest', 'Maximum retry attempts per submission') }}
			</label>
			<NcInputField
				id="archief_retry_attempts"
				:value.sync="retryAttempts"
				type="number"
				:disabled="!writable"
				:placeholder="'5'" />
			<p class="setting-help">
				{{ t('procest', 'Number of times the e-Depot submission is retried before being marked failed.') }}
			</p>
		</div>

		<div class="setting-row">
			<label for="archief_edepot_adapter">
				{{ t('procest', 'Active e-Depot adapter') }}
			</label>
			<NcInputField
				id="archief_edepot_adapter"
				:value.sync="adapter"
				:disabled="!writable"
				:placeholder="'openconnector-default'" />
			<p class="setting-help">
				{{ t('procest', 'Identifier of the EDepotAdapter implementation used for outbound submissions.') }}
			</p>
		</div>

		<NcButton
			type="primary"
			:disabled="!writable || saving"
			@click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
			</template>
			{{ saving ? t('procest', 'Saving...') : t('procest', 'Save archival settings') }}
		</NcButton>

		<p class="docs-link">
			<a :href="adminDocsUrl" target="_blank" rel="noopener">
				{{ t('procest', 'Read the archief & e-Depot administrator guide') }}
			</a>
		</p>
	</div>
</template>

<script>
import { NcButton, NcInputField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

/**
 * Archief & e-Depot admin settings tab.
 *
 * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/proposal.md
 */
export default {
	name: 'ArchiefSettingsTab',
	components: { NcButton, NcInputField, NcLoadingIcon, NcNoteCard },
	data() {
		const initial = loadState('procest', 'archiefSettings', {})
		return {
			maxConcurrent: initial.maxConcurrent ?? 5,
			retryAttempts: initial.retryAttempts ?? 5,
			adapter: initial.adapter ?? 'openconnector-default',
			writable: initial.writable ?? true,
			saving: false,
		}
	},
	computed: {
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/proposal.md */
		adminDocsUrl() {
			return 'https://docs.procest.nl/admin/archief-edepot'
		},
	},
	methods: {
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/proposal.md */
		async save() {
			this.saving = true
			try {
				await fetch(generateUrl('/apps/procest/api/settings/archief'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						maxConcurrent: Number(this.maxConcurrent),
						retryAttempts: Number(this.retryAttempts),
						adapter: this.adapter,
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
.archief-settings {
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
