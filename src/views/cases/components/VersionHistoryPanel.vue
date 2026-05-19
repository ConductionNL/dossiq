<!--
  VersionHistoryPanel.vue
  Side panel showing Nextcloud file versions for an informatieobject.
  @spec openspec/changes/document-zaakdossier/tasks.md#task-T07
-->
<template>
	<div class="version-panel">
		<div class="version-panel__header">
			<h4>{{ t('procest', 'Version history') }}</h4>
			<NcButton @click="$emit('close')">✕</NcButton>
		</div>

		<div v-if="loading" class="version-panel__loading">
			<NcLoadingIcon :size="16" />
		</div>
		<ul v-else-if="versions.length > 0" class="version-panel__list">
			<li v-for="version in versions" :key="version.href" class="version-panel__item">
				<div class="version-panel__item-meta">
					<span class="version-panel__label">{{ t('procest', 'Version') }} {{ version.label }}</span>
					<span class="version-panel__date">{{ formatDate(version.lastmodified) }}</span>
					<span v-if="version.author" class="version-panel__author">{{ version.author }}</span>
				</div>
				<NcButton
					:disabled="infoObject.status === 'definitief'"
					:title="infoObject.status === 'definitief' ? t('procest', 'Cannot restore a definitief document') : ''"
					@click="restore(version)">
					{{ t('procest', 'Restore') }}
				</NcButton>
			</li>
		</ul>
		<p v-else class="version-panel__empty">{{ t('procest', 'No versions found.') }}</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'VersionHistoryPanel',
	components: { NcButton, NcLoadingIcon },
	props: {
		infoObject: { type: Object, required: true },
	},
	emits: ['close'],
	data() {
		return { loading: false, versions: [] }
	},
	mounted() {
		this.loadVersions()
	},
	methods: {
		async loadVersions() {
			if (!this.infoObject.fileId) return
			this.loading = true
			try {
				const user = getCurrentUser()
				const url = generateRemoteUrl('dav') + '/versions/' + (user?.uid || '') + '/versions/' + this.infoObject.fileId
				const resp = await axios.request({ method: 'PROPFIND', url, data: this.propfindBody(), headers: { 'Content-Type': 'application/xml' } })
				this.versions = this.parseVersions(resp.data)
			} catch (e) {
				console.error('VersionHistoryPanel: load failed', e)
			} finally {
				this.loading = false
			}
		},
		propfindBody() {
			return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getlastmodified/><d:getcontentlength/></d:prop></d:propfind>'
		},
		parseVersions(xml) {
			const parser = new DOMParser()
			const doc = parser.parseFromString(xml, 'application/xml')
			const responses = doc.querySelectorAll('response')
			return Array.from(responses)
				.slice(1)
				.map((r, i) => ({
					href: r.querySelector('href')?.textContent || '',
					lastmodified: r.querySelector('getlastmodified')?.textContent || '',
					label: String(i + 1),
				}))
				.reverse()
		},
		async restore(version) {
			if (this.infoObject.status === 'definitief') return
			try {
				const user = getCurrentUser()
				const currentUrl = generateRemoteUrl('dav') + '/files/' + (user?.uid || '') + '/' + this.infoObject.bestandsnaam
				await axios.request({ method: 'COPY', url: version.href, headers: { Destination: currentUrl, Overwrite: 'T' } })
				this.$emit('close')
			} catch (e) {
				console.error('VersionHistoryPanel: restore failed', e)
			}
		},
		formatDate(d) {
			if (!d) return ''
			return new Date(d).toLocaleString()
		},
	},
}
</script>

<style scoped>
.version-panel { position: fixed; top: 0; right: 0; height: 100vh; width: 320px; background: var(--color-main-background); border-left: 1px solid var(--color-border); padding: 16px; z-index: 1000; overflow-y: auto; }
.version-panel__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.version-panel__list { list-style: none; padding: 0; margin: 0; }
.version-panel__item { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid var(--color-border-dark); }
.version-panel__item-meta { flex: 1; }
.version-panel__label { display: block; font-weight: 600; }
.version-panel__date, .version-panel__author { font-size: 0.75rem; color: var(--color-text-lighter); }
.version-panel__empty, .version-panel__loading { color: var(--color-text-lighter); }
</style>
