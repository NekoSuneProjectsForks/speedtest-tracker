export function sanitize(row) {
  if (!row || !Number.isSafeInteger(row.id) || row.id < 1 ||
      typeof row.timestamp !== 'string' || !Number.isFinite(Date.parse(row.timestamp))) return null;
  const result = { id: row.id, timestamp: new Date(row.timestamp).toISOString() };
  for (const key of ['download_mbps','upload_mbps','ping_ms','jitter_ms','packet_loss_pct']) {
    result[key] = typeof row[key] === 'number' && Number.isFinite(row[key]) ? row[key] : null;
  }
  return result;
}
