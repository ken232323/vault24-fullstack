# Vault24 Customer App

React + Vite + Mantine + JavaScript. Runs on http://localhost:5173

```bash
npm install
npm run dev
```
`.env`: `VITE_USE_MOCK=true` uses the built-in mock API (demo@vault24.test / Password123!).
`VITE_USE_MOCK=false` (default) talks to the Vault24 PHP backend at `/api/v1` (Vite proxies `/api` to :8000, start it with `php -S localhost:8000 -t public public/index.php` in `../../backend`). `VITE_USE_MOCK=true` runs on the built-in mock.
Sign-up fields: name, email, bank account number (10 digits), password.
