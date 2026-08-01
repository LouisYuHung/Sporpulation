<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { errorMessage, fieldErrors } from '@/lib/errors'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const mode = ref('login')
const isRegister = computed(() => mode.value === 'register')

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const errors = ref({})
const generalError = ref('')
const submitting = ref(false)

function switchMode(next) {
  mode.value = next
  errors.value = {}
  generalError.value = ''
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = ''

  try {
    if (isRegister.value) {
      await auth.register({
        name: form.name,
        email: form.email,
        password: form.password,
        password_confirmation: form.password_confirmation,
      })
    } else {
      await auth.login({ email: form.email, password: form.password })
    }

    router.replace(route.query.redirect || { name: 'me' })
  } catch (error) {
    errors.value = fieldErrors(error)

    // Only surface a banner when nothing landed on a field, so the same
    // problem is never reported twice.
    if (Object.keys(errors.value).length === 0) {
      generalError.value = errorMessage(error)
    }
  } finally {
    submitting.value = false
  }
}

async function signInWithLine() {
  generalError.value = ''

  try {
    await auth.loginWithLine()
  } catch (error) {
    generalError.value = errorMessage(error, '無法連線到 LINE 登入。')
  }
}
</script>

<template>
  <div class="page login-page">
    <div class="card login-card">
      <h1>{{ isRegister ? '建立帳號' : '登入' }}</h1>
      <p class="subtle">{{ isRegister ? '註冊後就能報名活動。' : '歡迎回來。' }}</p>

      <div v-if="generalError" class="alert alert-error">{{ generalError }}</div>

      <form @submit.prevent="submit">
        <div v-if="isRegister" class="field">
          <label for="name">名稱</label>
          <input id="name" v-model="form.name" class="input" autocomplete="name" required />
          <p v-if="errors.name" class="field-error">{{ errors.name }}</p>
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            class="input"
            autocomplete="email"
            required
          />
          <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
        </div>

        <div class="field">
          <label for="password">密碼</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            class="input"
            :autocomplete="isRegister ? 'new-password' : 'current-password'"
            required
          />
          <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
        </div>

        <div v-if="isRegister" class="field">
          <label for="password_confirmation">再輸入一次密碼</label>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            type="password"
            class="input"
            autocomplete="new-password"
            required
          />
        </div>

        <button class="btn btn-block" type="submit" :disabled="submitting">
          {{ submitting ? '處理中…' : isRegister ? '註冊' : '登入' }}
        </button>
      </form>

      <div class="divider"><span>或</span></div>

      <button class="btn btn-line btn-block" @click="signInWithLine">使用 LINE 登入</button>

      <p class="switch subtle">
        <template v-if="isRegister">
          已經有帳號了？
          <a href="#" @click.prevent="switchMode('login')">登入</a>
        </template>
        <template v-else>
          還沒有帳號？
          <a href="#" @click.prevent="switchMode('register')">建立一個</a>
        </template>
      </p>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  display: flex;
  align-items: flex-start;
  justify-content: center;
}

.login-card {
  width: 100%;
  max-width: 24rem;
  margin-top: 2rem;
  padding: 1.75rem;
}

.login-card h1 {
  font-size: 1.35rem;
}

.login-card > .subtle {
  margin-bottom: 1.5rem;
}

form {
  margin-top: 0.25rem;
}

form .btn {
  margin-top: 0.5rem;
}

.divider {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 1.25rem 0;
  color: var(--text-soft);
  font-size: 0.8rem;
}

.divider::before,
.divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

.switch {
  margin-top: 1.25rem;
  text-align: center;
}
</style>
