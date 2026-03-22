<template>
	<div class="voorstel-list">
		<div class="voorstel-list__header">
			<h2>{{ t('procest', 'B&W Voorstellen') }}</h2>
			<NcButton type="primary" @click="showCreateDialog = true">
				{{ t('procest', 'Nieuw voorstel') }}
			</NcButton>
		</div>

		<!-- Filter tabs -->
		<div class="voorstel-list__tabs">
			<button
				v-for="tab in tabs"
				:key="tab.key"
				class="voorstel-list__tab"
				:class="{ 'voorstel-list__tab--active': activeTab === tab.key }"
				@click="activeTab = tab.key">
				{{ tab.label }} ({{ tab.count }})
			</button>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="filteredVoorstellen.length === 0"
			:name="t('procest', 'Geen actieve voorstellen')"
			:description="t('procest', 'Er zijn geen voorstellen die aan de huidige filter voldoen')">
			<template #icon>
				<FileDocumentEditOutline :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th @click="sortBy('onderwerp')">
							{{ t('procest', 'Onderwerp') }}
						</th>
						<th @click="sortBy('type')">
							{{ t('procest', 'Type') }}
						</th>
						<th @click="sortBy('status')">
							{{ t('procest', 'Status') }}
						</th>
						<th>{{ t('procest', 'Stap') }}</th>
						<th>{{ t('procest', 'Wacht op') }}</th>
						<th @click="sortBy('_self.updated')">
							{{ t('procest', 'Dagen in stap') }}
						</th>
						<th>{{ t('procest', 'Steller') }}</th>
						<th>{{ t('procest', 'Acties') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="voorstel in filteredVoorstellen"
						:key="voorstel.id"
						class="voorstel-list__row"
						:class="{ 'voorstel-list__row--overdue': isOverdue(voorstel) }"
						@click="openVoorstel(voorstel)">
						<td>{{ voorstel.onderwerp }}</td>
						<td>
							<span class="voorstel-list__type-badge">
								{{ formatType(voorstel.type) }}
							</span>
						</td>
						<td>
							<span class="voorstel-list__status-badge" :class="`voorstel-list__status-badge--${voorstel.status}`">
								{{ formatStatus(voorstel.status) }}
							</span>
						</td>
						<td>{{ formatStepProgress(voorstel) }}</td>
						<td>{{ getWaitingActor(voorstel) }}</td>
						<td>
							<span :class="{ 'voorstel-list__overdue-text': isOverdue(voorstel) }">
								{{ getDaysInStep(voorstel) }}
							</span>
						</td>
						<td>{{ voorstel.steller }}</td>
						<td @click.stop>
							<NcButton
								v-if="isOverdue(voorstel)"
								type="tertiary"
								:aria-label="t('procest', 'Herinnering sturen')"
								@click="sendReminder(voorstel)">
								<template #icon>
									<BellRing :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<VoorstelCreateDialog
			v-if="showCreateDialog"
			@close="showCreateDialog = false"
			@created="onVoorstelCreated" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import BellRing from 'vue-material-design-icons/BellRing.vue'
import VoorstelCreateDialog from './components/VoorstelCreateDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'

const STATUS_LABELS = {
	concept: 'Concept',
	in_parafering: 'In parafering',
	ter_accordering: 'Ter accordering',
	geaccordeerd: 'Geaccordeerd',
	aangeboden: 'Aangeboden',
	besloten: 'Besloten',
	gearchiveerd: 'Gearchiveerd',
	teruggestuurd: 'Teruggestuurd',
}

const TYPE_LABELS = {
	dt_advies: 'DT-advies',
	collegeadvies: 'Collegeadvies',
	raadsvoorstel: 'Raadsvoorstel',
}

const OVERDUE_THRESHOLD_DAYS = 3

export default {
	name: 'VoorstelList',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		FileDocumentEditOutline,
		BellRing,
		VoorstelCreateDialog,
	},
	data() {
		return {
			loading: true,
			voorstellen: [],
			activeTab: 'active',
			sortField: 'onderwerp',
			sortAsc: true,
			showCreateDialog: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		tabs() {
			const active = this.voorstellen.filter(v => !['besloten', 'gearchiveerd'].includes(v.status))
			const completed = this.voorstellen.filter(v => ['besloten', 'gearchiveerd'].includes(v.status))
			return [
				{ key: 'active', label: t('procest', 'Actief'), count: active.length },
				{ key: 'completed', label: t('procest', 'Afgerond'), count: completed.length },
				{ key: 'all', label: t('procest', 'Alle'), count: this.voorstellen.length },
			]
		},
		filteredVoorstellen() {
			let list = [...this.voorstellen]
			if (this.activeTab === 'active') {
				list = list.filter(v => !['besloten', 'gearchiveerd'].includes(v.status))
			} else if (this.activeTab === 'completed') {
				list = list.filter(v => ['besloten', 'gearchiveerd'].includes(v.status))
			}
			list.sort((a, b) => {
				const aVal = this.getSortValue(a)
				const bVal = this.getSortValue(b)
				if (aVal < bVal) return this.sortAsc ? -1 : 1
				if (aVal > bVal) return this.sortAsc ? 1 : -1
				return 0
			})
			return list
		},
	},
	async created() {
		await this.loadVoorstellen()
	},
	methods: {
		async loadVoorstellen() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('voorstel', { _limit: 500 })
				this.voorstellen = Array.isArray(results) ? results : (results?.results || [])
			} catch (error) {
				console.error('Failed to load voorstellen:', error)
				this.voorstellen = []
			} finally {
				this.loading = false
			}
		},
		formatType(type) {
			return TYPE_LABELS[type] || type
		},
		formatStatus(status) {
			return STATUS_LABELS[status] || status
		},
		formatStepProgress(voorstel) {
			const steps = this.getSteps(voorstel)
			if (!steps.length || !voorstel.currentStep) return '-'
			return `${voorstel.currentStep}/${steps.length}`
		},
		getSteps(voorstel) {
			if (voorstel.routeSnapshot) {
				try {
					return typeof voorstel.routeSnapshot === 'string'
						? JSON.parse(voorstel.routeSnapshot)
						: voorstel.routeSnapshot
				} catch {
					return []
				}
			}
			return []
		},
		getWaitingActor(voorstel) {
			const steps = this.getSteps(voorstel)
			if (!steps.length || !voorstel.currentStep) return '-'
			const current = steps.find(s => s.order === voorstel.currentStep)
			return current ? (current.label || current.actor || '-') : '-'
		},
		getDaysInStep(voorstel) {
			const updated = voorstel._self?.updated || voorstel.updatedAt
			if (!updated) return '-'
			const diff = Math.floor((Date.now() - new Date(updated).getTime()) / (1000 * 60 * 60 * 24))
			return `${diff}d`
		},
		isOverdue(voorstel) {
			if (['concept', 'besloten', 'gearchiveerd'].includes(voorstel.status)) return false
			const updated = voorstel._self?.updated || voorstel.updatedAt
			if (!updated) return false
			const days = Math.floor((Date.now() - new Date(updated).getTime()) / (1000 * 60 * 60 * 24))
			return days >= OVERDUE_THRESHOLD_DAYS
		},
		getSortValue(voorstel) {
			if (this.sortField === '_self.updated') {
				return voorstel._self?.updated || ''
			}
			return voorstel[this.sortField] || ''
		},
		sortBy(field) {
			if (this.sortField === field) {
				this.sortAsc = !this.sortAsc
			} else {
				this.sortField = field
				this.sortAsc = true
			}
		},
		openVoorstel(voorstel) {
			this.$router.push({ name: 'VoorstelDetail', params: { id: voorstel.id } })
		},
		async sendReminder(voorstel) {
			const actor = this.getWaitingActor(voorstel)
			try {
				await fetch('/apps/procest/api/notifications/parafering-reminder', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({
						voorstelId: voorstel.id,
						actor,
						onderwerp: voorstel.onderwerp,
					}),
				})
			} catch (error) {
				console.error('Failed to send reminder:', error)
			}
		},
		async onVoorstelCreated() {
			this.showCreateDialog = false
			await this.loadVoorstellen()
		},
	},
}
</script>

<style scoped>
.voorstel-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px;
}

.voorstel-list__tabs {
	display: flex;
	gap: 4px;
	padding: 0 20px 12px;
	border-bottom: 1px solid var(--color-border);
}

.voorstel-list__tab {
	padding: 6px 12px;
	border: none;
	background: none;
	cursor: pointer;
	border-radius: var(--border-radius-pill);
	color: var(--color-text-maxcontrast);
}

.voorstel-list__tab--active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-weight: 600;
}

.voorstel-list__row {
	cursor: pointer;
}

.voorstel-list__row:hover {
	background: var(--color-background-hover);
}

.voorstel-list__row--overdue {
	background: var(--color-warning-light, rgba(255, 165, 0, 0.1));
}

.voorstel-list__type-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	font-size: 0.85em;
}

.voorstel-list__status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 0.85em;
}

.voorstel-list__status-badge--concept { background: var(--color-background-dark); }
.voorstel-list__status-badge--in_parafering { background: var(--color-primary-element-light); color: var(--color-primary-element); }
.voorstel-list__status-badge--ter_accordering { background: var(--color-primary-element-light); color: var(--color-primary-element); }
.voorstel-list__status-badge--geaccordeerd { background: var(--color-success-light, #e8f5e9); color: var(--color-success, #2e7d32); }
.voorstel-list__status-badge--besloten { background: var(--color-success-light, #e8f5e9); color: var(--color-success, #2e7d32); }
.voorstel-list__status-badge--teruggestuurd { background: var(--color-warning-light, #fff3e0); color: var(--color-warning, #e65100); }

.voorstel-list__overdue-text {
	color: var(--color-error);
	font-weight: 600;
}

.viewTableContainer {
	padding: 0 20px;
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	cursor: pointer;
	user-select: none;
}

.viewTable td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}
</style>
