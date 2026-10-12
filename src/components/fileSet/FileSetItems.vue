<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The items of a frozen file set (decision 182: generic, configured per case
  type): each item with its file name, classification and hash, and for an
  item whose file that went out is not its original a Compare action that
  opens the generic DocumentCompareDialog.

  What it needs from the case type comes from the widget's manifest `content`:
  - `filesUrl`: the compare endpoint, with `{case}`, `{set}` and `{index}`;
  - `classificationLabels`: the label per classification value;
  - `caseField` (default `case`) and `itemsField` (default `items`).
  The Woo case type's delivered set configures it on WooDeliveredSetDetail.
  It reads the set from the detail page (`objectData`); it writes nothing.

  @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
-->
<template>
	<div class="file-set-items" data-testid="file-set-items">
		<p v-if="rows.length === 0" class="file-set-items__empty">
			{{ t('dossiq', 'This set has no items.') }}
		</p>

		<table v-else class="file-set-items__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('dossiq', 'File name') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Classification') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'SHA-256') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{
							t('dossiq', 'Actions')
						}}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.index"
					:data-testid="'file-set-item-' + row.index">
					<td>{{ row.fileName }}</td>
					<td>{{ row.classificationLabel }}</td>
					<td class="file-set-items__hash">
						<code>{{ row.sha256 }}</code>
					</td>
					<td>
						<NcButton
							v-if="row.comparable"
							variant="secondary"
							:aria-label="
								t('dossiq', 'Compare {file} with the original', {
									file: row.fileName,
								})
							"
							:data-testid="'file-set-compare-' + row.index"
							@click="comparing = row.index">
							{{ t('dossiq', 'Compare') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<DocumentCompareDialog
			v-if="comparing !== null"
			:filesUrl="filesUrlFor(comparing)"
			@close="comparing = null" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import DocumentCompareDialog from '../../dialogs/DocumentCompareDialog.vue'

export default {
	name: 'FileSetItems',

	components: { NcButton, DocumentCompareDialog },

	props: {
		/** The set, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The set's data, bound by CnDetailWidgetHost. */
		objectData: {
			type: Object,
			default: () => ({}),
		},

		/** The case type's configuration, from the widget's manifest `content`. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			comparing: null,
		}
	},

	computed: {
		/** @return {string} The set id. */
		setId() {
			return String(
				this.objectId
					|| this.objectData?.id
					|| this.objectData?.['@self']?.id
					|| '',
			)
		},

		/** @return {string} The case the set belongs to. */
		caseId() {
			return String(this.objectData?.[this.content.caseField || 'case'] || '')
		},

		/**
		 * One row per item, in the set's own order; only an item whose file
		 * that went out is not its original compares, and only when the case
		 * type configured where the pair is read.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
		 */
		rows() {
			const labels = this.content.classificationLabels || {}
			const raw = this.objectData?.[this.content.itemsField || 'items']
			const items = Array.isArray(raw) ? raw : []
			const canCompare = Boolean(this.content.filesUrl) && this.caseId !== ''
			return items.map((item, index) => ({
				index,
				fileName: item.fileName || '',
				classificationLabel: labels[item.classification]
					? t('dossiq', labels[item.classification])
					: item.classification || '',
				sha256: item.sha256 || '',
				comparable:
					canCompare
					&& Boolean(item.deliveredRef)
					&& Boolean(item.originalRef)
					&& item.deliveredRef !== item.originalRef,
			}))
		},
	},

	methods: {
		t,

		/**
		 * The configured compare endpoint for one item.
		 *
		 * @param {number} index The item.
		 * @return {string} The URL.
		 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
		 */
		filesUrlFor(index) {
			return generateUrl(this.content.filesUrl, {
				case: this.caseId,
				set: this.setId,
				index,
			})
		},
	},
}
</script>

<style scoped>
.file-set-items__table {
	width: 100%;
	border-collapse: collapse;
}

.file-set-items__table th,
.file-set-items__table td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.file-set-items__hash code {
	font-size: 0.85em;
	word-break: break-all;
}

.file-set-items__empty {
	color: var(--color-text-maxcontrast);
}
</style>
