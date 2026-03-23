<template>
	<div v-if="isPlatformAdmin && tenants.length > 1" class="tenant-switcher">
		<NcSelect
			v-model="currentTenant"
			:options="tenants"
			label="name"
			track-by="slug"
			:placeholder="t('procest', 'Switch tenant...')"
			@input="switchTenant" />
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { listTenants, getCurrentTenant } from '../../../services/tenantApi.js'

export default {
	name: 'TenantSwitcher',
	components: { NcSelect },
	data() {
		return {
			tenants: [],
			currentTenant: null,
			isPlatformAdmin: false,
		}
	},
	async mounted() {
		try {
			const current = await getCurrentTenant()
			this.currentTenant = current.tenant
			const all = await listTenants()
			this.tenants = all.tenants || []
			this.isPlatformAdmin = this.tenants.length > 0
		} catch (e) {
			// Not admin or single-tenant
		}
	},
	methods: {
		t,
		switchTenant(tenant) {
			if (tenant) {
				window.location.reload()
			}
		},
	},
}
</script>

<style scoped>
.tenant-switcher {
	min-width: 200px;
}
</style>
