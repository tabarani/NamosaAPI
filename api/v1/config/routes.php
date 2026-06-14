<?php
/**
 * API Routes Configuration
 */

return [
    // Authentication
    'POST /auth/token' => [
        'controller' => 'AuthController',
        'method' => 'generateToken',
        'auth' => false
    ],
    'POST /auth/validate' => [
        'controller' => 'AuthController',
        'method' => 'validateToken',
        'auth' => false
    ],
    'POST /auth/revoke' => [
        'controller' => 'AuthController',
        'method' => 'revokeToken',
        'auth' => true
    ],
    
    // Students
    'GET /students' => [
        'controller' => 'StudentController',
        'method' => 'index',
        'auth' => true,
        'scopes' => ['students.read']
    ],
    'GET /students/search' => [
        'controller' => 'StudentController',
        'method' => 'search',
        'auth' => true,
        'scopes' => ['students.read']
    ],
    'GET /students/([0-9]+)/?' => [
        'controller' => 'StudentController',
        'method' => 'show',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['students.read']
    ],
    'GET /students/([0-9]+)/parents/?' => [
        'controller' => 'StudentController',
        'method' => 'getParents',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['students.read']
    ],
    'GET /students/([0-9]+)/siblings/?' => [
        'controller' => 'StudentController',
        'method' => 'getSiblings',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['students.read']
    ],
    
    // Attendance
    'GET /attendance/today' => [
        'controller' => 'AttendanceController',
        'method' => 'getTodaysAttendance',
        'auth' => true,
        'scopes' => ['attendance.read']
    ],
    'GET /attendance/student/([0-9]+)/?' => [
        'controller' => 'AttendanceController',
        'method' => 'getStudentAttendance',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['attendance.read']
    ],
    'GET /attendance/class/([0-9]+)/?' => [
        'controller' => 'AttendanceController',
        'method' => 'getClassAttendance',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['attendance.read']
    ],
    'POST /attendance' => [
        'controller' => 'AttendanceController',
        'method' => 'recordAttendance',
        'auth' => true,
        'scopes' => ['attendance.write']
    ],
    'POST /attendance/bulk' => [
        'controller' => 'AttendanceController',
        'method' => 'recordBulkAttendance',
        'auth' => true,
        'scopes' => ['attendance.write']
    ],
    
    // Transportation
    'GET /routes' => [
        'controller' => 'TransportationController',
        'method' => 'index',
        'auth' => true,
        'scopes' => ['transport.read']
    ],
    'GET /routes/([0-9]+)/?' => [
        'controller' => 'TransportationController',
        'method' => 'show',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['transport.read']
    ],
    'GET /routes/([0-9]+)/students/?' => [
        'controller' => 'TransportationController',
        'method' => 'getRouteStudents',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['transport.read']
    ],
    'GET /routes/([0-9]+)/events/today/?' => [
        'controller' => 'TransportationController',
        'method' => 'getTodaysEvents',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['transport.read']
    ],
    'GET /routes/([0-9]+)/missing/?' => [
        'controller' => 'TransportationController',
        'method' => 'getMissingBoarding',
        'auth' => true,
        'param' => 'id',
        'scopes' => ['transport.read']
    ],
    'POST /boarding' => [
        'controller' => 'TransportationController',
        'method' => 'recordBoarding',
        'auth' => true,
        'scopes' => ['transport.write']
    ],
    'POST /boarding/bulk' => [
        'controller' => 'TransportationController',
        'method' => 'recordBulkBoarding',
        'auth' => true,
        'scopes' => ['transport.write']
    ],
    
    // Add more routes as needed...
];