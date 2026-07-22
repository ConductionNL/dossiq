import { createApp } from 'vue'
import pinia from './pinia.js'
import CasesOverviewWidget from './views/widgets/CasesOverviewWidget.vue'

OCA.Dashboard.register('procest_cases_overview_widget', async (el, { widget }) => {
	const app = createApp(CasesOverviewWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
