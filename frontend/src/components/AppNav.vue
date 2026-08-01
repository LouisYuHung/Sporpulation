<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function signOut() {
  await auth.logout()
  router.push({ name: 'home' })
}
</script>

<template>
  <nav class="nav">
    <div class="nav-inner">
      <RouterLink :to="{ name: 'home' }" class="brand">Sporpulation</RouterLink>

      <div class="nav-links">
        <RouterLink :to="{ name: 'home' }">找活動</RouterLink>

        <template v-if="auth.isAuthenticated">
          <RouterLink :to="{ name: 'me' }">我的</RouterLink>
          <button class="btn btn-ghost" @click="signOut">登出</button>
        </template>

        <RouterLink v-else :to="{ name: 'login' }" class="btn">登入</RouterLink>
      </div>
    </div>
  </nav>
</template>

<style scoped>
.nav {
  border-bottom: 1px solid var(--border);
  background: var(--surface);
  position: sticky;
  top: 0;
  z-index: 10;
}

.nav-inner {
  max-width: var(--page-width);
  margin: 0 auto;
  padding: 0.75rem 1.25rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.brand {
  font-weight: 700;
  font-size: 1.05rem;
  color: var(--text);
  letter-spacing: -0.02em;
}

.brand:hover {
  text-decoration: none;
  color: var(--accent-text);
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.nav-links a {
  color: var(--text-soft);
  font-size: 0.9rem;
}

.nav-links a:hover {
  color: var(--text);
  text-decoration: none;
}

.nav-links a.router-link-active {
  color: var(--text);
  font-weight: 550;
}

.nav-links a.btn {
  color: var(--text-invert);
}
</style>
