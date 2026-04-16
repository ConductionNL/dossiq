import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import StartCaseWidget from './views/widgets/StartCaseWidget.vue'
import { initializeStores } from './store/store.js'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_start_case_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })

	// Register object types before mounting so the widget can fetch data
	// A temporary Vue instance is needed to activate the Pinia context
	new Vue({ pinia })
	await initializeStores()

	const View = Vue.extend(StartCaseWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
