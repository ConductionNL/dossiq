<template>
	<div class="case-documents-widget">
		<div v-if="documents.length === 0" class="documents-empty">
			{{ t('procest', 'No documents attached') }}
		</div>
		<div v-else class="documents-list">
			<div
				v-for="doc in documents"
				:key="doc.id"
				class="document-row">
				<span class="document-icon">{{ getFileIcon(doc.mimeType || doc.type) }}</span>
				<div class="document-info">
					<span class="document-name">{{ doc.title || doc.name || '---' }}</span>
					<span v-if="doc.createdAt" class="document-date">
						{{ formatDate(doc.createdAt) }}
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { formatDate } from '../../../utils/caseHelpers.js'

export default {
	name: 'CaseDocumentsWidget',
	props: {
		caseId: {
			type: String,
			default: null,
		},
		documents: {
			type: Array,
			default: () => [],
		},
	},
	methods: {
		formatDate,
		/**
		 * @param mimeType
		 * @spec openspec/specs/signalering-widgets/spec.md
		 */
		getFileIcon(mimeType) {
			if (!mimeType) return '📄'
			if (mimeType.includes('pdf')) return '📕'
			if (mimeType.includes('image')) return '🖼'
			if (mimeType.includes('spreadsheet') || mimeType.includes('excel')) return '📊'
			return '📄'
		},
	},
}
</script>

<style scoped>
.case-documents-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

.documents-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px;
}

.documents-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.document-row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px;
	border-radius: var(--border-radius);
}

.document-row:hover {
	background: var(--color-background-hover);
}

.document-icon {
	font-size: 20px;
	flex-shrink: 0;
}

.document-info {
	flex: 1;
	min-width: 0;
}

.document-name {
	display: block;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.document-date {
	display: block;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}
</style>
