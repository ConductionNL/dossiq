<template>
  <div class="doorlooptijd-dashboard">
    <div class="dashboard-header">
      <h1>{{ t('app', 'Processing Time Dashboard') }}</h1>
      <div class="filters">
        <input
          v-model="filters.caseType"
          type="text"
          :placeholder="t('app', 'Case Type')"
          class="filter-input"
        >
        <input
          v-model="filters.startDate"
          type="date"
          class="filter-input"
        >
        <input
          v-model="filters.endDate"
          type="date"
          class="filter-input"
        >
        <button @click="fetchData">{{ t('app', 'Apply Filters') }}</button>
      </div>
    </div>

    <div v-if="loading" class="loading">
      {{ t('app', 'Loading data...') }}
    </div>

    <div v-else class="dashboard-content">
      <!-- SLA Adherence Chart -->
      <div class="chart-card">
        <h2>{{ t('app', 'SLA Adherence Trend') }}</h2>
        <div class="chart-placeholder">
          <p>{{ slaTrendData.direction }}</p>
          <p>{{ slaTrendData.changePercentage }}% change</p>
        </div>
      </div>

      <!-- Bottleneck Analysis -->
      <div class="chart-card">
        <h2>{{ t('app', 'Process Step Bottlenecks') }}</h2>
        <div class="bottleneck-list">
          <div
            v-for="step in bottleneckData.steps"
            :key="step.id"
            class="bottleneck-item"
          >
            <span class="step-name">{{ step.name }}</span>
            <span class="step-duration">{{ step.avgDuration }} days</span>
            <div class="progress-bar">
              <div
                :style="{ width: step.percentageOfTotal + '%' }"
                class="progress-fill"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Trend Analysis -->
      <div class="chart-card">
        <h2>{{ t('app', 'Historical Trends') }}</h2>
        <div class="trend-placeholder">
          <p>{{ trendData.granularity }} view</p>
          <p>{{ trendData.direction }}</p>
        </div>
      </div>

      <!-- Statistics -->
      <div class="stats-card">
        <div class="stat">
          <h3>{{ t('app', 'Average Processing Time') }}</h3>
          <p class="stat-value">{{ statistics.averageDuration }} days</p>
        </div>
        <div class="stat">
          <h3>{{ t('app', 'SLA Adherence') }}</h3>
          <p class="stat-value">{{ statistics.slaAdherence.percentage }}%</p>
        </div>
        <div class="stat">
          <h3>{{ t('app', 'Closed Cases') }}</h3>
          <p class="stat-value">{{ statistics.closedCases }} / {{ statistics.totalCases }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * Doorlooptijd Dashboard View
 *
 * Main dashboard view for processing time analytics and reporting.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-12
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'DoorlooptijdDashboard',
  data() {
    return {
      t,
      loading: false,
      filters: {
        caseType: '',
        startDate: '',
        endDate: '',
      },
      statistics: {
        totalCases: 0,
        closedCases: 0,
        averageDuration: 0,
        slaAdherence: {
          percentage: 0,
        },
      },
      bottleneckData: {
        steps: [],
      },
      trendData: {
        granularity: 'weekly',
        direction: 'stable',
      },
      slaTrendData: {
        direction: 'stable',
        changePercentage: 0,
      },
    }
  },
  computed: {
    caseTypeParam() {
      return this.filters.caseType || 'default'
    },
  },
  mounted() {
    this.fetchData()
  },
  methods: {
    async fetchData() {
      this.loading = true
      try {
        const baseUrl = generateUrl('/apps/procest/api/doorlooptijd')

        // Fetch statistics
        const statsResponse = await axios.get(`${baseUrl}/statistics`, {
          params: {
            caseTypeId: this.caseTypeParam,
            startDate: this.filters.startDate,
            endDate: this.filters.endDate,
          },
        })
        this.statistics = statsResponse.data

        // Fetch bottleneck analysis
        const bottlenecksResponse = await axios.get(`${baseUrl}/bottlenecks`, {
          params: {
            caseTypeId: this.caseTypeParam,
            startDate: this.filters.startDate,
            endDate: this.filters.endDate,
          },
        })
        this.bottleneckData = bottlenecksResponse.data

        // Fetch trends
        const trendsResponse = await axios.get(`${baseUrl}/trends`, {
          params: {
            caseTypeId: this.caseTypeParam,
            startDate: this.filters.startDate,
            endDate: this.filters.endDate,
          },
        })
        this.trendData = trendsResponse.data

        // Fetch SLA trend
        const slaTrendResponse = await axios.get(`${baseUrl}/sla-trend`, {
          params: {
            caseTypeId: this.caseTypeParam,
            startDate: this.filters.startDate,
            endDate: this.filters.endDate,
          },
        })
        this.slaTrendData = slaTrendResponse.data
      } catch (error) {
        console.error('Error fetching dashboard data:', error)
      } finally {
        this.loading = false
      }
    },
  },
}
</script>

<style scoped>
.doorlooptijd-dashboard {
  padding: 20px;
  max-width: 1400px;
  margin: 0 auto;
}

.dashboard-header {
  margin-bottom: 30px;
}

.dashboard-header h1 {
  margin: 0 0 20px 0;
  font-size: 28px;
}

.filters {
  display: flex;
  gap: 10px;
  align-items: center;
}

.filter-input {
  padding: 8px 12px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 14px;
}

.filter-input:focus {
  outline: none;
  border-color: #0082c9;
}

button {
  padding: 8px 16px;
  background-color: #0082c9;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

button:hover {
  background-color: #006ba3;
}

.loading {
  text-align: center;
  padding: 40px;
  font-size: 16px;
  color: #666;
}

.dashboard-content {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
  gap: 20px;
}

.chart-card,
.stats-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.chart-card h2 {
  margin: 0 0 15px 0;
  font-size: 18px;
}

.chart-placeholder {
  height: 250px;
  background: #f5f5f5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  color: #999;
  font-size: 14px;
}

.bottleneck-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.bottleneck-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.step-name {
  font-weight: 500;
  font-size: 14px;
}

.step-duration {
  font-size: 12px;
  color: #666;
}

.progress-bar {
  height: 8px;
  background: #f0f0f0;
  border-radius: 4px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #0082c9, #00aae4);
  transition: width 0.3s;
}

.trend-placeholder {
  height: 250px;
  background: #f5f5f5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  color: #999;
  font-size: 14px;
}

.stats-card {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
}

.stat {
  text-align: center;
}

.stat h3 {
  margin: 0 0 10px 0;
  font-size: 14px;
  color: #666;
  text-transform: uppercase;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.stat-value {
  margin: 0;
  font-size: 32px;
  font-weight: 700;
  color: #0082c9;
}
</style>
