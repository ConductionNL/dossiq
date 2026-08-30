import { createApp } from 'vue'
import OverdueCasesWidget from './views/widgets/OverdueCasesWidget.vue'
import pinia from './pinia.js'

OCA.Dashboard.register('procest_overdue_cases_widget', async (el, { widget }) => {
	const app = createApp(OverdueCasesWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
