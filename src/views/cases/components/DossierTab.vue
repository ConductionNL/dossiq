<!--
  DossierTab.vue
  Dossier tab for CaseDetail — shows all informatieobjecten grouped by type,
  with count badge, drag-and-drop upload, and bulk actions.
  @spec openspec/changes/document-zaakdossier/tasks.md#task-T06
-->
<template>
	<div class="dossier-tab">
		<div class="dossier-tab__header">
			<h3 class="dossier-tab__title">
				{{ t('procest', 'Dossier') }}
				<span v-if="totalCount > 0" class="dossier-tab__badge">{{ totalCount }}</span>
			</h3>
			<NcButton type="primary"
				class="dossier-tab__upload-btn"
				@click="showUploadDialog = true">
				{{ t('procest', 'Upload document') }}
			</NcButton>
		</div>

		<div v-if="loading" class="dossier-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('procest', 'Loading dossier...') }}
		</div>

		<div v-else-if="totalCount === 0" class="dossier-tab__empty">
			<div class="dossier-tab__drop-zone"
				:class="{ 'dossier-tab__drop-zone--active': isDragging }"
				@dragover.prevent="isDragging = true"
				@dragleave="isDragging = false"
				@drop.prevent="onFileDrop">
				<span>{{ t('procest', 'Drag and drop files here or') }}</span>
				<NcButton @click="showUploadDialog = true">
					{{ t('procest', 'select files') }}
				</NcButton>
			</div>
		</div>

		<template v-else>
			<BulkActionsBar
				v-if="selectedIds.length > 0"
				:selected-count="selectedIds.length"
				@transition="onBulkTransition"
				@download-zip="onBulkDownloadZip"
				@clear="selectedIds = []" />

			<DossierGroup
				v-for="(items, type) in groupedDossier"
				:key="type"
				:type-label="type"
				:items="items"
				:selected-ids="selectedIds"
				@select="onSelectToggle"
				@open-version-history="onOpenVersionHistory"
				@delete="onDeleteDocument" />
		</template>

		<DocumentMetadataDialog
			v-if="showUploadDialog"
			:case-id="caseId"
			@uploaded="onDocumentUploaded"
			@close="showUploadDialog = false" />

		<VersionHistoryPanel
			v-if="versionHistoryItem"
			:info-object="versionHistoryItem"
			@close="versionHistoryItem = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import DossierGroup from './DossierGroup.vue'
import DocumentMetadataDialog from './DocumentMetadataDialog.vue'
import VersionHistoryPanel from './VersionHistoryPanel.vue'
import BulkActionsBar from './BulkActionsBar.vue'

export default {
	name: 'DossierTab',
	components: {
		NcButton,
		NcLoadingIcon,
		DossierGroup,
		DocumentMetadataDialog,
		VersionHistoryPanel,
		BulkActionsBar,
	},
	props: {
		caseId: { type: String, required: true },
	},
	emits: ['count-changed'],
	data() {
		return {
			loading: false,
			groupedDossier: {},
			selectedIds: [],
			showUploadDialog: false,
			versionHistoryItem: null,
			isDragging: false,
			droppedFiles: null,
		}
	},
	computed: {
		totalCount() {
			return Object.values(this.groupedDossier).reduce((s, g) => s + g.length, 0)
		},
	},
	mounted() {
		this.loadDossier()
	},
	methods: {
		async loadDossier() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/cases/' + encodeURIComponent(this.caseId) + '/dossier')
				const { data } = await axios.get(url)
				this.groupedDossier = data.dossier || {}
				this.$emit('count-changed', data.count || 0)
			} catch (e) {
				console.error('DossierTab: load failed', e)
			} finally {
				this.loading = false
			}
		},
		onSelectToggle(id) {
			const idx = this.selectedIds.indexOf(id)
			if (idx === -1) {
				this.selectedIds.push(id)
			} else {
				this.selectedIds.splice(idx, 1)
			}
		},
		onOpenVersionHistory(item) {
			this.versionHistoryItem = item
		},
		async onDeleteDocument(infoObjectId) {
			try {
				const url = generateUrl('/apps/procest/api/cases/' + encodeURIComponent(this.caseId) + '/dossier/' + encodeURIComponent(infoObjectId) + '/link')
				await axios.delete(url)
				await this.loadDossier()
			} catch (e) {
				console.error('DossierTab: delete failed', e)
			}
		},
		async onBulkTransition({ status }) {
			try {
				const url = generateUrl('/apps/procest/api/informatieobjecten/bulk/status')
				await axios.post(url, { ids: this.selectedIds, status })
				this.selectedIds = []
				await this.loadDossier()
			} catch (e) {
				console.error('DossierTab: bulk transition failed', e)
			}
		},
		async onBulkDownloadZip() {
			try {
				const url = generateUrl('/apps/procest/api/cases/' + encodeURIComponent(this.caseId) + '/dossier/zip')
				const resp = await axios.post(url, { ids: this.selectedIds }, { responseType: 'blob' })
				const link = document.createElement('a')
				link.href = URL.createObjectURL(resp.data)
				link.download = 'zaakdossier.zip'
				link.click()
			} catch (e) {
				console.error('DossierTab: ZIP download failed', e)
			}
		},
		onDocumentUploaded() {
			this.showUploadDialog = false
			this.loadDossier()
		},
		onFileDrop(event) {
			this.isDragging = false
			const files = event.dataTransfer?.files
			if (files && files.length > 0) {
				this.droppedFiles = files
				this.showUploadDialog = true
			}
		},
	},
}
</script>

<style scoped>
.dossier-tab { padding: 12px; }
.dossier-tab__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.dossier-tab__title { margin: 0; display: flex; align-items: center; gap: 6px; }
.dossier-tab__badge { background: var(--color-primary); color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.75rem; }
.dossier-tab__loading { display: flex; align-items: center; gap: 8px; }
.dossier-tab__empty { padding: 20px 0; }
.dossier-tab__drop-zone { border: 2px dashed var(--color-border); border-radius: 8px; padding: 40px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.dossier-tab__drop-zone--active { border-color: var(--color-primary); background: var(--color-primary-light); }
</style>
