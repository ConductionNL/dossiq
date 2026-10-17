<template>
	<CnSettingsSection
		id="section-configuration"
		:name="t('dossiq', 'Configuration')"
		:description="
			t(
				'dossiq',
				'In which register Dossiq keeps cases, and with which schemas.',
			)
		"
		docUrl="https://dossiq.conduction.nl/docs/intro"
		:loading="loading">
		<div class="dossiq-configuration">
			<div class="dossiq-configuration__field dossiq-configuration__register">
				<NcSelect
					:inputLabel="t('dossiq', 'Register')"
					:options="registerOptions"
					:modelValue="optionFor(registerOptions, form.register)"
					:loading="loadingRegisters"
					:clearable="false"
					@update:modelValue="onRegister" />
			</div>
			<div class="dossiq-configuration__schemas">
				<div
					v-for="field in schemaFields"
					:key="field.key"
					class="dossiq-configuration__field">
					<NcSelect
						:inputLabel="field.label"
						:options="schemaOptions"
						:modelValue="optionFor(schemaOptions, form[field.key])"
						:loading="loadingSchemas"
						:disabled="!form.register"
						:clearable="false"
						@update:modelValue="
							(option) => (form[field.key] = option ? option.id : '')
						" />
				</div>
			</div>
		</div>

		<div class="dossiq-configuration__footer">
			<p v-if="saved" class="success-message">
				{{ t('dossiq', 'Configuration saved') }}
			</p>
			<NcButton variant="primary" @click="save">
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'

/**
 * The register and the schemas dossiq stores its records in, picked by name.
 * The stored values stay the OpenRegister ids the app config has always held.
 */
export default {
	name: 'Settings',
	components: {
		CnSettingsSection,
		NcButton,
		NcSelect,
	},

	data() {
		return {
			form: {
				register: '',
				case_schema: '',
				status_schema: '',
				role_schema: '',
				result_schema: '',
				decision_schema: '',
				case_type_schema: '',
				status_type_schema: '',
			},

			registers: [],
			schemas: [],
			loadingRegisters: false,
			loadingSchemas: false,
			saved: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		settingsStore() {
			return useSettingsStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		loading() {
			return this.settingsStore.isLoading
		},

		/**
		 * The schema settings, in the order the board draws them.
		 *
		 * @return {Array<{key: string, label: string}>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		schemaFields() {
			return [
				{ key: 'case_schema', label: this.t('dossiq', 'Case schema') },
				{
					key: 'case_type_schema',
					label: this.t('dossiq', 'Case type schema'),
				},
				{ key: 'status_schema', label: this.t('dossiq', 'Status schema') },
				{ key: 'role_schema', label: this.t('dossiq', 'Role schema') },
				{ key: 'result_schema', label: this.t('dossiq', 'Result schema') },
				{
					key: 'decision_schema',
					label: this.t('dossiq', 'Decision schema'),
				},
				{
					key: 'status_type_schema',
					label: this.t('dossiq', 'Status type schema'),
				},
			]
		},

		/**
		 * The registers as select options.
		 *
		 * @return {Array<{id: number, label: string}>} The options.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		registerOptions() {
			return this.registers.map((register) => ({
				id: register.id,
				label: register.title || register.slug || String(register.id),
			}))
		},

		/**
		 * The chosen register's schemas as select options.
		 *
		 * @return {Array<{id: number, label: string}>} The options.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		schemaOptions() {
			return this.schemas.map((schema) => ({
				id: schema.id,
				label: schema.title || schema.slug || String(schema.id),
			}))
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async mounted() {
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.form = { ...this.form, ...config }
		}
		await this.loadRegisters()
		await this.loadSchemas()
	},

	methods: {
		/**
		 * The option that holds a stored id, or a bare one while the options load.
		 *
		 * @param {Array<{id: number, label: string}>} options The options.
		 * @param {string|number} id The stored id.
		 * @return {{id: number|string, label: string}|null} The option, or null when nothing is stored.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		optionFor(options, id) {
			if (id === '' || id === null || id === undefined) {
				return null
			}
			return (
				options.find((option) => String(option.id) === String(id)) ?? {
					id,
					label: String(id),
				}
			)
		},

		/**
		 * Read the registers OpenRegister holds.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async loadRegisters() {
			this.loadingRegisters = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/registers'),
					{
						params: { _limit: 500 },
					},
				)
				this.registers = response.data?.results ?? []
			} catch {
				this.registers = []
			} finally {
				this.loadingRegisters = false
			}
		},

		/**
		 * Read the schemas of the chosen register.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async loadSchemas() {
			if (!this.form.register) {
				this.schemas = []
				return
			}
			this.loadingSchemas = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/registers/${encodeURIComponent(this.form.register)}/schemas`,
					),
				)
				this.schemas = response.data?.results ?? []
			} catch {
				this.schemas = []
			} finally {
				this.loadingSchemas = false
			}
		},

		/**
		 * Another register was picked: its schemas become the options.
		 *
		 * @param {{id: number}|null} option The picked register.
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async onRegister(option) {
			this.form.register = option ? option.id : ''
			await this.loadSchemas()
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async save() {
			this.saved = false
			const result = await this.settingsStore.saveSettings(this.form)
			if (result) {
				this.saved = true
				setTimeout(() => {
					this.saved = false
				}, 3000)
			}
		},
	},
}
</script>

<style scoped>
.dossiq-configuration {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.dossiq-configuration__field {
	display: block;
	min-width: 0;
}

.dossiq-configuration__field :deep(.v-select.select) {
	width: 100%;
	min-width: 0;
}

.dossiq-configuration__register {
	max-width: 50%;
}

.dossiq-configuration__schemas {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
}

.dossiq-configuration__footer {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 12px;
	margin-top: 12px;
}

.success-message {
	color: var(--color-success-text);
}
</style>
