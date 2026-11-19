// Invelis fiecare endpoint: orice eroare devine JSON clar, nu pagina HTML de la Vercel.
export function safe(handler) {
  return async function wrapped(req, res) {
    try {
      if (typeof req.body === 'string') {
        try { req.body = JSON.parse(req.body); } catch { req.body = {}; }
      }
      return await handler(req, res);
    } catch (err) {
      console.error('API error:', err);
      return res.status(500).json({
        ok: false,
        reason: err.code || 'server_error',
        error: String((err && err.message) || err),
      });
    }
  };
}
