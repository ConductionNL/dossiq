import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import DeadlineAlertsWidget from './views/widgets/DeadlineAlertsWidget.vue'
import { initializeStores } from './store/store.js'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_deadline_alerts_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })

	// Register object types before mounting so the widget can fetch data
	new Vue({ pinia })
	await initializeStores()

	const View = Vue.extend(DeadlineAlertsWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
