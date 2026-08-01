<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/lib/http'
import { useAuthStore } from '@/stores/auth'
import { conflictCode, errorMessage } from '@/lib/errors'
import { formatTimeRange } from '@/lib/format'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const auth = useAuthStore()
const router = useRouter()

const activity = ref(null)
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const notice = ref('')

const joined = computed(() => activity.value?.my_registration?.status?.value === 1)
const filledPercent = computed(() =>
  activity.value?.capacity ? (activity.value.joined_count / activity.value.capacity) * 100 : 0,
)

// Full is only a blocker for someone who is not already in.
const canJoin = computed(
  () => activity.value?.is_open && !joined.value && !activity.value?.is_full,
)

async function load() {
  loading.value = true

  try {
    const { data } = await http.get(`/activities/${props.id}`)
    activity.value = data.data
  } catch (e) {
    error.value = errorMessage(e, '找不到這個活動。')
  } finally {
    loading.value = false
  }
}

async function join() {
  if (!auth.isAuthenticated) {
    router.push({ name: 'login', query: { redirect: `/activities/${props.id}` } })
    return
  }

  await submit(() => http.post(`/activities/${props.id}/registration`), '報名成功。')
}

async function cancel() {
  await submit(() => http.delete(`/activities/${props.id}/registration`), '已取消報名。')
}

/**
 * Both writes return the activity as it now stands, so the seat count and the
 * caller's own status refresh straight from the response - no reload needed.
 */
async function submit(request, successMessage) {
  submitting.value = true
  error.value = ''
  notice.value = ''

  try {
    const { data } = await request()
    activity.value = data.data
    notice.value = successMessage
  } catch (e) {
    const code = conflictCode(e)

    // A 409 means the world moved on while the page sat here, so show the
    // reason and refresh to whatever is true now.
    if (code) {
      error.value = errorMessage(e)
      await load()
    } else {
      error.value = errorMessage(e, '操作失敗，請再試一次。')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <p v-if="loading" class="subtle">載入中…</p>

    <template v-else-if="activity">
      <RouterLink :to="{ name: 'home' }" class="subtle back">← 回到活動列表</RouterLink>

      <div class="page-header">
        <div>
          <h1>{{ activity.title }}</h1>
          <div class="tag-row">
            <span v-if="activity.sport" class="tag">{{ activity.sport.name }}</span>
            <span v-if="activity.district" class="tag">
              {{ activity.district.city?.name }}{{ activity.district.name }}
            </span>
            <span v-if="joined" class="tag tag-accent">已報名</span>
            <span v-else-if="activity.is_full" class="tag tag-danger">已額滿</span>
            <span v-if="!activity.is_open" class="tag tag-danger">已截止</span>
          </div>
        </div>
      </div>

      <div v-if="error" class="alert alert-error">{{ error }}</div>
      <div v-else-if="notice" class="alert alert-info">{{ notice }}</div>

      <div class="card detail">
        <dl>
          <div>
            <dt>時間</dt>
            <dd>{{ formatTimeRange(activity.starts_at, activity.ends_at) }}</dd>
          </div>
          <div>
            <dt>地點</dt>
            <dd>{{ activity.location }}</dd>
          </div>
          <div v-if="activity.host">
            <dt>主辦</dt>
            <dd>{{ activity.host.nickname || activity.host.name }}</dd>
          </div>
        </dl>

        <p v-if="activity.description" class="description">{{ activity.description }}</p>

        <div class="seats">
          <div class="meter" :class="{ 'meter-full': activity.is_full }">
            <span :style="{ width: `${filledPercent}%` }" />
          </div>
          <p class="subtle">
            {{ activity.joined_count }} / {{ activity.capacity }} 人 · 剩
            {{ activity.remaining_seats }} 位
          </p>
        </div>

        <div class="actions">
          <button v-if="joined" class="btn btn-danger" :disabled="submitting" @click="cancel">
            {{ submitting ? '處理中…' : '取消報名' }}
          </button>

          <button v-else class="btn" :disabled="submitting || !canJoin" @click="join">
            <template v-if="submitting">處理中…</template>
            <template v-else-if="!activity.is_open">已截止報名</template>
            <template v-else-if="activity.is_full">名額已滿</template>
            <template v-else>我要報名</template>
          </button>

          <p v-if="!auth.isAuthenticated && canJoin" class="subtle">報名前需要先登入。</p>
        </div>
      </div>
    </template>

    <div v-else class="empty">{{ error || '找不到這個活動。' }}</div>
  </div>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 1rem;
}

.back:hover {
  text-decoration: none;
  color: var(--text);
}

.detail dl {
  display: grid;
  gap: 0.6rem;
}

.detail dl > div {
  display: flex;
  gap: 1rem;
}

.detail dt {
  width: 3.5rem;
  flex: none;
  color: var(--text-soft);
  font-size: 0.875rem;
}

.description {
  margin-top: 1.1rem;
  padding-top: 1.1rem;
  border-top: 1px solid var(--border);
  white-space: pre-wrap;
}

.seats {
  margin-top: 1.4rem;
  display: grid;
  gap: 0.35rem;
}

.actions {
  margin-top: 1.4rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
</style>
