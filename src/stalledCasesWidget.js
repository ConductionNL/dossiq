import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import StalledCasesWidget from './views/widgets/StalledCasesWidget.vue'
import { initializeStores } from './store/store.js'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_stalled_cases_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })

	// Register object types before mounting so the widget can fetch data
	new Vue({ pinia })
	await initializeStores()

	const View = Vue.extend(StalledCasesWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
