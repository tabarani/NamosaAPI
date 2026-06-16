<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

Gibbon OIDC Module - OIDC Helper Class
Handles token validation, session creation, and PKCE
*/

class OidcHelper
{
    private $config;
    private $connection2;
    private $guid;

    public function __construct($connection2, $guid)
    {
        $this->connection2 = $connection2;
        $this->guid = $guid;
        $this->config = $this->loadConfig($connection2, $guid);
    }

    /**
     * Load configuration from gibbonSetting table with fallback to config.php
     */
    private function loadConfig($connection2, $guid)
    {
        // Default configuration from file
        $defaults = include __DIR__ . '/config.php';

        // Try to load from database
        try {
            $stmt = $connection2->prepare("SELECT name, value FROM gibbonSetting WHERE scope = 'Gibbon OIDC'");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Merge database settings with defaults
            return [
                'idp_base_url' => $settings['idp_base_url'] ?? $defaults['idp_base_url'],
                'authorize_endpoint' => $defaults['authorize_endpoint'],
                'token_endpoint' => $defaults['token_endpoint'],
                'userinfo_endpoint' => $defaults['userinfo_endpoint'],
                'logout_endpoint' => $defaults['logout_endpoint'],
                'client_id' => $settings['client_id'] ?? $defaults['client_id'],
                'client_secret' => $settings['client_secret'] ?? $defaults['client_secret'],
                'redirect_uri' => $settings['redirect_uri'] ?? $defaults['redirect_uri'],
                'scopes' => $settings['scopes'] ?? $defaults['scopes'],
                'response_type' => $defaults['response_type'],
                'use_pkce' => $defaults['use_pkce'],
                'post_logout_redirect_uri' => $settings['post_logout_redirect_uri'] ?? $defaults['post_logout_redirect_uri'],
                'session_timeout' => $defaults['session_timeout'],
                'auto_redirect' => ($settings['auto_redirect'] ?? $defaults['auto_redirect']) === 'Y',
                'jit_provisioning' => ($settings['jit_provisioning'] ?? $defaults['jit_provisioning']) === 'Y',
                'trusted_idps' => isset($settings['trusted_idps']) ? explode(',', $settings['trusted_idps']) : ($defaults['trusted_idps'] ?? []),
            ];
        } catch (Exception $e) {
            // Fallback to defaults if database not available
            return $defaults;
        }
    }

    /**
     * Generate PKCE code verifier and challenge
     */
    public function generatePkcePair()
    {
        $codeVerifier = $this->generateRandomString(128);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
        ];
    }

    /**
     * Build authorization URL
     */
    public function buildAuthorizeUrl($state, $codeChallenge, $returnUrl = null)
    {
        $baseUrl = rtrim($this->config['idp_base_url'], '/');
        $authorizeUrl = $baseUrl . $this->config['authorize_endpoint'];

        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $_SESSION[$this->guid]['absoluteURL'] . $this->config['redirect_uri'],
            'response_type' => $this->config['response_type'],
            'scope' => $this->config['scopes'],
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if ($returnUrl) {
            $params['return_url'] = $returnUrl;
        }

        return $authorizeUrl . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCodeForTokens($code, $codeVerifier)
    {
        $baseUrl = rtrim($this->config['idp_base_url'], '/');
        $tokenUrl = $baseUrl . $this->config['token_endpoint'];

        $data = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $_SESSION[$this->guid]['absoluteURL'] . $this->config['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Token exchange failed: HTTP ' . $httpCode . ' - ' . $response);
        }

        $tokens = json_decode($response, true);

        if (!$tokens || !isset($tokens['access_token'])) {
            throw new Exception('Invalid token response');
        }

        return $tokens;
    }

    /**
     * Decode and validate ID token (JWT) - basic validation without signature check
     * For production, implement proper JWKS signature validation
     */
    public function decodeIdToken($idToken)
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new Exception('Invalid JWT token format');
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);

        if (!$payload) {
            throw new Exception('Failed to decode JWT payload');
        }

        // Validate expiration
        if (isset($payload['exp']) && time() >= $payload['exp']) {
            throw new Exception('Token has expired');
        }

        // Validate issuer
        if (isset($this->config['issuer']) && isset($payload['iss']) && $payload['iss'] !== $this->config['issuer']) {
            throw new Exception('Invalid token issuer');
        }

        // Validate audience
        if (isset($this->config['audience']) && isset($payload['aud'])) {
            $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
            if (!in_array($this->config['audience'], $audiences)) {
                throw new Exception('Invalid token audience');
            }
        }

        return $payload;
    }

    /**
     * Fetch user info from userinfo endpoint
     */
    public function getUserInfo($accessToken)
    {
        $baseUrl = rtrim($this->config['idp_base_url'], '/');
        $userinfoUrl = $baseUrl . $this->config['userinfo_endpoint'];

        $ch = curl_init($userinfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Userinfo request failed: HTTP ' . $httpCode);
        }

        return json_decode($response, true);
    }

    /**
     * Check if the current IdP is trusted for JIT provisioning
     */
    private function isTrustedIdp()
    {
        // If no trusted IdPs are configured, allow all (for backward compatibility)
        if (empty($this->config['trusted_idps'])) {
            return true;
        }

        // Check if current IdP base URL is in the trusted list
        $currentIdp = rtrim($this->config['idp_base_url'], '/');
        foreach ($this->config['trusted_idps'] as $trustedIdp) {
            $trustedIdp = trim($trustedIdp);
            if (!empty($trustedIdp) && rtrim($trustedIdp, '/') === $currentIdp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new user via JIT provisioning
     */
    private function createJitUser($claims, $gibbonId)
    {
        // Fetch additional user info from userinfo endpoint if available
        $userInfo = [];
        if (isset($claims['access_token'])) {
            try {
                $userInfo = $this->getUserInfo($claims['access_token']);
            } catch (Exception $e) {
                error_log('OIDC JIT: Failed to fetch userinfo: ' . $e->getMessage());
                // Continue with claims data only
            }
        }

        // Extract user details from claims or userinfo
        $givenName = $this->sanitizeInput($userInfo['given_name'] ?? $claims['given_name'] ?? '');
        $familyName = $this->sanitizeInput($userInfo['family_name'] ?? $claims['family_name'] ?? '');
        $email = $this->sanitizeInput($userInfo['email'] ?? $claims['email'] ?? '');
        $preferredUsername = $this->sanitizeInput($userInfo['preferred_username'] ?? $claims['preferred_username'] ?? '');

        // Validate required fields
        if (empty($email)) {
            throw new Exception('JIT Provisioning failed: Email is required');
        }

        // Generate username from preferred_username or email
        $username = !empty($preferredUsername) ? $preferredUsername : explode('@', $email)[0];
        $username = $this->sanitizeUsername($username);

        // Ensure username is unique
        $username = $this->generateUniqueUsername($username);

        // Generate a random password (user will login via SSO only)
        $randomPassword = bin2hex(random_bytes(16));
        $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

        // Determine name if not provided
        if (empty($givenName)) {
            $givenName = 'OIDC';
        }
        if (empty($familyName)) {
            $familyName = 'User';
        }

        // Get current school year for enrollment
        $schoolYearId = $this->getCurrentSchoolYear();

        // Insert new gibbonPerson record
        $insertStmt = $this->connection2->prepare("
            INSERT INTO gibbonPerson (
                username, 
                password, 
                surname, 
                firstName, 
                email, 
                status, 
                canLoginTo, 
                gibbonSchoolYearID,
                dateAdded
            ) VALUES (?, ?, ?, ?, ?, 'Full', 'Y', ?, NOW())
        ");
        $insertStmt->execute([
            $username,
            $passwordHash,
            $familyName,
            $givenName,
            $email,
            $schoolYearId,
        ]);

        $newGibbonPersonId = $this->connection2->lastInsertId();

        if (!$newGibbonPersonId) {
            throw new Exception('JIT Provisioning failed: Could not create user record');
        }

        // Log successful JIT creation
        error_log('OIDC JIT: Created new user ' . $username . ' (ID: ' . $newGibbonPersonId . ') from IdP');

        // Return the newly created person record
        $selectStmt = $this->connection2->prepare("
            SELECT * FROM gibbonPerson 
            WHERE gibbonPersonID = ?
        ");
        $selectStmt->execute([$newGibbonPersonId]);
        $person = $selectStmt->fetch();

        if (!$person) {
            throw new Exception('JIT Provisioning failed: Could not retrieve created user');
        }

        return $person;
    }

    /**
     * Sanitize input string for database insertion
     */
    private function sanitizeInput($input)
    {
        if ($input === null) {
            return '';
        }
        // Remove control characters and trim whitespace
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        return trim($input);
    }

    /**
     * Sanitize username to meet Gibbon requirements
     */
    private function sanitizeUsername($username)
    {
        // Only allow alphanumeric characters, underscores, dots, and hyphens
        $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
        // Ensure it's not empty after sanitization
        if (empty($username)) {
            $username = 'user_' . substr(md5(uniqid()), 0, 8);
        }
        // Limit length
        return substr($username, 0, 50);
    }

    /**
     * Generate a unique username by appending numbers if needed
     */
    private function generateUniqueUsername($baseUsername)
    {
        $username = $baseUsername;
        $counter = 1;

        while ($this->usernameExists($username)) {
            $username = $baseUsername . '_' . $counter;
            $counter++;
            
            // Prevent infinite loop
            if ($counter > 1000) {
                $username = 'user_' . substr(md5(uniqid()), 0, 8);
                break;
            }
        }

        return $username;
    }

    /**
     * Check if a username already exists in the database
     */
    private function usernameExists($username)
    {
        $stmt = $this->connection2->prepare("
            SELECT COUNT(*) FROM gibbonPerson WHERE username = ?
        ");
        $stmt->execute([$username]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Create Gibbon session from OIDC claims
     */
    public function createGibbonSession($claims)
    {
        $gibbonId = $claims['gibbon_id'] ?? null;

        if (!$gibbonId) {
            throw new Exception('Missing gibbon_id claim in token');
        }

        // Fetch person from database
        $stmt = $this->connection2->prepare("
            SELECT * FROM gibbonPerson 
            WHERE gibbonPersonID = ? 
            AND status = 'Full'
        ");
        $stmt->execute([$gibbonId]);
        $person = $stmt->fetch();

        if (!$person) {
            // JIT Provisioning: Auto-create user if enabled and IdP is trusted
            if ($this->config['jit_provisioning'] && $this->isTrustedIdp()) {
                $person = $this->createJitUser($claims, $gibbonId);
            } else {
                throw new Exception('User not found in Gibbon database (ID: ' . $gibbonId . ')');
            }
        }

        // Fetch user's role categories
        $roleStmt = $this->connection2->prepare("
            SELECT gibbonRole.category 
            FROM gibbonPersonRole 
            INNER JOIN gibbonRole ON gibbonPersonRole.gibbonRoleID = gibbonRole.gibbonRoleID 
            WHERE gibbonPersonRole.gibbonPersonID = ?
        ");
        $roleStmt->execute([$person['gibbonPersonID']]);
        $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

        // Create session (mimics Gibbon's native login)
        $_SESSION[$this->guid]['gibbonPersonID'] = $person['gibbonPersonID'];
        $_SESSION[$this->guid]['gibbonRoleID'] = $person['gibbonRoleIDPrimary'] ?? null;
        $_SESSION[$this->guid]['gibbonRoleIDCategory'] = !empty($roles) ? $roles[0] : null;
        $_SESSION[$this->guid]['gibbonSchoolYearID'] = $this->getCurrentSchoolYear();
        $_SESSION[$this->guid]['systemRole'] = $this->determineSystemRole($roles);
        $_SESSION[$this->guid]['absoluteURL'] = $_SESSION[$this->guid]['absoluteURL'] ?? '';
        $_SESSION[$this->guid]['username'] = $person['username'];
        $_SESSION[$this->guid]['firstName'] = $person['firstName'];
        $_SESSION[$this->guid]['surname'] = $person['surname'];
        $_SESSION[$this->guid]['email'] = $person['email'];
        $_SESSION[$this->guid]['canLoginTo'] = $person['canLoginTo'];

        // Update last login
        $updateStmt = $this->connection2->prepare("
            UPDATE gibbonPerson 
            SET lastLogin = NOW() 
            WHERE gibbonPersonID = ?
        ");
        $updateStmt->execute([$person['gibbonPersonID']]);

        return $person;
    }

    /**
     * Build logout URL
     */
    public function buildLogoutUrl($idTokenHint = null)
    {
        $baseUrl = rtrim($this->config['idp_base_url'], '/');
        $logoutUrl = $baseUrl . $this->config['logout_endpoint'];

        $params = [
            'post_logout_redirect_uri' => $_SESSION[$this->guid]['absoluteURL'] . $this->config['post_logout_redirect_uri'],
        ];

        if ($idTokenHint) {
            $params['id_token_hint'] = $idTokenHint;
        }

        return $logoutUrl . '?' . http_build_query($params);
    }

    /**
     * Get current school year ID
     */
    private function getCurrentSchoolYear()
    {
        $stmt = $this->connection2->query("
            SELECT gibbonSchoolYearID 
            FROM gibbonSchoolYear 
            WHERE status = 'Current' 
            LIMIT 1
        ");
        $result = $stmt->fetch();

        return $result ? $result['gibbonSchoolYearID'] : null;
    }

    /**
     * Determine system role from role categories
     */
    private function determineSystemRole($roles)
    {
        if (empty($roles)) {
            return 'Other';
        }

        // Priority: Admin > Staff > Student > Parent > Other
        if (in_array('Admin', $roles)) {
            return 'Admin';
        }
        if (in_array('Staff', $roles)) {
            return 'Staff';
        }
        if (in_array('Student', $roles)) {
            return 'Student';
        }
        if (in_array('Parent', $roles)) {
            return 'Parent';
        }

        return 'Other';
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }

    /**
     * Generate cryptographically secure random string
     */
    private function generateRandomString($length)
    {
        return bin2hex(random_bytes($length / 2));
    }
}
