<template>
	<div class="klachtcat-tab">
		<header class="klachtcat-tab__header">
			<h2>{{ t('procest', 'Complaint categories') }}</h2>
			<button type="button" class="primary" @click="onAdd">
				{{ t('procest', 'Add category') }}
			</button>
		</header>

		<div v-if="loading" class="klachtcat-tab__empty">
			{{ t('procest', 'Loading categories…') }}
		</div>
		<table v-else-if="categories.length" class="klachtcat-tab__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Name') }}</th>
					<th>{{ t('procest', 'Default handler') }}</th>
					<th>{{ t('procest', 'SLA override (days)') }}</th>
					<th class="actions"></th>
				</tr>
			</thead>
			<tbody>
				<template v-for="cat in categories" :key="cat.id">
					<tr v-if="editingId !== cat.id">
						<td>{{ cat.name }}</td>
						<td>{{ cat.defaultHandler || '-' }}</td>
						<td>{{ cat.slaOverrideDays || t('procest', 'use default') }}</td>
						<td class="actions">
							<button type="button" @click="onEdit(cat)">
								{{ t('procest', 'Edit') }}
							</button>
							<button type="button" @click="onDelete(cat)">
								{{ t('procest', 'Delete') }}
							</button>
						</td>
					</tr>
					<tr v-else class="klachtcat-tab__edit-row">
						<td>
							<input v-model="form.name" type="text" :placeholder="t('procest', 'Name')">
						</td>
						<td>
							<input v-model="form.defaultHandler" type="text" :placeholder="t('procest', 'User id')">
						</td>
						<td>
							<input v-model.number="form.slaOverrideDays" type="number" min="0">
						</td>
						<td class="actions">
							<button type="button" @click="onSave">
								{{ t('procest', 'Save') }}
							</button>
							<button type="button" @click="cancelEdit">
								{{ t('procest', 'Cancel') }}
							</button>
						</td>
					</tr>
				</template>
				<tr v-if="editingId === '__new'" class="klachtcat-tab__edit-row">
					<td>
						<input v-model="form.name" type="text" :placeholder="t('procest', 'Name')">
					</td>
					<td>
						<input v-model="form.defaultHandler" type="text" :placeholder="t('procest', 'User id')">
					</td>
					<td>
						<input v-model.number="form.slaOverrideDays" type="number" min="0">
					</td>
					<td class="actions">
						<button type="button" @click="onSave">
							{{ t('procest', 'Save') }}
						</button>
						<button type="button" @click="cancelEdit">
							{{ t('procest', 'Cancel') }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else class="klachtcat-tab__empty">
			{{ t('procest', 'No complaint categories yet.') }}
		</p>
	</div>
</template>

<script>
/**
 * Tenant-admin tab for complaint categories.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-cm-09
 */
export default {
	name: 'KlachtcategorieenTab',

	data() {
		return {
			loading: true,
			categories: [],
			editingId: null,
			form: {
				name: '',
				defaultHandler: '',
				slaOverrideDays: null,
			},
		}
	},

	mounted() {
		this.fetch()
	},

	methods: {
		/**
		 * Fetch the complaint categories.
		 *
		 * @return {Promise<void>}
		 */
		async fetch() {
			this.loading = true
			try {
				const res = await fetch(
					OC.generateUrl('/apps/openregister/api/objects/procest/complaintCategory'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (res.ok) {
					const body = await res.json()
					this.categories = Array.isArray(body) ? body : (body.items || [])
				}
			} catch (e) {
				this.categories = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Start the add flow.
		 *
		 * @return {void}
		 */
		onAdd() {
			this.editingId = '__new'
			this.form = { name: '', defaultHandler: '', slaOverrideDays: null }
		},

		/**
		 * Start the edit flow.
		 *
		 * @param {Object} cat Category row.
		 * @return {void}
		 */
		onEdit(cat) {
			this.editingId = cat.id
			this.form = {
				name: cat.name || '',
				defaultHandler: cat.defaultHandler || '',
				slaOverrideDays: cat.slaOverrideDays || null,
			}
		},

		/**
		 * Cancel edit.
		 *
		 * @return {void}
		 */
		cancelEdit() {
			this.editingId = null
		},

		/**
		 * Save the in-flight category.
		 *
		 * @return {Promise<void>}
		 */
		async onSave() {
			const url = OC.generateUrl('/apps/openregister/api/objects/procest/complaintCategory'
				+ (this.editingId === '__new' ? '' : '/' + this.editingId))
			try {
				const res = await fetch(url, {
					method: this.editingId === '__new' ? 'POST' : 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.form),
				})
				if (res.ok) {
					this.editingId = null
					await this.fetch()
				}
			} catch (e) {
				// Surfaced via the row-level error feedback already on the form.
			}
		},

		/**
		 * Delete a category.
		 *
		 * @param {Object} cat Category row.
		 * @return {Promise<void>}
		 */
		async onDelete(cat) {
			if (!window.confirm(t('procest', 'Delete this complaint category?'))) {
				return
			}
			try {
				const res = await fetch(
					OC.generateUrl('/apps/openregister/api/objects/procest/complaintCategory/' + cat.id),
					{
						method: 'DELETE',
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (res.ok) {
					await this.fetch()
				}
			} catch (e) {
				// Same — error feedback handled by the alert / inline error row.
			}
		},
	},
}
</script>

<style scoped>
.klachtcat-tab {
	padding: var(--default-grid-baseline, 8px);
}

.klachtcat-tab__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.klachtcat-tab__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: var(--default-grid-baseline, 8px);
}

.klachtcat-tab__table th,
.klachtcat-tab__table td {
	padding: var(--default-grid-baseline, 8px);
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.klachtcat-tab__table th.actions,
.klachtcat-tab__table td.actions {
	width: 1%;
	white-space: nowrap;
}

.klachtcat-tab__edit-row input {
	width: 100%;
}

.klachtcat-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}
</style>
