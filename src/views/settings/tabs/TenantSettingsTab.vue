<template>
	<div class="tenant-settings-tab">
		<h2>{{ t('procest', 'Tenant Management') }}</h2>

		<div class="tenant-settings-tab__actions">
			<NcButton type="primary" @click="showCreateForm = true">
				{{ t('procest', 'New Tenant') }}
			</NcButton>
		</div>

		<!-- Tenant list -->
		<table v-if="tenants.length > 0" class="tenant-settings-tab__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Name') }}</th>
					<th>{{ t('procest', 'OIN') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Users') }}</th>
					<th>{{ t('procest', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="tenant in tenants" :key="tenant.uuid || tenant.id">
					<td>{{ tenant.name }}</td>
					<td>{{ tenant.oin || '-' }}</td>
					<td>
						<span :class="tenant.isActive ? 'badge--active' : 'badge--inactive'">
							{{ tenant.isActive ? t('procest', 'Active') : t('procest', 'Inactive') }}
						</span>
					</td>
					<td>{{ tenant._usage?.users || '?' }} / {{ tenant.maxUsers || '∞' }}</td>
					<td>
						<NcButton v-if="!tenant.registerId" @click="provision(tenant)">
							{{ t('procest', 'Provision') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="tenant-settings-tab__empty">
			{{ t('procest', 'No tenants configured. This deployment is running in single-tenant mode.') }}
		</p>

		<!-- Create form dialog -->
		<NcDialog
			v-if="showCreateForm"
			:name="t('procest', 'Create Tenant')"
			@close="showCreateForm = false">
			<div class="form-group">
				<NcTextField
					:value="createForm.name"
					:label="t('procest', 'Municipality name')"
					@update:value="v => createForm.name = v" />
			</div>
			<div class="form-group">
				<NcTextField
					:value="createForm.oin"
					:label="t('procest', 'OIN (Organisatie-identificatienummer)')"
					@update:value="v => createForm.oin = v" />
			</div>
			<div class="form-group">
				<NcTextField
					:value="createForm.domain"
					:label="t('procest', 'Domain')"
					@update:value="v => createForm.domain = v" />
			</div>
			<NcButton type="primary" :disabled="!createForm.name" @click="create">
				{{ t('procest', 'Create') }}
			</NcButton>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcDialog } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { listTenants, createTenant, provisionTenant } from '../../../services/tenantApi.js'

export default {
	name: 'TenantSettingsTab',
	components: { NcButton, NcTextField, NcDialog },
	data() {
		return {
			tenants: [],
			showCreateForm: false,
			createForm: { name: '', oin: '', domain: '' },
		}
	},
	async mounted() {
		try {
			const response = await listTenants()
			this.tenants = response.tenants || []
		} catch (e) {
			// No tenants or not admin
		}
	},
	methods: {
		t,
		async create() {
			try {
				await createTenant(this.createForm)
				this.showCreateForm = false
				this.createForm = { name: '', oin: '', domain: '' }
				const response = await listTenants()
				this.tenants = response.tenants || []
			} catch (e) {
				// Handle error
			}
		},
		async provision(tenant) {
			try {
				await provisionTenant(tenant.uuid || tenant.id)
				const response = await listTenants()
				this.tenants = response.tenants || []
			} catch (e) {
				// Handle error
			}
		},
	},
}
</script>

<style scoped>
.tenant-settings-tab__table {
	width: 100%;
	border-collapse: collapse;
}

.tenant-settings-tab__table th,
.tenant-settings-tab__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.badge--active { color: var(--color-success); font-weight: 600; }
.badge--inactive { color: var(--color-error); font-weight: 600; }

.form-group { margin-bottom: 12px; }

.tenant-settings-tab__actions { margin-bottom: 16px; }
.tenant-settings-tab__empty { color: var(--color-text-maxcontrast); font-style: italic; }
</style>
