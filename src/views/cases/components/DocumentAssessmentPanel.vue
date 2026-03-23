<template>
	<div class="document-assessment">
		<h4 class="document-assessment__title">
			{{ t('procest', 'Document Assessment') }}
		</h4>
		<p class="document-assessment__description">
			{{ t('procest', 'Assess each document for disclosure under the WOO.') }}
		</p>

		<div v-if="documents.length === 0" class="document-assessment__empty">
			{{ t('procest', 'No documents to assess.') }}
		</div>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th>{{ t('procest', 'Document') }}</th>
						<th>{{ t('procest', 'Assessment') }}</th>
						<th>{{ t('procest', 'Grounds') }}</th>
						<th>{{ t('procest', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents" :key="doc.id">
						<td>{{ doc.title || doc.name || '---' }}</td>
						<td>
							<NcSelect
								:value="getAssessment(doc.id)"
								:options="assessmentOptions"
								:disabled="isReadOnly"
								@input="val => setAssessment(doc.id, val)" />
						</td>
						<td>
							<NcSelect
								v-if="getAssessment(doc.id) === 'niet_openbaar'"
								:value="getGrounds(doc.id)"
								:options="weigeringsgronden"
								:multiple="true"
								label="label"
								track-by="code"
								:disabled="isReadOnly"
								:placeholder="t('procest', 'Select grounds...')"
								@input="val => setGrounds(doc.id, val)" />
							<span v-else>---</span>
						</td>
						<td>
							<NcButton
								v-if="getAssessment(doc.id) === 'deels_openbaar'"
								type="secondary"
								:disabled="isReadOnly"
								@click="$emit('anonymize', doc)">
								{{ t('procest', 'Anonymize') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Summary -->
		<div v-if="documents.length > 0" class="document-assessment__summary">
			<span class="document-assessment__count document-assessment__count--openbaar">
				{{ t('procest', 'Public') }}: {{ counts.openbaar }}
			</span>
			<span class="document-assessment__count document-assessment__count--deels">
				{{ t('procest', 'Partial') }}: {{ counts.deels_openbaar }}
			</span>
			<span class="document-assessment__count document-assessment__count--niet">
				{{ t('procest', 'Withheld') }}: {{ counts.niet_openbaar }}
			</span>
			<span class="document-assessment__count document-assessment__count--pending">
				{{ t('procest', 'Pending') }}: {{ counts.pending }}
			</span>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

export default {
	name: 'DocumentAssessmentPanel',
	components: {
		NcButton,
		NcSelect,
	},
	props: {
		documents: {
			type: Array,
			default: () => [],
		},
		assessments: {
			type: Object,
			default: () => ({}),
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			assessmentOptions: [
				'openbaar',
				'deels_openbaar',
				'niet_openbaar',
			],
			weigeringsgronden: [
				{ code: '5.1.1', label: this.t('procest', '5.1.1 Eenheid van de Kroon') },
				{ code: '5.1.2', label: this.t('procest', '5.1.2 Veiligheid van de Staat') },
				{ code: '5.1.3', label: this.t('procest', '5.1.3 Bedrijfs- en fabricagegegevens') },
				{ code: '5.1.4', label: this.t('procest', '5.1.4 Persoonlijke beleidsopvattingen') },
				{ code: '5.1.5', label: this.t('procest', '5.1.5 Persoonlijke levenssfeer') },
				{ code: '5.2.1', label: this.t('procest', '5.2.1 Economische belangen Staat') },
				{ code: '5.2.2', label: this.t('procest', '5.2.2 Opsporing strafbare feiten') },
				{ code: '5.2.3', label: this.t('procest', '5.2.3 Inspectie en toezicht') },
				{ code: '5.2.4', label: this.t('procest', '5.2.4 Vertrouwelijkheid beraadslaging') },
				{ code: '5.2.5', label: this.t('procest', '5.2.5 Functioneren van de Staat') },
			],
		}
	},
	computed: {
		counts() {
			const result = { openbaar: 0, deels_openbaar: 0, niet_openbaar: 0, pending: 0 }
			for (const doc of this.documents) {
				const assessment = this.getAssessment(doc.id)
				if (assessment && result[assessment] !== undefined) {
					result[assessment]++
				} else {
					result.pending++
				}
			}
			return result
		},
	},
	methods: {
		getAssessment(docId) {
			return this.assessments[docId]?.assessment || null
		},
		getGrounds(docId) {
			return this.assessments[docId]?.grounds || []
		},
		setAssessment(docId, value) {
			this.$emit('update:assessment', {
				documentId: docId,
				assessment: value,
				grounds: value === 'niet_openbaar' ? (this.assessments[docId]?.grounds || []) : [],
			})
		},
		setGrounds(docId, value) {
			this.$emit('update:assessment', {
				documentId: docId,
				assessment: 'niet_openbaar',
				grounds: value,
			})
		},
	},
}
</script>

<style scoped>
.document-assessment__title {
	margin-bottom: 4px;
}

.document-assessment__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.document-assessment__empty {
	color: var(--color-text-maxcontrast);
	padding: 16px 0;
}

.document-assessment__summary {
	display: flex;
	gap: 16px;
	padding: 12px 0;
	flex-wrap: wrap;
}

.document-assessment__count {
	padding: 4px 8px;
	border-radius: var(--border-radius);
	font-size: 0.875rem;
}

.document-assessment__count--openbaar {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.document-assessment__count--deels {
	background: var(--color-warning-light, #fff3e0);
	color: var(--color-warning, #e65100);
}

.document-assessment__count--niet {
	background: var(--color-error-light, #ffebee);
	color: var(--color-error, #c62828);
}

.document-assessment__count--pending {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
