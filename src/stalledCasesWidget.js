import { createApp } from 'vue'
import pinia from './pinia.js'
import StalledCasesWidget from './views/widgets/StalledCasesWidget.vue'

OCA.Dashboard.register('procest_stalled_cases_widget', async (el, { widget }) => {
	const app = createApp(StalledCasesWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
