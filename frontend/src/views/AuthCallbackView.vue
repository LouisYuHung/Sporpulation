<script setup>
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

onMounted(async () => {
  const token = route.query.token
  if (!token) {
    router.replace('/')
    return
  }

  auth.setToken(token)

  try {
    await auth.fetchUser()
  } catch {
    auth.logout()
  }

  router.replace('/')
})
</script>

<template>
  <p>登入中...</p>
</template>
