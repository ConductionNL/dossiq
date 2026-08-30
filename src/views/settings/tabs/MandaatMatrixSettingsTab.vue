<template>
	<div class="mandaat-settings">
		<NcNoteCard type="info">
			{{
				t(
					'dossiq',
					'The mandate matrix (Awb art. 10:3) is being delivered in the mandaat-matrix chain. This panel will host role hierarchy, Decidesk imports and waarnemer assignments.',
				)
			}}
		</NcNoteCard>

		<div class="setting-row">
			<label for="mandaat_decidesk_connection">
				{{ t('dossiq', 'Decidesk connection (openconnector)') }}
			</label>
			<NcInputField
				id="mandaat_decidesk_connection"
				v-model="decideskConnection"
				:disabled="!writable"
				placeholder="decidesk-default" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Identifier of the openconnector connection used to fetch mandateringsbesluiten from Decidesk.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="mandaat_default_extension_days">
				{{ t('dossiq', 'Default extension days for waarnemer assignments') }}
			</label>
			<NcInputField
				id="mandaat_default_extension_days"
				v-model="defaultExtensionDays"
				type="number"
				:disabled="!writable"
				placeholder="14" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Used as a hint when a waarnemer assignment is created without an explicit end date.',
					)
				}}
			</p>
		</div>

		<NcCheckboxRadioSwitch v-model="autoFinalizeApproved" :disabled="!writable">
			{{
				t('dossiq', 'Automatically activate a mandate import after approval')
			}}
		</NcCheckboxRadioSwitch>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton type="primary" :disabled="!writable || saving" @click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
			</template>
			{{
				saving
					? t('dossiq', 'Saving...')
					: t('dossiq', 'Save mandate matrix settings')
			}}
		</NcButton>

		<p class="docs-link">
			<a :href="adminDocsUrl" target="_blank" rel="noopener">
				{{ t('dossiq', 'Read the mandate matrix administrator guide') }}
			</a>
		</p>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

/**
 * Mandate matrix admin settings tab.
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
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
		const initial = loadState('dossiq', 'mandaatSettings', {})
		return {
			decideskConnection: initial.decideskConnection ?? 'decidesk-default',
			defaultExtensionDays: initial.defaultExtensionDays ?? 14,
			autoFinalizeApproved: initial.autoFinalizeApproved ?? false,
			writable: initial.writable ?? true,
			saving: false,
			error: null,
		}
	},

	computed: {
		/** @spec openspec/specs/mandaat-matrix/spec.md */
		adminDocsUrl() {
			return 'https://docs.procest.nl/user/mandate-matrix-admin'
		},
	},

	methods: {
		t,
		/**
		 * Persist the mandate matrix settings.
		 *
		 * ⚠️ This used to POST `/apps/dossiq/api/settings/mandaat`, a route
		 * dossiq never declared. Nextcloud answers an unmatched app URL with its
		 * own HTML page under HTTP 200, so `fetch` resolved, nothing threw, and
		 * every save silently vanished (procest#794). It now uses the app's own
		 * canonical settings write, which carries `#[AuthorizedAdminSetting]`.
		 *
		 * `fetch` does NOT reject on a non-2xx response, so `res.ok` has to be
		 * checked explicitly or a 403 reads exactly like a successful save.
		 *
		 * @spec openspec/specs/mandaat-matrix/spec.md
		 */
		async save() {
			this.saving = true
			this.error = null
			try {
				const res = await fetch(generateUrl('/apps/dossiq/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						mandaat_decidesk_connection: this.decideskConnection,
						mandaat_default_extension_days: String(
							Number(this.defaultExtensionDays),
						),
						mandaat_auto_finalize_approved: this.autoFinalizeApproved
							? '1'
							: '0',
					}),
				})
				if (!res.ok) {
					this.error = t('dossiq', 'Saving failed ({status})', {
						status: res.status,
					})
				}
			} catch (e) {
				this.error = e.message || t('dossiq', 'Saving failed')
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
