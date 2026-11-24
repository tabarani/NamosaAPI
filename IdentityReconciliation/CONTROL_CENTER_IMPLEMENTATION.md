# Yulpana School OS Control Center

This document records the productization direction for the Identity Provider and reconciliation service.

## Runtime configuration

Only infrastructure bootstrap values belong in environment configuration:

- `ConnectionStrings__DefaultConnection`
- `OpenIddict__IssuerUri`
- the ASP.NET Core Data Protection key-ring location
- optional one-time `Bootstrap__*` values for the first administrator and initial OIDC clients

Gibbon and Moodle URLs, tokens, database credentials, provisioning modes and first-login policies are managed at `/Admin/Systems`. Secrets are encrypted through ASP.NET Core Data Protection and are never redisplayed.

After the first administrator and clients are created, remove the `Bootstrap__*` environment values.

## Implemented foundation

- UI-managed Gibbon and Moodle connection profiles
- encrypted integration secrets
- connection health checks
- observe-only, approval-required and automatic provisioning modes
- per-system missing-account and first-login-claim policies
- extended identity migration/password state
- forced IdP password change support
- durable sync job, operation and attempt entities
- append-only audit event entity
- backward-compatible fallback to legacy appsettings connections
- idempotent schema initialization for existing deployments

## Next slices

1. Add Gibbon and Moodle credential-verification adapters for first-login account claiming.
2. Add the account activation wizard and password-reset notification provider.
3. Convert reconciliation to idempotent upserts and implement conflict resolution/ignore.
4. Add dry-run provisioning plans and approval workflow.
5. Add a durable background worker with classified exponential retry.
6. Move OIDC client registration and rotation into the Control Center.
7. Add user, course, category, role and enrolment synchronization policies.
8. Add integration and security tests plus CI secret scanning.

## Safety rule

Never copy or synchronize Gibbon or Moodle password hashes. First-login migration verifies the existing credential against its source system once, then requires a new IdP password.
