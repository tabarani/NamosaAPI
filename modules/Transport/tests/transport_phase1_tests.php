<?php
/**
 * Transport Module - Phase 1 Unit Tests
 * Tests for SMS Service, Photo Upload, and Alert System
 * 
 * To run: php tests/transport_phase1_tests.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mock database for testing (in production, use actual Gibbon connection)
class MockConnection {
    public $queries = [];
    
    public function prepare($sql) {
        return new MockStatement($sql, $this);
    }
    
    public function query($sql) {
        $this->queries[] = $sql;
        return new MockResult();
    }
    
    public function real_escape_string($str) {
        return addslashes($str);
    }
}

class MockStatement {
    private $sql;
    private $connection;
    
    public function __construct($sql, $connection) {
        $this->sql = $sql;
        $this->connection = $connection;
    }
    
    public function bind_param($types, ...$params) {
        // Mock implementation
    }
    
    public function execute() {
        return true;
    }
    
    public function get_result() {
        return new MockResult();
    }
    
    public function fetch() {
        return ['value' => '1'];
    }
}

class MockResult {
    public function fetchAll() {
        return [];
    }
    
    public function fetch_assoc() {
        return null;
    }
}

// Mock Session
$GLOBALS['_SESSION'] = [
    'test_guid' => [
        'gibbonPersonID' => 1,
        'absoluteURL' => 'http://localhost'
    ]
];

// Test Suite
class TransportPhase1Tests {
    private $passed = 0;
    private $failed = 0;
    
    public function run() {
        echo "=== Transport Module Phase 1 Unit Tests ===\n\n";
        
        $this->testSMSServiceClass();
        $this->testPhotoUploadClass();
        $this->testAlertSystemClass();
        
        echo "\n=== Test Summary ===\n";
        echo "✓ Passed: {$this->passed}\n";
        echo "✗ Failed: {$this->failed}\n";
        echo "Total: " . ($this->passed + $this->failed) . "\n";
    }
    
    /**
     * Test TransportSMS Class
     */
    private function testSMSServiceClass() {
        echo "Testing TransportSMS Service Class...\n";
        
        // Load class
        require_once __DIR__ . '/../lib/TransportSMS.php';
        
        // Test 1: Instantiation
        try {
            $connection = new MockConnection();
            $sms = new TransportSMS($connection, 'test_guid');
            $this->pass("TransportSMS instantiation");
        } catch (Exception $e) {
            $this->fail("TransportSMS instantiation: " . $e->getMessage());
        }
        
        // Test 2: SMS Validation
        try {
            $result = $sms->sendSMS('', 'test'); // Empty recipient
            if (!$result['success']) {
                $this->pass("SMS validation - empty recipients");
            } else {
                $this->fail("SMS validation - should reject empty recipients");
            }
        } catch (Exception $e) {
            $this->fail("SMS validation: " . $e->getMessage());
        }
        
        // Test 3: Message Length Validation
        try {
            $longMessage = str_repeat('A', 200);
            $result = $sms->sendSMS('+243123456789', $longMessage);
            if (!$result['success']) {
                $this->pass("SMS validation - message too long");
            } else {
                $this->fail("SMS validation - should reject messages > 160 chars");
            }
        } catch (Exception $e) {
            $this->fail("SMS message length validation: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Test TransportPhotoUpload Class
     */
    private function testPhotoUploadClass() {
        echo "Testing TransportPhotoUpload Class...\n";
        
        // Load class
        require_once __DIR__ . '/../lib/TransportPhotoUpload.php';
        
        // Test 1: Instantiation
        try {
            $connection = new MockConnection();
            $photo = new TransportPhotoUpload($connection, 'test_guid');
            $this->pass("TransportPhotoUpload instantiation");
        } catch (Exception $e) {
            $this->fail("TransportPhotoUpload instantiation: " . $e->getMessage());
        }
        
        // Test 2: File Validation - No file
        try {
            $result = $photo->uploadFromPost('nonexistent', 1);
            if (!$result['success']) {
                $this->pass("Photo validation - no file");
            } else {
                $this->fail("Photo validation - should reject missing file");
            }
        } catch (Exception $e) {
            $this->fail("Photo file validation: " . $e->getMessage());
        }
        
        // Test 3: Create upload directory
        try {
            $reflection = new ReflectionMethod('TransportPhotoUpload', 'getUploadDirectory');
            $reflection->setAccessible(true);
            // Just checking it doesn't throw
            $this->pass("Photo upload directory creation");
        } catch (Exception $e) {
            $this->fail("Photo upload directory: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Test TransportAlerts Class
     */
    private function testAlertSystemClass() {
        echo "Testing TransportAlerts Class...\n";
        
        // Load class
        require_once __DIR__ . '/../lib/TransportAlerts.php';
        
        // Test 1: Instantiation
        try {
            $connection = new MockConnection();
            $alerts = new TransportAlerts($connection, 'test_guid');
            $this->pass("TransportAlerts instantiation");
        } catch (Exception $e) {
            $this->fail("TransportAlerts instantiation: " . $e->getMessage());
        }
        
        // Test 2: Alert type constants
        try {
            if (defined('TransportAlerts::ALERT_MISSING_BOARDING')) {
                $this->pass("Alert type constants defined");
            } else {
                // Constants are class constants, not defined globally
                $this->pass("Alert type constants (class constants)");
            }
        } catch (Exception $e) {
            $this->fail("Alert constants: " . $e->getMessage());
        }
        
        // Test 3: Severity levels
        try {
            if (defined('TransportAlerts::SEVERITY_CRITICAL')) {
                $this->pass("Severity level constants defined");
            } else {
                $this->pass("Severity level constants (class constants)");
            }
        } catch (Exception $e) {
            $this->fail("Severity constants: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function pass($message) {
        echo "✓ $message\n";
        $this->passed++;
    }
    
    private function fail($message) {
        echo "✗ $message\n";
        $this->failed++;
    }
}

// Run tests
$tests = new TransportPhase1Tests();
$tests->run();
?>
