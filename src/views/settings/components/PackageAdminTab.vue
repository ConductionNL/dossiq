<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The configuration one installed package shipped, on the admin tab the
  package declares (`package.json` `adminTab`): one card per declared list,
  one row per item the package installed here, each row opening the editor
  for that item where the app has one. Generic: the package declares the
  lists, the server reads what it installed from the shipped-set ledger.

  @spec openspec/changes/vth-cases-run-end-to-end
-->
<template>
	<div class="dq-package-tab" :data-testid="`package-tab-${pkg.id}`">
		<CnSettingsSection
			v-for="list in lists"
			:id="`section-package-${pkg.id}-${list.name}`"
			:key="list.name"
			:name="list.label"
			:description="t('dossiq', 'Installed by {package} {version}', { package: pkg.title, version: pkg.version })">
			<p v-if="list.items.length === 0" class="dq-package-tab__empty">
				{{ t('dossiq', 'This package installed nothing here.') }}
			</p>
			<ul v-else class="dq-package-tab__items">
				<li
					v-for="item in list.items"
					:key="item.id"
					class="dq-package-tab__item"
					:data-testid="`package-item-${list.name}`">
					<a v-if="item.url" :href="item.url">{{ item.label }}</a>
					<span v-else>{{ item.label }}</span>
					<span v-if="item.status" class="dq-package-tab__status">{{ item.status }}</span>
				</li>
			</ul>
		</CnSettingsSection>
	</div>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	packageItemStatus,
	packageItemUrl,
	packageListLabel,
} from '../../../utils/packageAdminTab.js'

export default {
	name: 'PackageAdminTab',

	components: {
		CnSettingsSection,
	},

	props: {
		/**
		 * One installed package with a tab, as the server lists it:
		 * `{ id, title, version, lists: { <list>: [{ id, title, status }] } }`.
		 */
		pkg: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * The declared lists, in declared order, with their rows ready to draw.
		 *
		 * @return {Array<{name: string, label: string, items: Array<object>}>} The lists.
		 * @spec openspec/changes/vth-cases-run-end-to-end
		 */
		lists() {
			const lists = this.pkg?.lists && typeof this.pkg.lists === 'object' ? this.pkg.lists : {}
			return Object.entries(lists).map(([name, items]) => ({
				name,
				label: packageListLabel(name),
				items: (Array.isArray(items) ? items : []).map((item) => ({
					id: String(item.id),
					label: item.title || String(item.id),
					status: packageItemStatus(item.status),
					url: packageItemUrl(name, item.id),
				})),
			}))
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.dq-package-tab__items {
	margin: 0;
	padding: 0;
	list-style: none;
}

.dq-package-tab__item {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: baseline;
	padding-block: var(--default-grid-baseline, 4px);
	border-bottom: 1px solid var(--color-border);
}

.dq-package-tab__item a {
	color: var(--color-main-text);
	text-decoration: underline;
}

.dq-package-tab__status,
.dq-package-tab__empty {
	color: var(--color-text-maxcontrast);
}
</style>
