{
    "name": "NamosaAPI",
    "type": "Module",
    "version": "1.0.0",
    "author": "Namosa Team",
    "url": "https://github.com/tabarani/NamosaAPI",
    "description": "RESTful API for Gibbon Core - Integrates with external IdentityProvider for SSO and provides secure endpoints for students, staff, courses, and transport data.",
    "database": "1.0.0",
    "dependencies": {
        "php": "7.4+",
        "gibbon": "v26.0.00+"
    },
    "permissions": {
        "students_read": "View student data via API",
        "staff_read": "View staff data via API",
        "courses_read": "View course data via API",
        "transport_read": "View transport data via API"
    },
    "entry": "api/v1/students.php",
    "resource": "modules/NamosaAPI/"
}
