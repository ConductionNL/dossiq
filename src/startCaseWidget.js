import { createApp } from 'vue'
import pinia from './pinia.js'
import StartCaseWidget from './views/widgets/StartCaseWidget.vue'

OCA.Dashboard.register('procest_start_case_widget', async (el, { widget }) => {
	const app = createApp(StartCaseWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
