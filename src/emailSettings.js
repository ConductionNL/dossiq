import { CnSettingsSection, CnVersionInfoCard } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { createApp, h } from 'vue'
import EmailSettings from './views/settings/EmailSettings.vue'
import pinia from './pinia.js'

const appVersion = loadState('dossiq', 'version', 'Unknown')

// Vue 3: props pass FLAT in h(); component children (arrays) become the default slot.
const app = createApp({
	render: () =>
		h(
			CnVersionInfoCard,
			{
				appName: 'Dossiq',
				appVersion,
				isUpToDate: true,
				title: t('dossiq', 'Case email — shared mailbox'),
				description: t(
					'dossiq',
					'Shared functional mailbox ingest and template settings',
				),
			},
			[
				h(
					CnSettingsSection,
					{
						name: t('dossiq', 'Shared mailbox (IMAP)'),
						description: t(
							'dossiq',
							'Inbound poller connection and case-correspondence transport',
						),
					},
					[h(EmailSettings)],
				),
			],
		),
})
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#dossiq-email-settings')
