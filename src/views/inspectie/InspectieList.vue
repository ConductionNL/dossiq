<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Mobiel-inspectie offline home: the inspector's daily planning + sync state.
  -
  - Reads the planning from local IndexedDB (synced via "Synchronise day"
  - against GET /apps/procest/api/sync/daily) and shows the pending-sync badge
  - driven by the pure `syncIndicator` helper. Mutations are queued locally and
  - drained by `syncReplayService` when connectivity returns.
  -
  - @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
  -->
<template>
	<div class="mio-list" data-testid="mio-inspectie-list">
		<header class="mio-toolbar">
			<h1>{{ t('procest', 'Field inspections') }}</h1>
			<div class="mio-sync" :class="`mio-sync--${indicator.tone}`" data-testid="mio-sync-indicator">
				<span class="mio-sync-dot" aria-hidden="true" />
				<span>{{ indicator.text }}</span>
			</div>
		</header>

		<div class="mio-actions">
			<NcButton type="primary"
				data-testid="mio-sync-day"
				:disabled="syncing || offline"
				@click="syncDay">
				{{ syncing ? t('procest', 'Synchronising…') : t('procest', 'Synchronise day') }}
			</NcButton>
			<span v-if="planning" class="mio-planning-meta" data-testid="mio-planning-meta">
				{{ t('procest', 'Ready offline until {time}', { time: formatTime(planning.expiresAt) }) }}
			</span>
		</div>

		<NcButton v-if="pendingCount > 0 && !offline"
			data-testid="mio-drain-queue"
			:disabled="syncing"
			@click="drain">
			{{ t('procest', 'Sync {n} pending changes', { n: pendingCount }) }}
		</NcButton>

		<div v-if="loading" class="mio-state" data-testid="mio-loading">
			<NcLoadingIcon :size="24" />
		</div>
		<NcEmptyContent v-else-if="inspections.length === 0"
			data-testid="mio-empty"
			:name="t('procest', 'No inspections planned')"
			:description="t('procest', 'Tap “Synchronise day” while online to download your planning.')" />
		<ul v-else class="mio-cards" data-testid="mio-inspection-cards">
			<li v-for="item in inspections" :key="item.id" class="mio-card">
				<router-link :to="{ name: 'InspectieDetail', params: { id: item.id } }" class="mio-card-link">
					<span class="mio-card-title">{{ item.caseRef }}</span>
					<span class="mio-card-status" :class="`mio-status--${item.status}`">{{ statusLabel(item.status) }}</span>
				</router-link>
			</li>
		</ul>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import { syncIndicator } from '../../utils/fieldInspectionHelpers.js'
import { getDb, storeDailyPlanning, getPlanningMeta, countPending } from '../../store/offlineDb.js'
import { drainQueue } from '../../services/syncReplayService.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'InspectieList',
	components: { NcButton, NcLoadingIcon, NcEmptyContent },
	data() {
		return {
			inspections: [],
			planning: null,
			pendingCount: 0,
			loading: true,
			syncing: false,
			offline: typeof navigator !== 'undefined' ? navigator.onLine === false : false,
			deviceId: this.resolveDeviceId(),
		}
	},
	computed: {
		/**
		 * Sync indicator tone + copy from the pure helper.
		 *
		 * @return {{ tone: string, text: string }} Indicator state.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
		 */
		indicator() {
			return syncIndicator(this.pendingCount, this.offline === false)
		},
	},
	/**
	 * Wire online/offline listeners (auto-drain on reconnect) + load local state.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
	 */
	async mounted() {
		window.addEventListener('online', this.onOnline)
		window.addEventListener('offline', this.onOffline)
		await this.loadLocal()
	},
	/**
	 * Detach the online/offline listeners.
	 *
	 * @return {void}
	 * @spec exclude trivial Vue lifecycle teardown, no behaviour
	 */
	beforeDestroy() {
		window.removeEventListener('online', this.onOnline)
		window.removeEventListener('offline', this.onOffline)
	},
	methods: {
		/**
		 * Resolve a stable per-device id, persisted in localStorage.
		 *
		 * @return {string} The device id.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
		 */
		resolveDeviceId() {
			let id = window.localStorage?.getItem('procest-mio-device')
			if (!id) {
				id = `device-${Math.random().toString(36).slice(2, 10)}`
				window.localStorage?.setItem('procest-mio-device', id)
			}
			return id
		},
		/**
		 * Load planning + pending count from IndexedDB.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
		 */
		async loadLocal() {
			this.loading = true
			try {
				const db = getDb()
				this.inspections = await db.fieldInspection.toArray()
				this.planning = await getPlanningMeta()
				this.pendingCount = await countPending(this.deviceId)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Download the daily planning and store it offline.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
		 */
		async syncDay() {
			this.syncing = true
			try {
				const { data } = await axios.get(generateUrl('/apps/procest/api/sync/daily'))
				await storeDailyPlanning(data)
				await this.loadLocal()
			} finally {
				this.syncing = false
			}
		},
		/**
		 * Drain the local sync queue against the server.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
		 */
		async drain() {
			this.syncing = true
			try {
				await drainQueue(this.deviceId)
				await this.loadLocal()
			} finally {
				this.syncing = false
			}
		},
		/**
		 * On reconnect: clear offline state and auto-drain the queue.
		 *
		 * @return {void}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection
		 */
		onOnline() {
			this.offline = false
			this.drain()
		},
		/**
		 * On disconnect: flip the indicator to the offline state.
		 *
		 * @return {void}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
		 */
		onOffline() {
			this.offline = true
		},
		/**
		 * Map an inspection lifecycle status to a translated label.
		 *
		 * @param {string} status The status enum value.
		 * @return {string} The translated label.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
		 */
		statusLabel(status) {
			const map = {
				planned: this.t('procest', 'Planned'),
				in_progress: this.t('procest', 'In progress'),
				synced: this.t('procest', 'Synced'),
				conflict: this.t('procest', 'Conflict'),
			}
			return map[status] || status
		},
		/**
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string} A locale-formatted time string.
		 * @spec exclude trivial date formatter, no domain behaviour
		 */
		formatTime(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleString()
		},
	},
}
</script>

<style scoped>
.mio-list { padding: 16px; max-width: 720px; margin: 0 auto; }
.mio-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.mio-sync { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; }
.mio-sync-dot { width: 10px; height: 10px; border-radius: 50%; }
.mio-sync--success .mio-sync-dot { background: var(--color-success, #2d7d46); }
.mio-sync--warning .mio-sync-dot { background: var(--color-warning, #c28900); }
.mio-sync--error .mio-sync-dot { background: var(--color-error, #c4291b); }
.mio-actions { display: flex; align-items: center; gap: 12px; margin: 16px 0; flex-wrap: wrap; }
.mio-cards { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.mio-card-link { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; min-height: 44px; border: 1px solid var(--color-border, #ddd); border-radius: var(--border-radius-large, 8px); }
.mio-card-status { font-size: 0.85em; }
.mio-state { display: flex; justify-content: center; padding: 32px; }
.mio-planning-meta { font-size: 0.85em; color: var(--color-text-maxcontrast); }
</style>
