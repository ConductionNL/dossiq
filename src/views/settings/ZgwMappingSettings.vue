<template>
	<div class="zgw-mapping-settings">
		<div class="mapping-list">
			<table>
				<thead>
					<tr>
						<th>{{ t('procest', 'ZGW Resource') }}</th>
						<th>{{ t('procest', 'Status') }}</th>
						<th>{{ t('procest', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="key in resourceKeys" :key="key">
						<td>{{ key }}</td>
						<td>
							<span v-if="mappings[key] && mappings[key].enabled" class="status-enabled">
								{{ t('procest', 'Enabled') }}
							</span>
							<span v-else-if="mappings[key]" class="status-disabled">
								{{ t('procest', 'Disabled') }}
							</span>
							<span v-else class="status-unconfigured">
								{{ t('procest', 'Not configured') }}
							</span>
						</td>
						<td>
							<NcButton type="secondary" @click="editMapping(key)">
								{{ t('procest', 'Edit') }}
							</NcButton>
							<NcButton type="tertiary" @click="resetMapping(key)">
								{{ t('procest', 'Reset') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<ZgwMappingDialog
			:open="editingKey !== null"
			:resource-key="editingKey || ''"
			:mapping="editingKey ? (mappings[editingKey] || {}) : {}"
			@save="saveMapping"
			@close="editingKey = null" />

		<p v-if="saved" class="success-message">
			{{ t('procest', 'Mapping saved successfully') }}
		</p>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useZgwMappingStore } from '../../store/modules/zgwMapping.js'
import ZgwMappingDialog from '../../dialogs/ZgwMappingDialog.vue'

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
		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		resourceKeys() {
			return [
				'zaak', 'zaaktype', 'status', 'statustype',
				'resultaat', 'resultaattype', 'rol', 'roltype',
				'eigenschap', 'besluit', 'besluittype', 'informatieobjecttype',
			]
		},
	},
	async mounted() {
		await this.store.fetchMappings()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		editMapping(key) {
			this.editingKey = key
		},

		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		async saveMapping(config) {
			const result = await this.store.saveMapping(this.editingKey, config)
			if (result) {
				this.editingKey = null
				this.saved = true
				setTimeout(() => { this.saved = false }, 3000)
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		async resetMapping(key) {
			await this.store.resetMapping(key)
			this.saved = true
			setTimeout(() => { this.saved = false }, 3000)
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

.status-enabled {
	color: var(--color-success);
}

.status-disabled {
	color: var(--color-warning);
}

.status-unconfigured {
	color: var(--color-text-maxcontrast);
}

.success-message {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
