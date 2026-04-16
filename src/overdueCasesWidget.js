import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import OverdueCasesWidget from './views/widgets/OverdueCasesWidget.vue'
import { initializeStores } from './store/store.js'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_overdue_cases_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })

	// Register object types before mounting so the widget can fetch data
	// A temporary Vue instance is needed to activate the Pinia context
	new Vue({ pinia })
	await initializeStores()

	const View = Vue.extend(OverdueCasesWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
