# Identity reconciliation moved

The Yulpana .NET 8 Identity Provider and integration control plane has moved to its canonical repository:

**https://github.com/tabarani/IdentityReconciliation**

That repository owns:

- OpenID Connect SSO for Gibbon and Moodle
- identity reconciliation and first-login migration state
- Control Center configuration and encrypted integration secrets
- account-provisioning policies and operation logs
- Moodle-to-Gibbon and Gibbon-to-Moodle semester-grade transfer

This repository owns the NamosaAPI Gibbon module, its PHP APIs, database migrations and Gibbon-facing integration gateway.

## Migration note

The former `IdentityReconciliation/` directory was removed after the canonical repository was established. Existing deployments are not changed automatically. Build, publish, configure and deploy the IdP from `tabarani/IdentityReconciliation`; deploy this repository independently as the Gibbon module.
