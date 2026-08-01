const DATE_TIME = new Intl.DateTimeFormat('zh-TW', {
  month: 'numeric',
  day: 'numeric',
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
})

const TIME = new Intl.DateTimeFormat('zh-TW', {
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
})

/** e.g. "8/3 (日) 19:00 – 21:00", collapsing the end date when it is the same day. */
export function formatTimeRange(startsAt, endsAt) {
  const start = new Date(startsAt)
  const end = new Date(endsAt)

  const sameDay = start.toDateString() === end.toDateString()

  return `${DATE_TIME.format(start)} – ${sameDay ? TIME.format(end) : DATE_TIME.format(end)}`
}

export function formatDateTime(value) {
  return DATE_TIME.format(new Date(value))
}
