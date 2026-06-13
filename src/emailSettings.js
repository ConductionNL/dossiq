import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import EmailSettings from './views/settings/EmailSettings.vue'
import { CnVersionInfoCard, CnSettingsSection } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

const appVersion = loadState('procest', 'version', 'Unknown')

new Vue({
	pinia,
	render: h => h(CnVersionInfoCard, {
		props: {
			appName: 'Procest',
			appVersion,
			isUpToDate: true,
			title: t('procest', 'Case email — shared mailbox'),
			description: t('procest', 'Shared functional mailbox ingest and template settings'),
		},
	}, [
		h(CnSettingsSection, {
			props: {
				name: t('procest', 'Shared mailbox (IMAP)'),
				description: t('procest', 'Inbound poller connection and case-correspondence transport'),
			},
		}, [h(EmailSettings)]),
	]),
}).$mount('#procest-email-settings')
