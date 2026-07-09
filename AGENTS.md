# Non-negotiable backend rules

Read `../constitution.md`, `../architecture.md`, and `../domain.md` before writing any code.

Use the `laravel-api` agent for API/service work in this directory (controllers, services, models, migrations, jobs, tests).

Use the `filament-admin` agent for anything under the admin panel (Filament resources, panel config, admin-only auth guard) — even though it lives in the same Laravel app, it's a distinct specialization from the mobile-facing API.
