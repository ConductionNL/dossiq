<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Conflict-resolution merge modal — isolated NcDialog per ADR-004.

  Shows a side-by-side field diff (computed by the pure `diffVersions` helper)
  between the inspector's offline version and the server version of a
  conflicted record, and lets them choose: keep mine / accept server / manual
  merge. The choice is posted to POST /api/sync/conflicts/{id}/resolve (server
  re-authorizes ownership) and the local queue op is patched via
  `resolveConflictChoice`.

  Usage:
    <ConflictResolverModal
      :operation-id="op.id"
      :device-id="deviceId"
      :client-version="clientVersion"
      :server-version="serverVersion"
      @resolved="onResolved"
      @close="show = false" />

  @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
-->
<template>
	<NcDialog
		:name="t('procest', 'Resolve sync conflict')"
		:open="true"
		size="large"
		data-testid="mio-conflict-modal"
		@update:open="onDialogClose"
		@closing="$emit('close')">
		<div class="mio-conflict">
			<p class="mio-conflict-intro">
				{{ t('procest', 'A colleague edited this case while you were offline. Choose which version to keep.') }}
			</p>

			<table class="mio-conflict-diff" data-testid="mio-conflict-diff">
				<thead>
					<tr>
						<th>{{ t('procest', 'Field') }}</th>
						<th>{{ t('procest', 'My version') }}</th>
						<th>{{ t('procest', 'Server version') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in diff" :key="row.field">
						<td>{{ row.field }}</td>
						<td class="mio-conflict-mine">
							{{ display(row.client) }}
						</td>
						<td class="mio-conflict-server">
							{{ display(row.server) }}
						</td>
					</tr>
				</tbody>
			</table>

			<div class="mio-conflict-actions">
				<NcButton type="primary"
					data-testid="mio-conflict-mine"
					:disabled="submitting"
					@click="resolve('client_wins')">
					{{ t('procest', 'Use my version') }}
				</NcButton>
				<NcButton data-testid="mio-conflict-server" :disabled="submitting" @click="resolve('server_wins')">
					{{ t('procest', 'Accept server version') }}
				</NcButton>
				<NcButton data-testid="mio-conflict-merge" :disabled="submitting" @click="resolve('manual_merge')">
					{{ t('procest', 'Merge manually') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { diffVersions, resolveConflictChoice } from '../utils/syncQueueEngine.js'
import { getDb } from '../store/offlineDb.js'

export default {
	name: 'ConflictResolverModal',
	components: { NcDialog, NcButton },
	props: {
		operationId: { type: String, required: true },
		deviceId: { type: String, required: true },
		clientVersion: { type: Object, default: () => ({}) },
		serverVersion: { type: Object, default: () => ({}) },
	},
	data() {
		return { submitting: false }
	},
	computed: {
		/**
		 * Field-level diff for the side-by-side merge view (pure helper).
		 *
		 * @return {Array<{ field: string, client: *, server: * }>} The diff.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
		 */
		diff() {
			return diffVersions(this.clientVersion, this.serverVersion)
		},
	},
	methods: {
		/**
		 * Render a diff cell value for display (objects → JSON, null → dash).
		 *
		 * @param {*} value The field value.
		 * @return {string} The display string.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
		 */
		display(value) {
			if (value === null || value === undefined) return '—'
			return typeof value === 'object' ? JSON.stringify(value) : String(value)
		},
		/**
		 * Emit close when the dialog is dismissed.
		 *
		 * @param {boolean} open The dialog open state.
		 * @return {void}
		 * @spec exclude trivial dialog close bridge, no behaviour
		 */
		onDialogClose(open) {
			if (open === false) {
				this.$emit('close')
			}
		},
		/**
		 * Submit a resolution choice: server re-authorizes, then patch locally.
		 *
		 * @param {string} resolution One of client_wins/server_wins/manual_merge.
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
		 */
		async resolve(resolution) {
			this.submitting = true
			try {
				await axios.post(
					generateUrl(`/apps/procest/api/sync/conflicts/${this.operationId}/resolve`),
					{ deviceId: this.deviceId, resolution },
				)
				const { patch } = resolveConflictChoice(resolution, this.clientVersion)
				await getDb().syncQueue.update(this.operationId, patch)
				this.$emit('resolved', { operationId: this.operationId, resolution })
				this.$emit('close')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.mio-conflict-diff { width: 100%; border-collapse: collapse; margin: 12px 0; }
.mio-conflict-diff th, .mio-conflict-diff td { border: 1px solid var(--color-border, #ddd); padding: 8px; text-align: left; vertical-align: top; }
.mio-conflict-mine { background: var(--color-success-hover, #e8f5ec); }
.mio-conflict-server { background: var(--color-warning-hover, #fdf3e0); }
.mio-conflict-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
</style>
