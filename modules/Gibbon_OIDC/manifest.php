<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

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

// Basic module info
$name = 'Gibbon_OIDC';
$description = 'OIDC Single Sign-On integration for seamless authentication with external Identity Provider (OpenIddict). Enables unified login across Gibbon, Moodle, and other systems.';
$entryURL = 'login.php';
$type = 'Additional';
$category = 'Authentication';
$version = '1.0.0';
$author = 'Yulpana Edutech / Mustafa';
$url = 'https://yulpana.com';

// Module tables (none needed - uses Gibbon's existing session/person tables)
$moduleTables = [];

// Actions
$actionRows[] = [
    'name' => 'OIDC Login',
    'precedence' => '0',
    'category' => 'Authentication',
    'description' => 'Access OIDC SSO login functionality',
    'URLList' => 'login.php,callback.php',
    'entryURL' => 'login.php',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'Y',
    'defaultPermissionStudent' => 'Y',
    'defaultPermissionParent' => 'Y',
    'defaultPermissionSupport' => 'Y',
    'categoryPermissionStaff' => 'Y',
    'categoryPermissionStudent' => 'Y',
    'categoryPermissionParent' => 'Y',
    'categoryPermissionOther' => 'Y',
];

$actionRows[] = [
    'name' => 'OIDC Settings',
    'precedence' => '10',
    'category' => 'Settings',
    'description' => 'Configure OIDC provider settings',
    'URLList' => 'settings.php',
    'entryURL' => 'settings.php',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'N',
    'defaultPermissionStudent' => 'N',
    'defaultPermissionParent' => 'N',
    'defaultPermissionSupport' => 'N',
    'categoryPermissionStaff' => 'N',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent' => 'N',
    'categoryPermissionOther' => 'N',
];
