# Namosa API Module for Gibbon Educore

A RESTful API layer that transforms Gibbon into a connected platform for mobile apps, third-party integrations, and external services.

## Features

- ✅ JWT-based authentication with OAuth2-style client credentials
- ✅ Rate limiting and security hardening
- ✅ CORS support for web/mobile apps
- ✅ Comprehensive student endpoints (CRUD + relationships)
- ✅ Repository pattern for clean data access
- ✅ Automatic database setup on module installation
- ✅ Detailed API logging and auditing
- ✅ Pagination and filtering support
- ✅ Extensible architecture for custom endpoints

## Installation

### 1. Copy Module Files

```bash
cd /path/to/gibbon/modules
git clone https://github.com/yulpana/namosa-api NamosaAPI
# OR upload the NamosaAPI folder manually
```

## Yulpana identity control plane

The .NET Identity Provider, SSO, reconciliation, account-provisioning policies, operation logs, and bidirectional Moodle/Gibbon grade-transfer orchestration are maintained separately in:

**https://github.com/tabarani/IdentityReconciliation**

NamosaAPI remains the Gibbon module and API/data gateway. Do not add another copy of the .NET solution to this repository; keeping one canonical source prevents the IdP and integration logic from diverging.
