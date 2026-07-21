<script setup>
import { onMounted } from 'vue'
import HelloWorld from '@/components/HelloWorld.vue'
import TheWelcome from '@/components/TheWelcome.vue'
import { useAuthStore } from '@/stores/auth'
import http from '@/lib/http'

const auth = useAuthStore()

onMounted(() => {
  if (auth.token && !auth.user) {
    auth.fetchUser().catch(() => auth.logout())
  }
})

async function loginWithLine() {
  const { data } = await http.get('/auth/line/redirect')
  window.location.href = data.url
}
</script>

<template>
  <header>
    <img alt="Vue logo" class="logo" src="@/assets/logo.svg" width="125" height="125" />

    <div class="wrapper">
      <HelloWorld msg="You did it!" />

      <div v-if="auth.user">
        <p>你好，{{ auth.user.name }}</p>
        <button @click="auth.logout()">登出</button>
      </div>
      <button v-else @click="loginWithLine">使用 LINE 登入</button>
    </div>
  </header>

  <main>
    <TheWelcome />
  </main>
</template>

<style scoped>
header {
  line-height: 1.5;
}

.logo {
  display: block;
  margin: 0 auto 2rem;
}

@media (min-width: 1024px) {
  header {
    display: flex;
    place-items: center;
    padding-right: calc(var(--section-gap) / 2);
  }

  .logo {
    margin: 0 2rem 0 0;
  }

  header .wrapper {
    display: flex;
    place-items: flex-start;
    flex-wrap: wrap;
  }
}
</style>
