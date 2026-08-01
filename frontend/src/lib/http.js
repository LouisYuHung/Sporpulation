import axios from 'axios'
import { useAuthStore } from '@/stores/auth'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
})

http.interceptors.request.use((config) => {
  const auth = useAuthStore()
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`
  }
  config.headers['Accept-Language'] = localStorage.getItem('locale') || 'zh-TW'
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error) => {
    // clear(), not logout(): the token is already dead, and calling the
    // logout endpoint here would just 401 again.
    if (error.response?.status === 401) {
      useAuthStore().clear()
    }
    return Promise.reject(error)
  },
)

export default http
