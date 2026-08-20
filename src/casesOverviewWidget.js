import { createApp } from 'vue'
import CasesOverviewWidget from './views/widgets/CasesOverviewWidget.vue'
import pinia from './pinia.js'

OCA.Dashboard.register('procest_cases_overview_widget', async (el, { widget }) => {
	const app = createApp(CasesOverviewWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
