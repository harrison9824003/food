/** 本機時區的 YYYY-MM-DD（不用 toISOString，它會換成 UTC，台灣清晨會差一天）。 */
export function todayString(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}
