<script setup>
import { computed } from 'vue'
import { formatTimeRange } from '@/lib/format'

const props = defineProps({
  activity: { type: Object, required: true },
})

const joined = computed(() => props.activity.my_registration?.status?.value === 1)

const filledPercent = computed(() =>
  props.activity.capacity ? (props.activity.joined_count / props.activity.capacity) * 100 : 0,
)
</script>

<template>
  <RouterLink :to="{ name: 'activity', params: { id: activity.id } }" class="activity-card card">
    <div class="head">
      <h3>{{ activity.title }}</h3>
      <span v-if="joined" class="tag tag-accent">已報名</span>
      <span v-else-if="activity.is_full" class="tag tag-danger">已額滿</span>
    </div>

    <div class="tag-row">
      <span v-if="activity.sport" class="tag">{{ activity.sport.name }}</span>
      <span v-if="activity.district" class="tag">
        {{ activity.district.city?.name }}{{ activity.district.name }}
      </span>
    </div>

    <p class="subtle when">{{ formatTimeRange(activity.starts_at, activity.ends_at) }}</p>
    <p class="subtle">{{ activity.location }}</p>

    <div class="seats">
      <div class="meter" :class="{ 'meter-full': activity.is_full }">
        <span :style="{ width: `${filledPercent}%` }" />
      </div>
      <p class="subtle">{{ activity.joined_count }} / {{ activity.capacity }} 人 · 剩 {{ activity.remaining_seats }} 位</p>
    </div>
  </RouterLink>
</template>

<style scoped>
.activity-card {
  display: block;
  color: inherit;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.activity-card:hover {
  text-decoration: none;
  border-color: var(--border-strong);
  box-shadow: var(--shadow);
}

.head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.head h3 {
  font-size: 1rem;
}

.when {
  margin-top: 0.6rem;
}

.seats {
  margin-top: 0.9rem;
  display: grid;
  gap: 0.35rem;
}
</style>
