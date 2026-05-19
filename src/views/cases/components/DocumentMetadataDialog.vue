<!--
  DocumentMetadataDialog.vue
  Upload metadata dialog — appears on drag-drop or upload button click.
  @spec openspec/changes/document-zaakdossier/tasks.md#task-T07
-->
<template>
	<NcDialog :name="t('procest', 'Upload document')"
		:open="true"
		@update:open="val => { if (!val) $emit('close') }">
		<template #default>
			<div class="doc-meta-dialog">
				<div v-if="files.length === 0" class="doc-meta-dialog__file-select">
					<NcButton @click="$refs.fileInput.click()">
						{{ t('procest', 'Select file(s)') }}
					</NcButton>
					<input ref="fileInput" type="file" multiple hidden @change="onFileSelect">
				</div>
				<ul v-else class="doc-meta-dialog__file-list">
					<li v-for="(f, i) in files" :key="i">
						{{ f.name }}
						<span v-if="progress[i]"> — {{ progress[i] }}%</span>
					</li>
				</ul>

				<div class="doc-meta-dialog__field">
					<label>{{ t('procest', 'Document type') }} *</label>
					<NcSelect
						v-model="form.informatieobjecttype"
						:options="typeOptions"
						label="label"
						track-by="value"
						:placeholder="t('procest', 'Select type...')"
						:inputLabel="t('procest', 'Document type')"
						@input="onTypeSelected" />
				</div>

				<div class="doc-meta-dialog__field">
					<label>{{ t('procest', 'Confidentiality') }} *</label>
					<NcSelect
						v-model="form.vertrouwelijkheidaanduiding"
						:options="classOptions"
						label="label"
						track-by="value"
						:inputLabel="t('procest', 'Confidentiality')" />
				</div>

				<div class="doc-meta-dialog__field">
					<label>{{ t('procest', 'Title') }} *</label>
					<NcTextField
						:value="form.titel"
						:placeholder="t('procest', 'Document title')"
						@update:value="v => form.titel = v" />
				</div>

				<div class="doc-meta-dialog__field">
					<label>{{ t('procest', 'Description') }}</label>
					<NcTextField
						:value="form.beschrijving"
						:placeholder="t('procest', 'Optional description')"
						@update:value="v => form.beschrijving = v" />
				</div>

				<p v-if="error" class="doc-meta-dialog__error">{{ error }}</p>
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">{{ t('procest', 'Cancel') }}</NcButton>
			<NcButton type="primary" :disabled="saving || files.length === 0" @click="upload">
				{{ saving ? t('procest', 'Uploading...') : t('procest', 'Upload') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'

const CLASSIFICATIONS = ['openbaar', 'beperkt_openbaar', 'intern', 'zaakvertrouwelijk', 'vertrouwelijk', 'confidentieel', 'geheim', 'zeer_geheim']

export default {
	name: 'DocumentMetadataDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	props: {
		caseId: { type: String, required: true },
		initialFiles: { type: Array, default: () => [] },
	},
	emits: ['uploaded', 'close'],
	data() {
		return {
			files: [...this.initialFiles],
			form: {
				titel: '',
				beschrijving: '',
				informatieobjecttype: null,
				vertrouwelijkheidaanduiding: { value: 'intern', label: 'intern' },
			},
			typeOptions: [],
			classOptions: CLASSIFICATIONS.map(c => ({ value: c, label: c })),
			progress: {},
			saving: false,
			error: '',
		}
	},
	mounted() {
		this.loadTypes()
	},
	methods: {
		async loadTypes() {
			try {
				const url = generateUrl('/apps/openregister/api/objects/procest/informatieobjecttype')
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				const items = data.results || data.items || data || []
				this.typeOptions = items.map(t => ({ value: t.id || t.slug, label: t.omschrijving || t.id }))
			} catch (e) {
				console.error('DocumentMetadataDialog: loadTypes failed', e)
			}
		},
		onTypeSelected(opt) {
			if (opt && opt.defaultVertrouwelijkheid) {
				this.form.vertrouwelijkheidaanduiding = { value: opt.defaultVertrouwelijkheid, label: opt.defaultVertrouwelijkheid }
			}
		},
		onFileSelect(event) {
			this.files = Array.from(event.target.files)
			if (this.files.length > 0 && !this.form.titel) {
				this.form.titel = this.files[0].name.replace(/\.[^.]+$/, '')
			}
		},
		async upload() {
			this.error = ''
			if (!this.form.informatieobjecttype || !this.form.vertrouwelijkheidaanduiding || !this.form.titel) {
				this.error = this.t('procest', 'Please fill in all required fields.')
				return
			}
			this.saving = true
			try {
				for (let i = 0; i < this.files.length; i++) {
					const formData = new FormData()
					formData.append('file', this.files[i])
					formData.append('titel', this.form.titel || this.files[i].name)
					formData.append('beschrijving', this.form.beschrijving)
					formData.append('informatieobjecttype', this.form.informatieobjecttype.value)
					formData.append('vertrouwelijkheidaanduiding', this.form.vertrouwelijkheidaanduiding.value)
					const url = generateUrl('/apps/procest/api/cases/' + encodeURIComponent(this.caseId) + '/dossier')
					await axios.post(url, formData, {
						onUploadProgress: (e) => {
							this.$set(this.progress, i, Math.round((e.loaded / e.total) * 100))
						},
					})
				}
				this.$emit('uploaded')
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('procest', 'Upload failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.doc-meta-dialog { display: flex; flex-direction: column; gap: 12px; padding: 8px 0; }
.doc-meta-dialog__field { display: flex; flex-direction: column; gap: 4px; }
.doc-meta-dialog__file-list { margin: 0; padding-left: 16px; }
.doc-meta-dialog__error { color: var(--color-error); font-size: 0.85rem; }
</style>
