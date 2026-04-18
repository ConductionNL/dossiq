<template>
  <div class="reporting-page">
    <div class="page-header">
      <h1>{{ t('app', 'Management Report') }}</h1>
    </div>

    <div class="report-container">
      <!-- Filters -->
      <div class="filters-panel">
        <h3>{{ t('app', 'Filters') }}</h3>
        <div class="filter-group">
          <label>{{ t('app', 'Case Type') }}</label>
          <select v-model="selectedFilters.caseType" class="filter-select">
            <option value="">{{ t('app', 'All') }}</option>
            <option
              v-for="(label, value) in filterOptions.caseTypes"
              :key="value"
              :value="value"
            >
              {{ label }}
            </option>
          </select>
        </div>

        <div class="filter-group">
          <label>{{ t('app', 'Team') }}</label>
          <select v-model="selectedFilters.team" class="filter-select">
            <option value="">{{ t('app', 'All') }}</option>
            <option
              v-for="(label, value) in filterOptions.teams"
              :key="value"
              :value="value"
            >
              {{ label }}
            </option>
          </select>
        </div>

        <div class="filter-group">
          <label>{{ t('app', 'Status') }}</label>
          <select v-model="selectedFilters.status" class="filter-select">
            <option value="">{{ t('app', 'All') }}</option>
            <option
              v-for="(label, value) in filterOptions.statuses"
              :key="value"
              :value="value"
            >
              {{ label }}
            </option>
          </select>
        </div>

        <div class="filter-group">
          <label>{{ t('app', 'Start Date') }}</label>
          <input v-model="selectedFilters.startDate" type="date" class="filter-input">
        </div>

        <div class="filter-group">
          <label>{{ t('app', 'End Date') }}</label>
          <input v-model="selectedFilters.endDate" type="date" class="filter-input">
        </div>

        <button @click="applyFilters" class="btn-apply">
          {{ t('app', 'Apply Filters') }}
        </button>
        <button @click="exportReport" class="btn-export">
          {{ t('app', 'Export') }}
        </button>
      </div>

      <!-- Report Content -->
      <div v-if="loading" class="loading">
        {{ t('app', 'Loading report...') }}
      </div>

      <div v-else class="report-content">
        <!-- Summary Cards -->
        <div class="summary-cards">
          <div class="card">
            <h4>{{ t('app', 'Total Cases') }}</h4>
            <p class="value">{{ report.summary.totalCases }}</p>
          </div>
          <div class="card">
            <h4>{{ t('app', 'Average Doorlooptijd') }}</h4>
            <p class="value">{{ report.summary.averageDoorlooptijd }} days</p>
          </div>
          <div class="card">
            <h4>{{ t('app', 'SLA Adherence') }}</h4>
            <p class="value">{{ report.summary.slaAdherence.percentage }}%</p>
          </div>
          <div class="card">
            <h4>{{ t('app', 'Overdue Cases') }}</h4>
            <p class="value">{{ report.summary.slaAdherence.overdue }}</p>
          </div>
        </div>

        <!-- Data Table -->
        <div class="data-table">
          <h3>{{ t('app', 'Case Details') }}</h3>
          <table>
            <thead>
              <tr>
                <th>{{ t('app', 'Case ID') }}</th>
                <th>{{ t('app', 'Type') }}</th>
                <th>{{ t('app', 'Created') }}</th>
                <th>{{ t('app', 'Doorlooptijd (days)') }}</th>
                <th>{{ t('app', 'SLA Status') }}</th>
                <th>{{ t('app', 'Team') }}</th>
                <th>{{ t('app', 'Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="caseItem in report.data" :key="caseItem.caseId">
                <td>{{ caseItem.caseId }}</td>
                <td>{{ caseItem.caseType }}</td>
                <td>{{ caseItem.createdAt }}</td>
                <td>{{ caseItem.doorlooptijd }}</td>
                <td :class="'sla-' + caseItem.slaStatus">{{ caseItem.slaStatus }}</td>
                <td>{{ caseItem.team }}</td>
                <td>{{ caseItem.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * Reporting Page Component
 *
 * Management reporting view with filterable data and export capabilities.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-13
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'ReportingPage',
  data() {
    return {
      t,
      loading: false,
      selectedFilters: {
        caseType: '',
        team: '',
        status: '',
        startDate: '',
        endDate: '',
      },
      filterOptions: {
        caseTypes: {},
        teams: {},
        statuses: {},
      },
      report: {
        summary: {
          totalCases: 0,
          averageDoorlooptijd: 0,
          slaAdherence: {
            percentage: 0,
            overdue: 0,
          },
        },
        data: [],
      },
    }
  },
  mounted() {
    this.loadFilterOptions()
    this.loadReport()
  },
  methods: {
    async loadFilterOptions() {
      try {
        const response = await axios.get(generateUrl('/apps/procest/api/reports/filters'))
        this.filterOptions = response.data
      } catch (error) {
        console.error('Error loading filter options:', error)
      }
    },

    async loadReport() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/procest/api/reports/doorlooptijd'), {
          params: this.selectedFilters,
        })
        this.report = response.data
      } catch (error) {
        console.error('Error loading report:', error)
      } finally {
        this.loading = false
      }
    },

    async applyFilters() {
      await this.loadReport()
    },

    async exportReport() {
      try {
        const response = await axios.get(
          generateUrl('/apps/procest/api/reports/doorlooptijd/export'),
          {
            params: {
              format: 'csv',
              ...this.selectedFilters,
            },
            responseType: 'blob',
          }
        )

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', 'doorlooptijd-report.csv')
        document.body.appendChild(link)
        link.click()
        link.parentNode.removeChild(link)
      } catch (error) {
        console.error('Error exporting report:', error)
      }
    },
  },
}
</script>

<style scoped>
.reporting-page {
  padding: 20px;
  max-width: 1600px;
  margin: 0 auto;
}

.page-header {
  margin-bottom: 30px;
}

.page-header h1 {
  margin: 0;
  font-size: 28px;
}

.report-container {
  display: grid;
  grid-template-columns: 250px 1fr;
  gap: 20px;
}

.filters-panel {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 20px;
  height: fit-content;
  position: sticky;
  top: 20px;
}

.filters-panel h3 {
  margin: 0 0 20px 0;
  font-size: 16px;
}

.filter-group {
  margin-bottom: 15px;
  display: flex;
  flex-direction: column;
}

.filter-group label {
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 5px;
  text-transform: uppercase;
  color: #666;
}

.filter-select,
.filter-input {
  padding: 8px 12px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 14px;
}

.filter-select:focus,
.filter-input:focus {
  outline: none;
  border-color: #0082c9;
  box-shadow: 0 0 0 2px rgba(0, 130, 201, 0.1);
}

.btn-apply,
.btn-export {
  width: 100%;
  padding: 10px 15px;
  margin-bottom: 10px;
  border: none;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  font-weight: 500;
  transition: background-color 0.2s;
}

.btn-apply {
  background-color: #0082c9;
  color: white;
}

.btn-apply:hover {
  background-color: #006ba3;
}

.btn-export {
  background-color: #f5f5f5;
  color: #333;
  border: 1px solid #ddd;
}

.btn-export:hover {
  background-color: #f0f0f0;
}

.loading {
  text-align: center;
  padding: 40px;
  color: #999;
}

.report-content {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 20px;
}

.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
  margin-bottom: 30px;
}

.card {
  background: #f9f9f9;
  border: 1px solid #f0f0f0;
  border-radius: 6px;
  padding: 15px;
  text-align: center;
}

.card h4 {
  margin: 0 0 10px 0;
  font-size: 12px;
  color: #999;
  text-transform: uppercase;
  font-weight: 600;
}

.card .value {
  margin: 0;
  font-size: 28px;
  font-weight: 700;
  color: #0082c9;
}

.data-table {
  margin-top: 30px;
}

.data-table h3 {
  margin: 0 0 15px 0;
  font-size: 16px;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

table thead {
  background: #f5f5f5;
}

table th {
  padding: 12px;
  text-align: left;
  font-weight: 600;
  color: #333;
  border-bottom: 2px solid #e0e0e0;
}

table td {
  padding: 12px;
  border-bottom: 1px solid #f0f0f0;
}

table tbody tr:hover {
  background: #f9f9f9;
}

.sla-within {
  color: #28a745;
  font-weight: 600;
}

.sla-overdue {
  color: #dc3545;
  font-weight: 600;
}

@media (max-width: 768px) {
  .report-container {
    grid-template-columns: 1fr;
  }

  .filters-panel {
    position: static;
  }

  .summary-cards {
    grid-template-columns: repeat(2, 1fr);
  }

  table {
    font-size: 12px;
  }

  table th,
  table td {
    padding: 8px;
  }
}
</style>
