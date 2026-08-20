import { createApp } from 'vue'
import StartCaseWidget from './views/widgets/StartCaseWidget.vue'
import pinia from './pinia.js'

OCA.Dashboard.register('procest_start_case_widget', async (el, { widget }) => {
	const app = createApp(StartCaseWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
