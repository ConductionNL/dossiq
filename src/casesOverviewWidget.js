import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import CasesOverviewWidget from './views/widgets/CasesOverviewWidget.vue'
import { initializeStores } from './store/store.js'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_cases_overview_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })

	// Register object types before mounting so the widget can fetch data
	new Vue({ pinia })
	await initializeStores()

	const View = Vue.extend(CasesOverviewWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
