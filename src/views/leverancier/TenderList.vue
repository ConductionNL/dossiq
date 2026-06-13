<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Tender list — sortable, filterable, badge-coloured table.
  -
  - Binds to GET /apps/procest/api/leverancier-portaal/tenders.
  - Status filter values come from `TenderViewModelService::badgeColor()`
  - (chain member 06).
  -
  - @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
  -->
<template>
	<div class="lz-tenders" data-testid="leverancier-tender-list">
		<header class="lz-toolbar">
			<h1>{{ t('procest', 'Aanbestedingen') }}</h1>
			<div class="lz-filters">
				<label for="lz-status" class="lz-filter-label">
					{{ t('procest', 'Status') }}
				</label>
				<select id="lz-status"
					v-model="statusFilter"
					data-testid="leverancier-tender-status-filter"
					@change="reload">
					<option value="">
						{{ t('procest', 'Alle') }}
					</option>
					<option value="submitted">
						{{ t('procest', 'Ingediend') }}
					</option>
					<option value="evaluating">
						{{ t('procest', 'Evaluatie') }}
					</option>
					<option value="awarded">
						{{ t('procest', 'Gegund') }}
					</option>
					<option value="rejected">
						{{ t('procest', 'Afgewezen') }}
					</option>
					<option value="withdrawn">
						{{ t('procest', 'Ingetrokken') }}
					</option>
				</select>

				<label for="lz-search" class="lz-filter-label">
					{{ t('procest', 'Zoek') }}
				</label>
				<input id="lz-search"
					v-model="searchInput"
					type="text"
					data-testid="leverancier-tender-search"
					:placeholder="t('procest', 'Onderwerp of kenmerk')"
					class="lz-search-input">
			</div>
		</header>

		<div v-if="loading" data-testid="lz-loading" class="lz-state">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-else-if="error"
			data-testid="lz-error"
			class="lz-state lz-state--error"
			role="alert">
			{{ error }}
		</div>

		<table v-else-if="visibleRows.length" class="lz-table" data-testid="leverancier-tender-table">
			<thead>
				<tr>
					<th scope="col" @click="setSort('kenmerk')">
						{{ t('procest', 'Kenmerk') }} <span v-if="sortKey === 'kenmerk'">{{ sortArrow }}</span>
					</th>
					<th scope="col" @click="setSort('onderwerp')">
						{{ t('procest', 'Onderwerp') }} <span v-if="sortKey === 'onderwerp'">{{ sortArrow }}</span>
					</th>
					<th scope="col" @click="setSort('status')">
						{{ t('procest', 'Status') }} <span v-if="sortKey === 'status'">{{ sortArrow }}</span>
					</th>
					<th scope="col" @click="setSort('publishedOn')">
						{{ t('procest', 'Gepubliceerd') }} <span v-if="sortKey === 'publishedOn'">{{ sortArrow }}</span>
					</th>
					<th scope="col">
						{{ t('procest', 'Actie') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="t in visibleRows" :key="t.id || t.uuid">
					<td>{{ t.kenmerk || (t.id || '').slice(0, 8) }}</td>
					<td>{{ t.onderwerp || t.subject || '—' }}</td>
					<td>
						<span class="lz-badge"
							:class="'lz-badge--' + (t.badgeColor || 'gray')">
							{{ t.status }}
						</span>
					</td>
					<td>{{ t.publishedOn || '—' }}</td>
					<td>
						<router-link :to="`/leverancier/tenders/${t.id || t.uuid}`"
							class="lz-link"
							data-testid="leverancier-tender-row-link">
							{{ t('procest', 'Bekijk') }}
						</router-link>
					</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="lz-empty" data-testid="lz-empty">
			{{ t('procest', 'Geen aanbestedingen gevonden.') }}
		</p>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { listTenders } from '../../services/leverancierApi.js'

export default {
	name: 'TenderList',
	components: { NcLoadingIcon },
	data() {
		return {
			rows: [],
			loading: false,
			error: null,
			statusFilter: '',
			searchInput: '',
			sortKey: 'publishedOn',
			sortDir: 'desc',
		}
	},
	computed: {
		supplierRef() {
			return (this.$route.query && this.$route.query.supplierRef) || ''
		},
		sortArrow() {
			return this.sortDir === 'asc' ? '↑' : '↓'
		},
		visibleRows() {
			const q = (this.searchInput || '').trim().toLowerCase()
			let r = this.rows
			if (q) {
				r = r.filter(t => {
					const hay = `${t.kenmerk || ''} ${t.onderwerp || t.subject || ''}`.toLowerCase()
					return hay.includes(q)
				})
			}
			const key = this.sortKey
			const dir = this.sortDir === 'asc' ? 1 : -1
			return [...r].sort((a, b) => {
				const av = (a[key] || '') + ''
				const bv = (b[key] || '') + ''
				return av.localeCompare(bv) * dir
			})
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			if (!this.supplierRef) {
				this.rows = []
				return
			}
			this.loading = true
			this.error = null
			try {
				const r = await listTenders(this.supplierRef, this.statusFilter || undefined)
				this.rows = (r && r.items) || []
			} catch (e) {
				this.error = this.t('procest', 'Kon aanbestedingen niet laden.')
			} finally {
				this.loading = false
			}
		},
		setSort(key) {
			if (this.sortKey === key) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortKey = key
				this.sortDir = 'asc'
			}
		},
	},
}
</script>

<style scoped>
.lz-tenders { padding: 20px; max-width: 1200px; margin: 0 auto; }
.lz-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: 12px; margin-bottom: 16px; }
.lz-filters { display: flex; gap: 12px; align-items: end; }
.lz-filter-label { font-size: 12px; color: var(--color-text-maxcontrast, #555); margin-right: 4px; }
.lz-search-input { padding: 6px 10px; border: 1px solid var(--color-border-dark, #aaa); border-radius: 4px; min-width: 220px; }
.lz-state { padding: 40px 20px; text-align: center; }
.lz-state--error { color: var(--color-error, #c00); }
.lz-table { width: 100%; border-collapse: collapse; }
.lz-table th, .lz-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border, #ddd); }
.lz-table th { cursor: pointer; font-weight: 600; user-select: none; }
.lz-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; color: #fff; }
.lz-badge--gray   { background: #888; }
.lz-badge--blue   { background: #0082c9; }
.lz-badge--green  { background: #46ba61; }
.lz-badge--orange { background: #ed8d04; }
.lz-badge--red    { background: #c4474b; }
.lz-link { color: var(--color-primary, #0082c9); text-decoration: none; }
.lz-link:hover { text-decoration: underline; }
.lz-empty { padding: 40px 20px; text-align: center; color: var(--color-text-maxcontrast, #555); }
</style>
