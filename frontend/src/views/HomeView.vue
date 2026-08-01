<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import http from '@/lib/http'
import ActivityCard from '@/components/ActivityCard.vue'
import { errorMessage } from '@/lib/errors'

const activities = ref([])
const sports = ref([])
const cities = ref([])

const filters = reactive({ sport_id: '', district_id: '' })

const loading = ref(true)
const error = ref('')

async function loadActivities() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get('/activities', {
      // 空白代表「不篩選」；送出空字串會無法通過驗證。
      params: {
        sport_id: filters.sport_id || undefined,
        district_id: filters.district_id || undefined,
      },
    })
    activities.value = data.data
  } catch (e) {
    error.value = errorMessage(e, '無法載入活動。')
  } finally {
    loading.value = false
  }
}

async function loadFilterOptions() {
  const [sportsResponse, regionsResponse] = await Promise.all([
    http.get('/sports'),
    http.get('/regions'),
  ])

  sports.value = sportsResponse.data.data
  cities.value = regionsResponse.data.data
}

onMounted(() => {
  loadActivities()
  loadFilterOptions().catch(() => {
    // 篩選只是輔助功能；就算沒有它，列表照樣能運作。
  })
})

watch(filters, loadActivities)
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1>找活動</h1>
        <p class="subtle">挑一場即將開始的活動，按下報名就佔一個名額。</p>
      </div>
    </div>

    <div class="form-row filters">
      <div class="field">
        <label for="sport">運動</label>
        <select id="sport" v-model="filters.sport_id" class="input">
          <option value="">全部</option>
          <option v-for="sport in sports" :key="sport.id" :value="sport.id">
            {{ sport.name }}
          </option>
        </select>
      </div>

      <div class="field">
        <label for="district">地區</label>
        <select id="district" v-model="filters.district_id" class="input">
          <option value="">全部</option>
          <optgroup v-for="city in cities" :key="city.id" :label="city.name">
            <option v-for="district in city.districts" :key="district.id" :value="district.id">
              {{ district.name }}
            </option>
          </optgroup>
        </select>
      </div>
    </div>

    <div v-if="error" class="alert alert-error">{{ error }}</div>

    <p v-if="loading" class="subtle">載入中…</p>

    <div v-else-if="activities.length" class="card-list">
      <ActivityCard v-for="activity in activities" :key="activity.id" :activity="activity" />
    </div>

    <p v-else class="empty">目前沒有符合條件的活動。</p>
  </div>
</template>

<style scoped>
.filters {
  margin-bottom: 1.5rem;
}
</style>
