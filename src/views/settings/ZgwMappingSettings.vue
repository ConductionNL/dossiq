<template>
	<div class="zgw-mapping-settings">
		<div class="mapping-list">
			<table>
				<thead>
					<tr>
						<th scope="col">{{ t('dossiq', 'ZGW Resource') }}</th>
						<th scope="col">{{ t('dossiq', 'Status') }}</th>
						<th scope="col">{{ t('dossiq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="key in resourceKeys" :key="key">
						<td>{{ resourceName(key) }}</td>
						<td>
							<span
								v-if="mappings[key] && mappings[key].enabled"
								class="cn-status-badge cn-status-badge--success">
								{{ t('dossiq', 'Enabled') }}
							</span>
							<span v-else-if="mappings[key]" class="cn-status-badge cn-status-badge--warning">
								{{ t('dossiq', 'Disabled') }}
							</span>
							<span v-else class="cn-status-badge cn-status-badge--default">
								{{ t('dossiq', 'Not configured') }}
							</span>
						</td>
						<td>
							<div class="zgw-mapping-settings__actions">
								<NcButton type="secondary" @click="editMapping(key)">
									{{ t('dossiq', 'Edit') }}
								</NcButton>
								<NcButton type="tertiary" @click="resetMapping(key)">
									{{ t('dossiq', 'Reset') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<ZgwMappingDialog
			:open="editingKey !== null"
			:resourceKey="editingKey || ''"
			:mapping="editingKey ? mappings[editingKey] || {} : {}"
			@save="saveMapping"
			@close="editingKey = null" />

		<p v-if="saved" class="success-message">
			{{ t('dossiq', 'Mapping saved successfully') }}
		</p>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ZgwMappingDialog from '../../dialogs/ZgwMappingDialog.vue'
import { useZgwMappingStore } from '../../store/modules/zgwMapping.js'
import { zgwResourceName } from './zgwResourceNames.js'

export default {
	name: 'ZgwMappingSettings',
	components: {
		NcButton,
		ZgwMappingDialog,
	},

	data() {
		return {
			editingKey: null,
			saved: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		store() {
			return useZgwMappingStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		mappings() {
			return this.store.mappings
		},

		/**
		 * The resource keys the server actually holds mappings for.
		 *
		 * This used to be a hardcoded list of twelve, and it was a third copy
		 * of an inventory the backend already owns. It named `case` and
		 * `case_type` for mappings stored as `zaak` and `caseType`, so those
		 * two rows read "Not configured" against a configured mapping and both
		 * buttons addressed a resource key that does not exist. The other
		 * fourteen mappings had no row at all. Reading the API's own keys is
		 * what stops the list drifting again.
		 *
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		resourceKeys() {
			return Object.keys(this.mappings)
		},
	},

	async mounted() {
		await this.store.fetchMappings()
	},

	methods: {
		/**
		 * A resource key as a person reads it ("Zaaktype", not "caseType").
		 *
		 * @param {string} key The stored resource key.
		 * @return {string} The name.
		 * @spec openspec/specs/admin-settings/spec.md#requirement-a-zgw-mapping-row-names-its-resource
		 */
		resourceName(key) {
			return zgwResourceName(key, translate)
		},

		/**
		 * @param {string} key The key.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		editMapping(key) {
			this.editingKey = key
		},

		/**
		 * @param {object} config The config.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		async saveMapping(config) {
			const result = await this.store.saveMapping(this.editingKey, config)
			if (result) {
				this.editingKey = null
				this.saved = true
				setTimeout(() => {
					this.saved = false
				}, 3000)
			}
		},

		/**
		 * @param {string} key The key.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		async resetMapping(key) {
			await this.store.resetMapping(key)
			this.saved = true
			setTimeout(() => {
				this.saved = false
			}, 3000)
		},
	},
}
</script>

<style scoped>
.zgw-mapping-settings table {
	width: 100%;
	border-collapse: collapse;
}

.zgw-mapping-settings th,
.zgw-mapping-settings td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.zgw-mapping-settings th {
	font-weight: bold;
}

.zgw-mapping-settings__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

/* The state reads as a pill and the row's buttons are 34px, as board
   DqBeheerGeavanceerd "ZGW-API-koppeling" draws them. */
.zgw-mapping-settings__actions :deep(.button-vue) {
	min-height: 34px;
	height: 34px;
}

.success-message {
	color: var(--color-success-text);
	margin-top: 12px;
}
</style>
