import { defineStore } from 'pinia'
import { ref } from 'vue'
import http from '@/lib/http'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token'))
  const user = ref(null)

  function setToken(newToken) {
    token.value = newToken
    localStorage.setItem('token', newToken)
  }

  async function fetchUser() {
    const { data } = await http.get('/me')
    user.value = data
    return data
  }

  function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
  }

  return { token, user, setToken, fetchUser, logout }
})
