/**
 * Router Configuration for Procest App
 *
 * Defines routes for doorlooptijd dashboard and reporting views.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-14
 */

import Vue from 'vue'
import Router from 'vue-router'

// Import views
import DoorlooptijdDashboard from '../views/DoorlooptijdDashboard.vue'
import ReportingPage from '../views/ReportingPage.vue'

Vue.use(Router)

/**
 * Create and export router instance
 */
export default new Router({
  mode: 'history',
  base: '/apps/procest',
  routes: [
    {
      path: '/doorlooptijd',
      name: 'DoorlooptijdDashboard',
      component: DoorlooptijdDashboard,
      meta: {
        title: 'Processing Time Dashboard',
        requiresAuth: true,
      },
    },
    {
      path: '/reporting',
      name: 'ReportingPage',
      component: ReportingPage,
      meta: {
        title: 'Management Report',
        requiresAuth: true,
      },
    },
  ],
})
