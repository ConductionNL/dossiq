<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('procest', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'My Work')"
				:to="{ name: 'MyWork' }">
				<template #icon>
					<AccountCheck :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Cases')"
				:to="{ name: 'Cases' }">
				<template #icon>
					<FolderOpen :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Tasks')"
				:to="{ name: 'Tasks' }">
				<template #icon>
					<ClipboardCheckOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Map')"
				:to="{ name: 'CaseMap' }">
				<template #icon>
					<MapOutline :size="20" />
				:name="t('procest', 'Voorstellen')"
				:to="{ name: 'Voorstellen' }">
				<template #icon>
					<FileDocumentEditOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Documentation')"
				@click="openLink('https://conduction.gitbook.io/procest-nextcloud', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('procest', 'Case Types')"
					:to="{ name: 'CaseTypes' }">
					<template #icon>
						<ShapeOutline :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('procest', 'Configuration')"
					:to="{ name: 'Settings' }">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import MapOutline from 'vue-material-design-icons/MapOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		ViewDashboard,
		AccountCheck,
		FolderOpen,
		ClipboardCheckOutline,
		FileDocumentEditOutline,
		BookOpenVariantOutline,
		MapOutline,
		Cog,
		ShapeOutline,
	},
	methods: {
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
