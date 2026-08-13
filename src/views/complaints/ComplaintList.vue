<template>
	<div class="complaint-list">
		<header class="complaint-list__header">
			<h2>{{ t('procest', 'Complaints') }}</h2>
			<button type="button" class="primary" @click="openCreate">
				{{ t('procest', 'New complaint') }}
			</button>
		</header>

		<div class="complaint-list__filters">
			<input
				v-model="search"
				type="search"
				:aria-label="t('procest', 'Search complaints')"
				class="complaint-list__search"
				:placeholder="t('procest', 'Search complaints…')" />
			<select v-model="statusFilter" class="complaint-list__status">
				<option value="">
					{{ t('procest', 'Any status') }}
				</option>
				<option value="ingediend">
					{{ t('procest', 'Submitted') }}
				</option>
				<option value="in-behandeling">
					{{ t('procest', 'In progress') }}
				</option>
				<option value="afgehandeld">
					{{ t('procest', 'Closed') }}
				</option>
			</select>
		</div>

		<div v-if="loading" class="complaint-list__empty">
			{{ t('procest', 'Loading complaints…') }}
		</div>
		<div v-else-if="filtered.length === 0" class="complaint-list__empty">
			{{ t('procest', 'No complaints found.') }}
		</div>
		<table v-else class="complaint-list__table">
			<thead>
				<tr>
					<th scope="col">{{ t('procest', 'Title') }}</th>
					<th scope="col">{{ t('procest', 'Category') }}</th>
					<th scope="col">{{ t('procest', 'Status') }}</th>
					<th scope="col">{{ t('procest', 'Deadline') }}</th>
					<th scope="col">{{ t('procest', 'Handler') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in filtered"
					:key="row.id"
					class="complaint-list__row"
					@click="openDetail(row.id)">
					<td>{{ row.title || '-' }}</td>
					<td>{{ row.category || '-' }}</td>
					<td>
						<span
							class="complaint-list__badge"
							:class="'badge-' + row.status">
							{{ row.status }}
						</span>
					</td>
					<td>{{ row.deadline || '-' }}</td>
					<td>{{ row.handler || '-' }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
/**
 * Complaint list view.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
 */
export default {
	name: 'ComplaintList',

	data() {
		return {
			loading: true,
			rows: [],
			search: '',
			statusFilter: '',
		}
	},

	computed: {
		/**
		 * Filtered complaint rows.
		 *
		 * @return {Array} Rows.
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		filtered() {
			let out = this.rows
			if (this.statusFilter) {
				out = out.filter((r) => r.status === this.statusFilter)
			}
			if (this.search) {
				const q = this.search.toLowerCase()
				out = out.filter(
					(r) =>
						(r.title || '').toLowerCase().includes(q)
						|| (r.category || '').toLowerCase().includes(q),
				)
			}
			return out
		},
	},

	mounted() {
		this.fetch()
	},

	methods: {
		/**
		 * Fetch the complaint list.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		async fetch() {
			this.loading = true
			try {
				const res = await fetch(
					OC.generateUrl('/apps/procest/api/complaints'),
					{
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (res.ok) {
					const body = await res.json()
					this.rows = Array.isArray(body) ? body : body.items || []
				}
			} catch (e) {
				this.rows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the create dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		openCreate() {
			this.$emit('open-create')
		},

		/**
		 * Open the detail view.
		 *
		 * @param {string} id Complaint id.
		 * @return {void}
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		openDetail(id) {
			this.$emit('open-detail', id)
		},
	},
}
</script>

<style scoped>
.complaint-list {
	padding: var(--default-grid-baseline, 8px);
}

.complaint-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.complaint-list__filters {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	margin: var(--default-grid-baseline, 8px) 0;
}

.complaint-list__search {
	flex: 1;
}

.complaint-list__empty {
	padding: calc(var(--default-grid-baseline, 8px) * 4);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.complaint-list__table {
	width: 100%;
	border-collapse: collapse;
}

.complaint-list__table th,
.complaint-list__table td {
	padding: var(--default-grid-baseline, 8px);
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.complaint-list__row {
	cursor: pointer;
}

.complaint-list__row:hover {
	background: var(--color-background-hover);
}

.complaint-list__badge {
	padding: 2px 6px;
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
}
</style>
