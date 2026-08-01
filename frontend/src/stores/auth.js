import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import http from '@/lib/http'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token'))
  const user = ref(null)

  // False while the stored token is still being checked, so views can tell
  // "not logged in" from "we do not know yet" and avoid flashing a signed-out
  // state on reload.
  const ready = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))

  function setToken(newToken) {
    token.value = newToken
    localStorage.setItem('token', newToken)
  }

  /** Resources come back wrapped in `data`; auth endpoints return the user flat. */
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
   * Restore the session held in localStorage. Safe to call repeatedly - it
   * only ever does the work once per page load.
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
      // A token that no longer works is the same as no token; the 401
      // interceptor has already cleared it.
      clear()
    } finally {
      ready.value = true
    }
  }

  /** Drop local session state without calling the API. */
  function clear() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
  }

  async function logout() {
    try {
      await http.post('/auth/logout')
    } catch {
      // Revoking server-side is best effort; the local session goes either way.
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
