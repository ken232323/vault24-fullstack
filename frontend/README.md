# Vault24 Frontend
- `customer-app/` customer system (http://localhost:5173)
- `admin-app/`    staff/admin system (http://localhost:5174)

Both are wired to the PHP backend in `../backend` (see the root README). Each app's `src/services/api.js` is the only file
that knows the wire format; pages are unchanged. Set `VITE_USE_MOCK=true` in an app's `.env` to run it on the built-in
mock instead (no backend needed).

```bash
cd customer-app && npm install && npm run dev      # :5173
cd admin-app    && npm install && npm run dev      # :5174
```
Vite proxies `/api` to `http://localhost:8000`, so the HttpOnly session cookie stays same-origin.
