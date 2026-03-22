import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import DoorlooptijdDashboard from '../views/DoorlooptijdDashboard.vue'
import MyWork from '../views/MyWork.vue'
import CaseList from '../views/cases/CaseList.vue'
import CaseDetail from '../views/cases/CaseDetail.vue'
import TaskList from '../views/tasks/TaskList.vue'
import TaskDetail from '../views/tasks/TaskDetail.vue'
import VoorstelList from '../views/voorstellen/VoorstelList.vue'
import VoorstelDetail from '../views/voorstellen/VoorstelDetail.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/procest'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/doorlooptijd', name: 'Doorlooptijd', component: DoorlooptijdDashboard },
		{ path: '/my-work', name: 'MyWork', component: MyWork },
		{ path: '/cases', name: 'Cases', component: CaseList },
		{ path: '/cases/:id', name: 'CaseDetail', component: CaseDetail, props: route => ({ caseId: route.params.id }) },
		{ path: '/tasks', name: 'Tasks', component: TaskList },
		{ path: '/tasks/new', name: 'TaskNew', component: TaskDetail, props: route => ({ taskId: 'new', caseIdProp: route.query.caseId || null }) },
		{ path: '/tasks/:id', name: 'TaskDetail', component: TaskDetail, props: route => ({ taskId: route.params.id }) },
		{ path: '/voorstellen', name: 'Voorstellen', component: VoorstelList },
		{ path: '/voorstellen/:id', name: 'VoorstelDetail', component: VoorstelDetail, props: route => ({ voorstelId: route.params.id }) },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '/case-types', name: 'CaseTypes', component: AdminRoot },
		{ path: '*', redirect: '/' },
	],
})
