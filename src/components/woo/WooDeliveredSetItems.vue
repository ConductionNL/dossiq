<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The items of one delivered Woo set, each with what went out, its
  classification and its hash, and for an item whose delivered file is not
  its original (a redaction) a Compare action that opens the original beside
  it (woo-delivered-set-is-a-record REQ-WDS-004).

  It reads the set from the detail page (`objectData`); it writes nothing.

  @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
-->
<template>
	<div class="woo-set-items" data-testid="woo-delivered-set-items">
		<p v-if="rows.length === 0" class="woo-set-items__empty">
			{{ t('dossiq', 'This set has no items.') }}
		</p>

		<table v-else class="woo-set-items__table">
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
					:data-testid="'woo-delivered-set-item-' + row.index">
					<td>{{ row.fileName }}</td>
					<td>{{ row.classificationLabel }}</td>
					<td class="woo-set-items__hash">
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
							:data-testid="'woo-compare-' + row.index"
							@click="comparing = row.index">
							{{ t('dossiq', 'Compare') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<WooCompareDialog
			v-if="comparing !== null"
			:caseId="caseId"
			:setId="setId"
			:index="comparing"
			@close="comparing = null" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import WooCompareDialog from '../../dialogs/WooCompareDialog.vue'

export default {
	name: 'WooDeliveredSetItems',

	components: { NcButton, WooCompareDialog },

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
			return String(this.objectData?.case || '')
		},

		/**
		 * One row per item, in the set's own order; only a redacted item compares.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
		 */
		rows() {
			const labels = {
				openbaar: t('dossiq', 'Public'),
				deels_openbaar: t('dossiq', 'Disclosed in part'),
			}
			const items = Array.isArray(this.objectData?.items)
				? this.objectData.items
				: []
			return items.map((item, index) => ({
				index,
				fileName: item.fileName || '',
				classificationLabel:
					labels[item.classification] || item.classification || '',
				sha256: item.sha256 || '',
				comparable:
					this.caseId !== ''
					&& Boolean(item.deliveredRef)
					&& Boolean(item.originalRef)
					&& item.deliveredRef !== item.originalRef,
			}))
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.woo-set-items__table {
	width: 100%;
	border-collapse: collapse;
}

.woo-set-items__table th,
.woo-set-items__table td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.woo-set-items__hash code {
	font-size: 0.85em;
	word-break: break-all;
}

.woo-set-items__empty {
	color: var(--color-text-maxcontrast);
}
</style>
