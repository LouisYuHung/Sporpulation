/**
 * The API speaks one error shape: `message` always, `errors` on 422, and a
 * stable `code` on the 409s that registration can raise. These helpers pull
 * those apart so views do not each reinvent the same optional chaining.
 */

/** Message safe to show the user; already localised by the backend. */
export function errorMessage(error, fallback = '發生錯誤，請稍後再試。') {
  return error?.response?.data?.message || fallback
}

/** Per-field validation messages from a 422, flattened to one line each. */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors ?? {}

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

/** Machine code from a 409, e.g. 'activity_full' or 'activity_closed'. */
export function conflictCode(error) {
  return error?.response?.status === 409 ? error.response.data?.code : null
}
