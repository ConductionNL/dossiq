import { createApp } from 'vue'
import pinia from './pinia.js'
import OverdueCasesWidget from './views/widgets/OverdueCasesWidget.vue'

OCA.Dashboard.register('procest_overdue_cases_widget', async (el, { widget }) => {
	const app = createApp(OverdueCasesWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
