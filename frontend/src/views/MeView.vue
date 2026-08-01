<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '@/lib/http'
import { useAuthStore } from '@/stores/auth'
import { errorMessage } from '@/lib/errors'
import ActivityCard from '@/components/ActivityCard.vue'

const auth = useAuthStore()

const registrations = ref([])
const cities = ref([])
const sports = ref([])

const newDistrictId = ref('')
const newSportId = ref('')
const newSportLevel = ref('')

const error = ref('')
const loading = ref(true)
const busy = ref(false)

const user = computed(() => auth.user)

// 完整清單扣掉使用者已經擁有的項目，讓選擇器不會列出重複的選項 - 那些選項送到
// API 後也只會被併回既有的那一列。
const availableSports = computed(() => {
  const taken = new Set((user.value?.sports ?? []).map((sport) => sport.id))

  return sports.value.filter((sport) => !taken.has(sport.id))
})

const availableCities = computed(() => {
  const taken = new Set((user.value?.areas ?? []).map((area) => area.id))

  return cities.value
    .map((city) => ({
      ...city,
      districts: city.districts.filter((district) => !taken.has(district.id)),
    }))
    .filter((city) => city.districts.length > 0)
})

async function load() {
  loading.value = true

  try {
    const [me, registrationsResponse, regions, sportCatalogue] = await Promise.all([
      auth.fetchUser(),
      http.get('/me/registrations'),
      http.get('/regions'),
      http.get('/sports'),
    ])

    registrations.value = registrationsResponse.data.data
    cities.value = regions.data.data
    sports.value = sportCatalogue.data.data

    return me
  } catch (e) {
    error.value = errorMessage(e, '無法載入個人資料。')
  } finally {
    loading.value = false
  }
}

/** 執行一次寫入，接著重新取回個人資料，讓畫面顯示的是實際已儲存的內容。 */
async function mutate(request) {
  busy.value = true
  error.value = ''

  try {
    await request()
    await auth.fetchUser()
  } catch (e) {
    error.value = errorMessage(e, '操作失敗，請再試一次。')
  } finally {
    busy.value = false
  }
}

async function addArea() {
  if (!newDistrictId.value) return

  const districtId = newDistrictId.value
  newDistrictId.value = ''

  await mutate(() => http.post('/me/areas', { district_id: districtId }))
}

async function removeArea(districtId) {
  await mutate(() => http.delete(`/me/areas/${districtId}`))
}

async function addSport() {
  if (!newSportId.value) return

  const sportId = newSportId.value
  const level = newSportLevel.value
  newSportId.value = ''
  newSportLevel.value = ''

  await mutate(() =>
    http.post('/me/sports', {
      sport_id: sportId,
      level: level === '' ? null : Number(level),
    }),
  )
}

async function setSportLevel(sportId, level) {
  await mutate(() =>
    http.patch(`/me/sports/${sportId}`, { level: level === '' ? null : Number(level) }),
  )
}

async function removeSport(sportId) {
  await mutate(() => http.delete(`/me/sports/${sportId}`))
}

onMounted(load)
</script>

<template>
  <div class="page">
    <p v-if="loading" class="subtle">載入中…</p>

    <template v-else-if="user">
      <div v-if="error" class="alert alert-error">{{ error }}</div>

      <div class="card profile">
        <img v-if="user.avatar" :src="user.avatar" :alt="user.name" class="avatar" />
        <div v-else class="avatar placeholder">{{ user.name?.[0] ?? '?' }}</div>

        <div>
          <h1>{{ user.nickname || user.name }}</h1>
          <p class="subtle">{{ user.email || '尚未設定 Email' }}</p>
          <p v-if="user.sex" class="subtle">{{ user.sex.label }}</p>
        </div>
      </div>

      <section class="section">
        <h2>常用地區</h2>

        <div class="tag-row" :class="{ 'is-busy': busy }">
          <span v-for="area in user.areas" :key="area.id" class="tag tag-removable">
            {{ area.city?.name }}{{ area.name }}
            <button class="tag-remove" title="移除" @click="removeArea(area.id)">×</button>
          </span>
          <span v-if="!user.areas?.length" class="subtle">還沒有加入任何地區。</span>
        </div>

        <div class="form-row add-row">
          <div class="field">
            <label for="area">加入地區</label>
            <select id="area" v-model="newDistrictId" class="input">
              <option value="">選擇地區…</option>
              <optgroup v-for="city in availableCities" :key="city.id" :label="city.name">
                <option
                  v-for="district in city.districts"
                  :key="district.id"
                  :value="district.id"
                >
                  {{ district.name }}
                </option>
              </optgroup>
            </select>
          </div>
          <button class="btn btn-secondary" :disabled="busy || !newDistrictId" @click="addArea">
            加入
          </button>
        </div>
      </section>

      <section class="section">
        <h2>可參與的運動</h2>

        <div v-if="user.sports?.length" class="card-list sport-list">
          <div v-for="sport in user.sports" :key="sport.id" class="card sport">
            <span class="sport-name">{{ sport.name }}</span>

            <div class="sport-controls">
              <label :for="`level-${sport.id}`" class="subtle">程度</label>
              <select
                :id="`level-${sport.id}`"
                class="input level"
                :value="sport.level ?? ''"
                :disabled="busy"
                @change="setSportLevel(sport.id, $event.target.value)"
              >
                <option value="">未評</option>
                <option v-for="level in 10" :key="level" :value="level">{{ level }}</option>
              </select>

              <button class="btn btn-ghost" :disabled="busy" @click="removeSport(sport.id)">
                移除
              </button>
            </div>
          </div>
        </div>
        <p v-else class="subtle">還沒有加入任何運動。</p>

        <div class="form-row add-row">
          <div class="field">
            <label for="sport">加入運動</label>
            <select id="sport" v-model="newSportId" class="input">
              <option value="">選擇運動…</option>
              <option v-for="sport in availableSports" :key="sport.id" :value="sport.id">
                {{ sport.name }}
              </option>
            </select>
          </div>

          <div class="field level-field">
            <label for="new-level">程度</label>
            <select id="new-level" v-model="newSportLevel" class="input">
              <option value="">未評</option>
              <option v-for="level in 10" :key="level" :value="level">{{ level }}</option>
            </select>
          </div>

          <button class="btn btn-secondary" :disabled="busy || !newSportId" @click="addSport">
            加入
          </button>
        </div>
      </section>

      <section class="section">
        <h2>我報名的活動</h2>

        <div v-if="registrations.length" class="card-list">
          <ActivityCard
            v-for="registration in registrations"
            :key="registration.id"
            :activity="registration.activity"
          />
        </div>
        <p v-else class="empty">還沒有報名任何活動。</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.profile {
  display: flex;
  align-items: center;
  gap: 1.1rem;
}

.profile h1 {
  font-size: 1.3rem;
}

.avatar.placeholder {
  display: grid;
  place-items: center;
  font-size: 1.4rem;
  font-weight: 600;
  color: var(--text-soft);
}

.is-busy {
  opacity: 0.6;
}

.add-row {
  margin-top: 1rem;
}

.level-field {
  flex: 0 1 6rem;
}

.sport-list {
  gap: 0.6rem;
}

.sport {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.7rem 1rem;
}

.sport-name {
  font-weight: 550;
}

.sport-controls {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.level {
  width: auto;
  padding: 0.3rem 0.5rem;
  font-size: 0.85rem;
}
</style>
