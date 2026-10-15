# Vault24 Admin (Staff) App

Separate app from the customer app. Runs on http://localhost:5174

```bash
npm install
npm run dev
```
Mock logins (Password123!): admin@vault24.test, staff@vault24.test. No sign-up: admins create staff/admin accounts at /staff.
Uses `/api/admin/*` endpoints and should use its own session cookie name.
