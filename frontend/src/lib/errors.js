/**
 * API 的錯誤格式只有一種：一定有 `message`，422 時多帶 `errors`，報名可能拋出的
 * 409 則多帶一個固定的 `code`。這些輔助函式負責把它們拆開，讓各個畫面不必各自
 * 重寫同一串可選鏈存取。
 */

/** 可以安全顯示給使用者的訊息；後端已完成在地化。 */
export function errorMessage(error, fallback = '發生錯誤，請稍後再試。') {
  return error?.response?.data?.message || fallback
}

/** 422 回應中各欄位的驗證訊息，每個欄位壓成一行。 */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors ?? {}

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

/** 409 回應中的機器碼，例如 'activity_full' 或 'activity_closed'。 */
export function conflictCode(error) {
  return error?.response?.status === 409 ? error.response.data?.code : null
}
