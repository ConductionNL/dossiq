<template>
	<div class="document-checklist">
		<div v-if="loading" class="document-checklist__loading">
			<NcLoadingIcon :size="20" />
		</div>

		<template v-else-if="documentTypes.length === 0">
			<p class="document-checklist__empty">
				{{ t('procest', 'No required documents for this case type') }}
			</p>
		</template>

		<template v-else>
			<div class="document-checklist__header">
				{{ t('procest', '{present}/{total} complete', { present: presentCount, total: documentTypes.length }) }}
			</div>

			<div class="document-checklist__list">
				<div
					v-for="docType in documentTypes"
					:key="docType.id"
					class="doc-item"
					:class="{ 'doc-item--present': isDocPresent(docType.id), 'doc-item--missing': !isDocPresent(docType.id) }">
					<div class="doc-item__icon">
						<span v-if="isDocPresent(docType.id)" class="doc-item__check" aria-label="Present">&#10003;</span>
						<span v-else class="doc-item__missing" aria-label="Missing">&#10007;</span>
					</div>
					<div class="doc-item__info">
						<span class="doc-item__name">{{ docType.name }}</span>
						<span v-if="docType.category" class="doc-item__category">{{ docType.category }}</span>
						<span v-if="getUploadDate(docType.id)" class="doc-item__date">
							{{ t('procest', 'Uploaded: {date}', { date: getUploadDate(docType.id) }) }}
						</span>
						<span v-else-if="docType.requiredAtStatus" class="doc-item__required-at">
							{{ t('procest', 'Required at: {status}', { status: getStatusName(docType.requiredAtStatus) }) }}
						</span>
					</div>
					<div v-if="!isDocPresent(docType.id) && !isReadOnly" class="doc-item__actions">
						<NcButton type="tertiary" @click="$emit('upload', docType)">
							{{ t('procest', 'Upload') }}
						</NcButton>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'DocumentChecklist',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		caseTypeId: {
			type: String,
			default: null,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
		statusTypes: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			loading: false,
			documentTypes: [],
			caseDocuments: [],
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		presentCount() {
			return this.documentTypes.filter(dt => this.isDocPresent(dt.id)).length
		},
	},
	watch: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		caseTypeId() {
			if (this.caseTypeId) {
				this.loadData()
			}
		},
	},
	/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
	async mounted() {
		if (this.caseTypeId) {
			await this.loadData()
		}
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async loadData() {
			this.loading = true
			const objectStore = useObjectStore()

			const [docTypes, caseDocs] = await Promise.all([
				objectStore.fetchCollection('documentType', {
					'_filters[caseType]': this.caseTypeId,
					_limit: 100,
				}),
				objectStore.fetchCollection('caseDocument', {
					'_filters[case]': this.caseId,
					_limit: 100,
				}),
			])

			this.documentTypes = docTypes || []
			this.caseDocuments = caseDocs || []
			this.loading = false
		},

		isDocPresent(docTypeId) {
			return this.caseDocuments.some(cd => cd.documentType === docTypeId)
		},

		/**
		 * @param docTypeId
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		getUploadDate(docTypeId) {
			const doc = this.caseDocuments.find(cd => cd.documentType === docTypeId)
			if (!doc?.registrationDate) return null
			return new Date(doc.registrationDate).toLocaleDateString(undefined, {
				month: 'short',
				day: 'numeric',
			})
		},

		/**
		 * @param statusTypeId
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		getStatusName(statusTypeId) {
			const st = this.statusTypes.find(s => s.id === statusTypeId)
			return st?.name || statusTypeId
		},
	},
}
</script>

<style scoped>
.document-checklist__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 12px;
}

.document-checklist__header {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
	font-weight: 500;
}

.document-checklist__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.doc-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.doc-item--present {
	border-left: 3px solid var(--color-success);
}

.doc-item--missing {
	border-left: 3px solid var(--color-warning);
}

.doc-item__icon {
	flex-shrink: 0;
	width: 20px;
	text-align: center;
}

.doc-item__check {
	color: var(--color-success);
	font-weight: bold;
}

.doc-item__missing {
	color: var(--color-warning);
	font-weight: bold;
}

.doc-item__info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.doc-item__name {
	font-weight: 500;
}

.doc-item__category {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.doc-item__date {
	font-size: 12px;
	color: var(--color-success);
}

.doc-item__required-at {
	font-size: 12px;
	color: var(--color-warning);
}
</style>
