import { createApp } from 'vue'
import pinia from './pinia.js'
import DeadlineAlertsWidget from './views/widgets/DeadlineAlertsWidget.vue'

OCA.Dashboard.register('procest_deadline_alerts_widget', async (el, { widget }) => {
	const app = createApp(DeadlineAlertsWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
