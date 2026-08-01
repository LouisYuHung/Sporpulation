<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const failed = ref(false)

onMounted(async () => {
  const token = route.query.token

  if (!token) {
    router.replace({ name: 'login' })
    return
  }

  auth.setToken(token)

  try {
    await auth.fetchUser()
  } catch {
    auth.clear()
    failed.value = true
    return
  }

  router.replace({ name: 'me' })
})
</script>

<template>
  <div class="page callback">
    <p v-if="failed" class="alert alert-error">登入失敗，請再試一次。</p>
    <p v-else class="subtle">登入中…</p>
  </div>
</template>

<style scoped>
.callback {
  text-align: center;
  padding-top: 4rem;
}
</style>
