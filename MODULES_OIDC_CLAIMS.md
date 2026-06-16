# OIDC Claims Structure for Gibbon Modules

This document describes the expected OpenID Connect (OIDC) claims structure that the .NET Identity Provider (IdP) must send and what the Gibbon modules expect.

## Required Claims

The following claims **must** be present in every ID token or userinfo response:

| Claim | Description | Example |
|-------|-------------|---------|
| `sub` | Unique subject identifier. This is the primary claim used to identify the user in Gibbon. | `"1234567890"` |
| `iss` | Issuer identifier. The URL of the IdP that issued the token. | `"https://idp.example.com"` |
| `aud` | Audience. Must match the client_id or identifier registered for the Gibbon application. | `"gibbon-modules"` |
| `exp` | Expiration time. Unix timestamp indicating when the token expires. | `1699999999` |

### Claim Details

#### `sub` (Subject)
- **Type:** String
- **Required:** Yes
- **Purpose:** Uniquely identifies the user at the IdP
- **Mapping to Gibbon:** See "User Mapping" section below

#### `iss` (Issuer)
- **Type:** String (URI)
- **Required:** Yes
- **Purpose:** Identifies the principal that issued the JWT
- **Validation:** Must match the configured issuer URL in Gibbon settings

#### `aud` (Audience)
- **Type:** String or Array of strings
- **Required:** Yes
- **Purpose:** Identifies the recipients that the JWT is intended for
- **Validation:** Must contain the client_id configured in Gibbon

#### `exp` (Expiration Time)
- **Type:** Integer (Unix timestamp)
- **Required:** Yes
- **Purpose:** Specifies the expiration time on or after which the JWT must not be accepted for processing

## Optional/Custom Claims

The following claims are optional but recommended for full integration:

| Claim | Description | Example |
|-------|-------------|---------|
| `email` | User's email address | `"user@example.com"` |
| `name` | User's full display name | `"John Doe"` |
| `given_name` | User's first name | `"John"` |
| `family_name` | User's last name | `"Doe"` |
| `roles` | Array of user roles/permissions | `["admin", "teacher"]` |
| `gibbon_id` | Legacy Gibbon person ID mapping | `"GS00123"` |

### Claim Details

#### `email`
- **Type:** String
- **Required:** No (but recommended)
- **Purpose:** Used for user notifications and as a fallback identifier

#### `name`
- **Type:** String
- **Required:** No (but recommended)
- **Purpose:** Display name for the user in the Gibbon UI

#### `roles`
- **Type:** Array of strings
- **Required:** No
- **Purpose:** Defines user permissions and access levels within Gibbon
- **Expected values:** Common roles include:
  - `admin` - Full administrative access
  - `teacher` - Teaching staff access
  - `parent` - Parent/guardian access
  - `student` - Student access
  - `support` - Support staff access

#### `gibbon_id`
- **Type:** String
- **Required:** No (legacy support)
- **Purpose:** Maps to legacy Gibbon identifier for backward compatibility
- **Note:** This claim is deprecated in favor of using `sub` for user mapping

## User Mapping

### How the `sub` Claim Maps to Gibbon Users

The `sub` claim is used to identify users in Gibbon using the following priority:

1. **Primary Mapping: `gibbonPerson.username`**
   - The `sub` claim value is matched against the `username` field in the `gibbonPerson` table
   - This is the recommended approach for new deployments
   - Example: If `sub` = `"jsmith"`, it matches `gibbonPerson.username = 'jsmith'`

2. **Secondary Mapping: `gibbonPerson.gibbonPersonID`**
   - If no username match is found, the system attempts to match against the numeric `gibbonPersonID`
   - The `sub` claim should contain only the numeric ID in this case
   - Example: If `sub` = `"12345"`, it matches `gibbonPerson.gibbonPersonID = 12345`

3. **Legacy Mapping: `gibbon_id` Custom Claim**
   - If the `gibbon_id` custom claim is present, it can be used as a fallback
   - This supports legacy student/staff IDs (e.g., `"GS00123"`)
   - Example: If `gibbon_id` = `"GS00123"`, it matches against the `studentID` or `staffID` field

### Mapping Configuration

The mapping behavior can be configured in the module settings:

```php
// Example configuration in Gibbon settings
$oidcConfig = [
    'user_mapping_field' => 'username', // Options: 'username', 'gibbonPersonID', 'gibbon_id'
    'fallback_to_sub_numeric' => true,   // Try numeric sub if username fails
];
```

## Sample Decoded JWT Payload

Below is an example of a decoded JWT payload that meets the requirements:

```json
{
  "iss": "https://idp.example.com",
  "sub": "jsmith",
  "aud": "gibbon-modules",
  "exp": 1699999999,
  "iat": 1699996399,
  "nbf": 1699996399,
  "jti": "abc123def456",
  "email": "john.smith@example.com",
  "name": "John Smith",
  "given_name": "John",
  "family_name": "Smith",
  "preferred_username": "jsmith",
  "roles": ["teacher", "homeroom_teacher"],
  "gibbon_id": "STF00123"
}
```

### Minimal Valid Payload

For basic authentication, the minimum viable payload is:

```json
{
  "iss": "https://idp.example.com",
  "sub": "12345",
  "aud": "gibbon-modules",
  "exp": 1699999999
}
```

## Implementation Notes

### For .NET IdP Administrators

1. **Ensure Consistent `sub` Values**
   - The `sub` claim must remain consistent for the same user across all tokens
   - Do not change the `sub` value for existing users

2. **Token Lifetime**
   - Set reasonable `exp` values (recommended: 1 hour for ID tokens)
   - Implement refresh token rotation for long-lived sessions

3. **HTTPS Required**
   - All OIDC endpoints must use HTTPS
   - The `iss` claim must use the HTTPS scheme

4. **Claim Names are Case-Sensitive**
   - All standard OIDC claims are lowercase
   - Custom claims should follow the same convention

### For Gibbon Module Developers

1. **Always Validate Token Signature**
   - Verify the JWT signature using the IdP's public keys
   - Use the `jwks_uri` from the IdP's discovery document

2. **Validate All Required Claims**
   - Check presence of `sub`, `iss`, `aud`, and `exp`
   - Validate `iss` matches configured issuer
   - Validate `aud` contains expected audience
   - Validate `exp` has not passed

3. **Handle Missing Optional Claims Gracefully**
   - Provide defaults when optional claims are missing
   - Log warnings for missing recommended claims

## Troubleshooting

### Common Issues

| Issue | Possible Cause | Solution |
|-------|---------------|----------|
| "Invalid token" | Missing required claim | Ensure all required claims are present |
| "Token expired" | `exp` timestamp in past | Check server time synchronization |
| "Invalid issuer" | `iss` mismatch | Verify IdP URL in Gibbon settings |
| "User not found" | `sub` doesn't map to user | Check user mapping configuration |
| "Invalid audience" | `aud` mismatch | Verify client_id configuration |

### Debugging Tips

1. Decode the JWT at [jwt.io](https://jwt.io) to inspect claims
2. Enable debug logging in Gibbon OIDC module
3. Verify IdP discovery document is accessible
4. Check network connectivity between Gibbon and IdP

## References

- [OpenID Connect Core Specification](https://openid.net/specs/openid-connect-core-1_0.html)
- [JSON Web Token (JWT) RFC 7519](https://tools.ietf.org/html/rfc7519)
- [OAuth 2.0 RFC 6749](https://tools.ietf.org/html/rfc6749)
