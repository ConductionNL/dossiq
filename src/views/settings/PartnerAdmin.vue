<template>
	<div class="partner-admin">
		<div class="partner-admin__header">
			<h2>{{ t('procest', 'Partner Organizations') }}</h2>
			<NcButton type="primary" @click="showCreateForm = true">
				{{ t('procest', 'Add partner') }}
			</NcButton>
		</div>

		<p class="partner-admin__description">
			{{ t('procest', 'Manage partner organizations (ketenpartners) for inter-organizational case collaboration.') }}
		</p>

		<!-- Partner list -->
		<div v-if="loading" class="partner-admin__loading">
			<NcLoadingIcon :size="20" />
		</div>

		<table v-else-if="partners.length > 0" class="partner-admin__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Name') }}</th>
					<th>{{ t('procest', 'OIN') }}</th>
					<th>{{ t('procest', 'Contact') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="partner in partners" :key="partner.id">
					<td>{{ partner.name }}</td>
					<td>{{ partner.oin || '-' }}</td>
					<td>{{ partner.contactEmail }}</td>
					<td>
						<span :class="partner.isActive ? 'status--active' : 'status--inactive'">
							{{ partner.isActive ? t('procest', 'Active') : t('procest', 'Inactive') }}
						</span>
					</td>
					<td>
						<NcButton @click="editPartner(partner)">
							{{ t('procest', 'Edit') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<div v-else class="partner-admin__empty">
			<p>{{ t('procest', 'No partner organizations registered yet.') }}</p>
		</div>

		<!-- Create/Edit form dialog -->
		<NcDialog
			:open="showCreateForm"
			:name="editingPartner ? t('procest', 'Edit partner') : t('procest', 'Add partner')"
			@update:open="showCreateForm = $event">
			<div class="partner-admin__form">
				<div class="form-group">
					<label>{{ t('procest', 'Organization name') }} *</label>
					<NcTextField
						:value="form.name"
						@update:value="v => form.name = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'OIN (optional)') }}</label>
					<NcTextField
						:value="form.oin"
						:placeholder="t('procest', 'Organisatie-identificatienummer')"
						@update:value="v => form.oin = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Contact email') }} *</label>
					<NcTextField
						:value="form.contactEmail"
						type="email"
						@update:value="v => form.contactEmail = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Default permission level') }}</label>
					<NcSelect
						v-model="form.defaultPermissionLevel"
						:options="permissionOptions"
						label="label"
						track-by="value" />
				</div>
			</div>
			<template #actions>
				<NcButton @click="showCreateForm = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!isFormValid" @click="savePartner">
					{{ t('procest', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcDialog, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'PartnerAdmin',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcDialog,
		NcLoadingIcon,
	},
	data() {
		return {
			loading: false,
			partners: [],
			showCreateForm: false,
			editingPartner: null,
			form: {
				name: '',
				oin: '',
				contactEmail: '',
				defaultPermissionLevel: { value: 'bekijken', label: t('procest', 'View only') },
			},
			permissionOptions: [
				{ value: 'bekijken', label: t('procest', 'View only') },
				{ value: 'bekijken_reageren', label: t('procest', 'View + Comment') },
				{ value: 'bekijken_bijdragen', label: t('procest', 'View + Contribute') },
			],
		}
	},
	computed: {
		isFormValid() {
			return this.form.name.trim().length > 0 && this.form.contactEmail.trim().length > 0
		},
	},
	methods: {
		editPartner(partner) {
			this.editingPartner = partner
			this.form = {
				name: partner.name,
				oin: partner.oin || '',
				contactEmail: partner.contactEmail,
				defaultPermissionLevel: this.permissionOptions.find(
					o => o.value === partner.defaultPermissionLevel,
				) || this.permissionOptions[0],
			}
			this.showCreateForm = true
		},
		savePartner() {
			// Emit save event for parent to handle API call
			this.showCreateForm = false
			this.editingPartner = null
			this.form = {
				name: '',
				oin: '',
				contactEmail: '',
				defaultPermissionLevel: this.permissionOptions[0],
			}
		},
	},
}
</script>

<style scoped>
.partner-admin__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.partner-admin__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.partner-admin__table {
	width: 100%;
	border-collapse: collapse;
}

.partner-admin__table th,
.partner-admin__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.partner-admin__table th {
	font-weight: bold;
	background: var(--color-background-dark);
}

.status--active {
	color: var(--color-success);
	font-weight: bold;
}

.status--inactive {
	color: var(--color-text-maxcontrast);
}

.partner-admin__form .form-group {
	margin-bottom: 12px;
}

.partner-admin__form .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.partner-admin__empty,
.partner-admin__loading {
	text-align: center;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}
</style>
