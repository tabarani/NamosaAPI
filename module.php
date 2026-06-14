<?php
/*
Gibbon: The Flexible, Open Source School Platform
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

// Module manifest for Gibbon

$module = [
    'name'          => 'Namosa API',
    'version'       => '1.0.0',
    'description'   => 'RESTful API layer for Gibbon Educore. Enables integration with mobile apps, external systems, and third-party services.',
    'author'        => 'Yulpana Edutech',
    'url'           => 'https://yulpana.com',
    'license'       => 'MIT',
    'types'         => ['Admin'],
    'dependencies'  => [
        'gibbon' => '>=25.0.00'
    ],
    'settings'      => [
        [
            'name'        => 'api_enabled',
            'displayName' => 'API Enabled',
            'description' => 'Enable or disable the Namosa API',
            'type'        => 'boolean',
            'default'     => 'Y'
        ],
        [
            'name'        => 'jwt_secret',
            'displayName' => 'JWT Secret Key',
            'description' => 'Secret key for JWT token generation (auto-generated on first install)',
            'type'        => 'text',
            'default'     => bin2hex(random_bytes(32))
        ],
        [
            'name'        => 'token_lifetime',
            'displayName' => 'Token Lifetime (seconds)',
            'description' => 'How long access tokens remain valid',
            'type'        => 'number',
            'default'     => '3600'
        ],
        [
            'name'        => 'rate_limit_anonymous',
            'displayName' => 'Anonymous Rate Limit',
            'description' => 'Max requests per hour for unauthenticated clients',
            'type'        => 'number',
            'default'     => '100'
        ],
        [
            'name'        => 'rate_limit_authenticated',
            'displayName' => 'Authenticated Rate Limit',
            'description' => 'Max requests per hour for authenticated clients',
            'type'        => 'number',
            'default'     => '1000'
        ],
        [
            'name'        => 'cors_origins',
            'displayName' => 'CORS Allowed Origins',
            'description' => 'Comma-separated list of allowed origins (e.g., https://app.yourschool.com)',
            'type'        => 'text',
            'default'     => '*'
        ],
        [
            'name'        => 'api_logging_enabled',
            'displayName' => 'Enable API Logging',
            'description' => 'Log all API requests for auditing (recommended: YES)',
            'type'        => 'boolean',
            'default'     => 'Y'
        ]
    ],
    'actions'       => [
        'Manage API Clients' => [
            'script' => '/modules/NamosaAPI/clients_manage.php',
            'type'   => 'Admin'
        ],
        'API Documentation' => [
            'script' => '/modules/NamosaAPI/docs.php',
            'type'   => 'Admin'
        ],
        'API Logs' => [
            'script' => '/modules/NamosaAPI/logs_view.php',
            'type'   => 'Admin'
        ]
    ],
    'entryURL'      => '/modules/NamosaAPI/api/v1/index.php'
];

return $module;