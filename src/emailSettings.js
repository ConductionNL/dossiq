import { createApp, h } from 'vue'
import pinia from './pinia.js'
import EmailSettings from './views/settings/EmailSettings.vue'
import { CnVersionInfoCard, CnSettingsSection } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'

const appVersion = loadState('procest', 'version', 'Unknown')

// Vue 3: props pass FLAT in h(); component children (arrays) become the default slot.
const app = createApp({
	render: () =>
		h(
			CnVersionInfoCard,
			{
				appName: 'Procest',
				appVersion,
				isUpToDate: true,
				title: t('procest', 'Case email — shared mailbox'),
				description: t(
					'procest',
					'Shared functional mailbox ingest and template settings',
				),
			},
			[
				h(
					CnSettingsSection,
					{
						name: t('procest', 'Shared mailbox (IMAP)'),
						description: t(
							'procest',
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
app.mount('#procest-email-settings')
