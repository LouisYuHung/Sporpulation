import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import http from '@/lib/http'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token'))
  const user = ref(null)

  // 在檢查已儲存的 token 期間為 false，讓畫面能分辨「未登入」與「還不確定」，
  // 避免重新整理時閃過一瞬間的未登入狀態。
  const ready = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))

  function setToken(newToken) {
    token.value = newToken
    localStorage.setItem('token', newToken)
  }

  /** Resource 會包在 `data` 裡回傳；認證端點則直接回傳扁平的使用者物件。 */
  async function fetchUser() {
    const { data } = await http.get('/me')
    user.value = data.data
    return user.value
  }

  async function register(payload) {
    const { data } = await http.post('/auth/register', payload)
    setToken(data.token)
    user.value = data.user
    ready.value = true
  }

  async function login(credentials) {
    const { data } = await http.post('/auth/login', credentials)
    setToken(data.token)
    user.value = data.user
    ready.value = true
  }

  async function loginWithLine() {
    const { data } = await http.get('/auth/line/redirect')
    window.location.href = data.url
  }

  /**
   * 還原存放在 localStorage 的登入狀態。可以安全地重複呼叫 - 每次頁面載入實際
   * 上只會執行一次。
   */
  async function restore() {
    if (ready.value) return

    if (!token.value) {
      ready.value = true
      return
    }

    try {
      await fetchUser()
    } catch {
      // 已失效的 token 等同於沒有 token；401 攔截器早已把它清除。
      clear()
    } finally {
      ready.value = true
    }
  }

  /** 清除本地登入狀態，不呼叫 API。 */
  function clear() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
  }

  async function logout() {
    try {
      await http.post('/auth/logout')
    } catch {
      // 伺服器端撤銷屬盡力而為；無論成功與否，本地登入狀態都會被清掉。
    }

    clear()
  }

  return {
    token,
    user,
    ready,
    isAuthenticated,
    setToken,
    fetchUser,
    register,
    login,
    loginWithLine,
    restore,
    clear,
    logout,
  }
})
