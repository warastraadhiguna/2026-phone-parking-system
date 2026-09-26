# ADR-0004: Admin web with Laravel + Inertia.js + React + TypeScript

- Status: Accepted (owner decision Q1, 2026-09-25)
- Date: 2026-09-25
- Supersedes: the initial proposal recommending Blade + Livewire (and the Vue option)

## Context

The control center serves Dishub admins, operators, finance, supervisors, auditors and
executives (master doc §4.2). It needs rich tables, dashboards and maps. It must not add a
second authentication system, and it must not become a separate application.

## Decision

- **Laravel + Inertia.js (v3) + React (19) + TypeScript (strict)** inside `backend/`.
- Frontend code lives in `backend/resources/js/`: `Pages/`, `Components/`, `Layouts/`,
  `hooks/`, `types/`. Vite builds it into `public/build`.
- React is only the presentation layer. Routing, authorization, validation and all business
  rules stay in Laravel. Pages receive server-decided data as props.
- **Authentication is Laravel session auth with CSRF protection** (web guard). No JWT, no
  token auth for the admin UI, no separate API for it.
- No separate `admin-web/` application, no Vue, no SSR for now (`INERTIA_SSR_ENABLED=false`).
- Inertia DevTools is disabled by default (`INERTIA_DEVTOOLS_ENABLED=false`), because it
  persists request and page data to disk.

## Consequences

- One deployment and one auth model. Server-side authorization protects every page, and
  existing Laravel testing tools (`assertInertia`) apply.
- A Node toolchain is needed to build assets (the `node` compose service). Production images
  must include the build output.
- The JSON API under `/api/v1` stays dedicated to mobile and machine clients.
