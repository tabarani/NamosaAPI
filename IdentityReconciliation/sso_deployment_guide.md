# SSO Deployment Guide — Step by Step

## Architecture Recap

```
User Browser
    │
    ├──► https://nbs.edu.mr/sis2  (Gibbon — shared hosting)
    ├──► https://nbs.edu.mr/lms2  (Moodle — shared hosting)
    │
    └──► https://144.91.66.114    (Identity Provider — VPS)
              │
              Nginx (SSL termination, port 443)
              │
              └──► http://localhost:5000 (.NET 8 OpenIddict)
```

---

## Step 1: Nginx Reverse Proxy on IdP Server (144.91.66.114)

> [!IMPORTANT]
> Without this, Gibbon and Moodle cannot reach the IdP from the internet.

SSH into your VPS and check if Nginx is already installed:

```bash
nginx -v
```

If not installed:
```bash
sudo apt update && sudo apt install nginx -y
```

### Create the Nginx config:

```bash
sudo nano /etc/nginx/sites-available/identity-idp
```

Paste this:

```nginx
server {
    listen 443 ssl;
    server_name 144.91.66.114;

    # Use your existing SSL certificate, or generate a self-signed one:
    # sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    #   -keyout /etc/ssl/private/idp.key -out /etc/ssl/certs/idp.crt \
    #   -subj "/CN=144.91.66.114"
    ssl_certificate     /etc/ssl/certs/idp.crt;
    ssl_certificate_key /etc/ssl/private/idp.key;

    location / {
        proxy_pass         http://localhost:5000;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection keep-alive;
        proxy_set_header   Host $host;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }
}

server {
    listen 80;
    server_name 144.91.66.114;
    return 301 https://$host$request_uri;
}
```

Enable and restart:

```bash
sudo ln -sf /etc/nginx/sites-available/identity-idp /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Verify from the server:
```bash
curl -sk https://144.91.66.114/.well-known/openid-configuration | python3 -m json.tool
```

### Verify from your laptop browser:
Open: `https://144.91.66.114/.well-known/openid-configuration`

(You may get a certificate warning if using self-signed — that's OK for testing.)

---

## Step 2: Deploy Gibbon OIDC Module

### 2a. Upload the updated module

Upload the **entire** `OIDC_Gibbon` folder from your local machine to the Gibbon server:

```
Local:  C:\Users\ma.elnaiem\source\repos\SSO\IdentityReconciliation\OIDC_Gibbon\
Remote: /home/edumddju/public_html/sis2/modules/OIDC/
```

Use FileZilla, WinSCP, or cPanel File Manager. Make sure the folder is named **`OIDC`** (not `OIDC_Gibbon`).

### 2b. Install the module in Gibbon

1. Go to: `https://nbs.edu.mr/sis2/index.php?q=/modules/System Admin/module_manage.php`
2. You should see the **OIDC** module in the list
3. Click **Install** in the Actions column
4. After install, go to **System Admin → User Admin → Manage Permissions**
5. Make sure the **OIDC Login** action is enabled for all roles
6. Make sure the **OIDC Settings** action is enabled for Admin only

### 2c. Configure the OIDC settings

Go to: `https://nbs.edu.mr/sis2/index.php?q=/modules/OIDC/settings.php`

Fill in:

| Field | Value |
|-------|-------|
| **Identity Provider Base URL** | `https://144.91.66.114` |
| **Client ID** | `gibbon-sso` |
| **Client Secret** | `gibbon-sso-secret-change-me` |
| **Scopes** | `openid profile email gibbon_id` |
| **Redirect URI** | `/modules/OIDC/callback.php` |
| **Post-Logout Redirect URI** | `/index.php` |
| **Auto-Redirect to SSO** | OFF (for testing — turn ON later) |

Click **Save OIDC Settings**.

### 2d. Test the Gibbon SSO login

1. Open an incognito/private browser window
2. Go to: `https://nbs.edu.mr/sis2/index.php?q=/modules/OIDC/login.php`
3. You should see a **"Sign in with SSO"** button
4. Click it → you'll be redirected to `https://144.91.66.114/Account/Login`
5. Log in with: **admin@nbs.edu.mr** / **Admin@2024!**
6. You should be redirected back to Gibbon, logged in

> [!WARNING]
> This will only work if the admin user (admin@nbs.edu.mr) has a corresponding `UserMap` entry linking to a Gibbon person ID. Since we haven't run the reconciliation yet, we need to manually create this mapping. See Step 4.

---

## Step 3: Configure Moodle OIDC Plugin

### 3a. Upload the plugin (if not already installed)

Upload the `oidc_moodle` folder to:
```
/home/edumddju/public_html/lms2/auth/oidc/
```

Then go to **Site Administration → Notifications** to trigger the plugin install/upgrade.

### 3b. Configure the OIDC Application

Go to: **Site Administration → Plugins → Authentication → OpenID Connect → Application**
(or direct URL: `https://nbs.edu.mr/lms2/auth/oidc/manageapplication.php`)

| Field | Value |
|-------|-------|
| **IdP Type** | `Other` (value 3 — this is critical, NOT Microsoft) |
| **Client ID** | `moodle-sso` |
| **Authentication method** | `Secret` |
| **Client Secret** | `moodle-sso-secret-change-me` |
| **Authorization Endpoint** | `https://144.91.66.114/connect/authorize` |
| **Token Endpoint** | `https://144.91.66.114/connect/token` |
| **Resource** | `https://144.91.66.114` (required for "Other" IdP type) |
| **OIDC Scope** | `openid profile email moodle_id` |

Click **Save changes**.

### 3c. Configure Other Settings

Go to: **Site Administration → Plugins → Authentication → OpenID Connect → Other settings**
(this is the standard settings page)

| Setting | Value |
|---------|-------|
| **Force redirect** | ☐ Unchecked (for testing — enable later) |
| **Login flow** | `Authorization Code Flow` (authcode — this is the default) |
| **Single sign off** | ☑ Checked |
| **IdP logout endpoint** | `https://144.91.66.114/connect/logout` |
| **Provider Name** | `NBS SSO` (or whatever you want on the login button) |
| **Record debugging messages** | ☑ Checked (for initial testing) |

### 3d. Enable the OIDC auth plugin

Go to: **Site Administration → Plugins → Authentication → Manage authentication**

1. Find **OpenID Connect** in the list
2. Click the **eye icon** to enable it
3. Move it up in priority if you want it as primary

### 3e. Test Moodle SSO

1. Log out of Moodle
2. On the Moodle login page, you should see an **"OpenID Connect"** button
3. Click it → redirects to `https://144.91.66.114/Account/Login`
4. Log in with: **admin@nbs.edu.mr** / **Admin@2024!**
5. You should be redirected back to Moodle

---

## Step 4: Create User Mappings (Critical!)

The IdP needs to know which `IdentityUser` maps to which Gibbon/Moodle user. Currently, only the admin user exists in the IdP. We need to:

### 4a. Find your Gibbon Person ID

In Gibbon, go to your admin profile and note the `gibbonPersonID` from the URL (e.g., `gibbonPersonID=1`).

### 4b. Find your Moodle User ID

In Moodle, go to your admin profile and note the user ID from the URL (e.g., `id=2`).

### 4c. Create the UserMap in SQL Server

SSH into the IdP server and run:

```bash
/opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Pass@2021' -C -d IdentityReconciliation -Q "
-- First, get the IdentityUser ID for admin
DECLARE @userId NVARCHAR(450);
SELECT @userId = Id FROM AspNetUsers WHERE Email = 'admin@nbs.edu.mr';

-- Insert the UserMap linking IdP user to Gibbon + Moodle
INSERT INTO UserMaps (Id, MoodleId, GibbonId, Email, Username, MatchConfidence, Status, CreatedAt, UpdatedAt, IdentityUserId)
VALUES (
    NEWID(),
    2,              -- Replace with your actual Moodle user ID
    1,              -- Replace with your actual Gibbon person ID
    'admin@nbs.edu.mr',
    'admin',
    100,
    1,              -- 1 = Linked
    GETUTCDATE(),
    GETUTCDATE(),
    @userId
);

SELECT * FROM UserMaps;
"
```

> [!TIP]
> Replace `2` and `1` with your actual Moodle user ID and Gibbon person ID.

---

## Step 5: End-to-End Test

### Test Flow:
1. Open a private/incognito browser
2. Go to `https://nbs.edu.mr/sis2/index.php?q=/modules/OIDC/login.php`
3. Click "Sign in with SSO" → lands on IdP login
4. Enter `admin@nbs.edu.mr` / `Admin@2024!`
5. → Redirected back to Gibbon, logged in as admin ✅
6. Now open a new tab: `https://nbs.edu.mr/lms2`
7. Click "OpenID Connect" login → should auto-login (same IdP session!) ✅

### Verify the token contains claims:
```bash
# On the IdP server, check the userinfo endpoint
curl -sk https://144.91.66.114/connect/userinfo \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" | python3 -m json.tool
```

---

## Troubleshooting

| Problem | Check |
|---------|-------|
| **"This server only accepts HTTPS"** | Nginx proxy not working, or forwarded headers not being sent |
| **"Invalid redirect_uri"** | The URI must match **exactly** what's in ClientSeedService.cs |
| **"Missing gibbon_id claim"** | No UserMap exists for the logged-in user |
| **Gibbon session not created** | Check Gibbon's PHP error log for OidcHelper errors |
| **Moodle shows "Invalid state"** | Clear browser cookies and try again |
| **SSL certificate errors** | Use `-k` flag with curl for self-signed certs |

### Key log commands:
```bash
# IdP logs
sudo journalctl -u identity-idp -f

# Gibbon PHP errors
tail -f /home/edumddju/logs/error.log

# Moodle debug
# Enable in Site Admin → Development → Debugging → set to DEVELOPER
```
