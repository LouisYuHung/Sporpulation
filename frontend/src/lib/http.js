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
    // 用 clear() 而非 logout()：token 早已失效，此時再呼叫登出端點只會再拿到
    // 一次 401。
    if (error.response?.status === 401) {
      useAuthStore().clear()
    }
    return Promise.reject(error)
  },
)

/**
 * 為寫入請求加上冪等 key 的請求設定，讓伺服器能認出這是同一個意圖的重試，重播
 * 第一次的回應而不是執行第二次。
 */
export function idempotent(key) {
  return { headers: { 'Idempotency-Key': key } }
}

export function newIdempotencyKey() {
  return crypto.randomUUID()
}

export default http
