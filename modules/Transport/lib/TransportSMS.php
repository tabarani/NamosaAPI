<?php
/**
 * Transport SMS Service Class
 * Handles all SMS operations including sending, tracking, history
 * Supports multiple SMS providers (Infobip, Twilio, Africa's Talking, Nexmo)
 */

class TransportSMS {
    
    private $connection;
    private $guid;
    private $smsEnabled;
    private $smsProvider;
    private $smsApiKey;
    private $smsApiSecret;
    private $smsBaseUrl;
    private $smsSenderID;
    
    /**
     * Constructor
     * @param $connection mysqli Database connection
     * @param $guid Gibbon session GUID
     */
    public function __construct($connection, $guid) {
        $this->connection = $connection;
        $this->guid = $guid;
        $this->loadSettings();
    }
    
    /**
     * Load SMS settings from database
     */
    private function loadSettings() {
        $stmt = $this->connection->prepare("
            SELECT name, value FROM gibbonSetting 
            WHERE scope = 'Transport' 
            AND name IN ('smsEnabled', 'smsProvider', 'smsApiKey', 'smsApiSecret', 'smsBaseUrl', 'smsSenderID')
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $key = $row['name'];
            $this->$key = $row['value'];
        }
    }
    
    /**
     * Check if SMS is enabled and configured
     * @return bool
     */
    public function isEnabled() {
        return $this->smsEnabled == '1' && !empty($this->smsApiKey);
    }
    
    /**
     * Send SMS to one or more recipients
     * @param $recipients array|string Phone number(s) - can be single number or array
     * @param $message string SMS message
     * @param $metadata array Optional metadata (routeID, studentID, alertID, etc)
     * @return array ['success' => bool, 'messageID' => string, 'error' => string]
     */
    public function sendSMS($recipients, $message, $metadata = []) {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'SMS not enabled'];
        }
        
        // Normalize recipients to array
        if (is_string($recipients)) {
            $recipients = [$recipients];
        }
        
        if (empty($recipients)) {
            return ['success' => false, 'error' => 'No recipients provided'];
        }
        
        // Clean phone numbers
        $recipients = array_map(function($phone) {
            return preg_replace('/[^0-9+]/', '', $phone);
        }, $recipients);
        
        // Validate message
        if (empty($message) || strlen($message) > 160) {
            return ['success' => false, 'error' => 'Message must be 1-160 characters'];
        }
        
        try {
            $messageID = $this->generateMessageID();
            $result = false;
            
            // Send via appropriate provider
            switch ($this->smsProvider) {
                case 'infobip':
                    $result = $this->sendViaInfobip($recipients, $message, $messageID);
                    break;
                case 'twilio':
                    $result = $this->sendViaTwilio($recipients, $message, $messageID);
                    break;
                case 'africastalking':
                    $result = $this->sendViaAfricasTalking($recipients, $message, $messageID);
                    break;
                case 'nexmo':
                    $result = $this->sendViaNexmo($recipients, $message, $messageID);
                    break;
                default:
                    return ['success' => false, 'error' => 'Unknown SMS provider'];
            }
            
            if ($result) {
                // Log SMS in history
                $this->logSMSHistory($messageID, implode(',', $recipients), $message, 'sent', $metadata);
                return ['success' => true, 'messageID' => $messageID];
            } else {
                $this->logSMSHistory($messageID, implode(',', $recipients), $message, 'failed', $metadata);
                return ['success' => false, 'error' => 'Failed to send SMS'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send SMS via Infobip
     */
    private function sendViaInfobip($recipients, $message, $messageID) {
        $url = rtrim($this->smsBaseUrl, '/') . '/sms/2/text/advanced';
        
        $destinations = array_map(function($phone) {
            return ['to' => $phone];
        }, $recipients);
        
        $payload = [
            'messages' => [
                [
                    'from' => $this->smsSenderID,
                    'destinations' => $destinations,
                    'text' => $message
                ]
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: App ' . $this->smsApiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode >= 200 && $httpCode < 300;
    }
    
    /**
     * Send SMS via Twilio
     */
    private function sendViaTwilio($recipients, $message, $messageID) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->smsApiKey}/Messages.json";
        
        // Twilio sends one at a time
        $allSuccess = true;
        foreach ($recipients as $phone) {
            $data = [
                'From' => $this->smsSenderID,
                'To' => $phone,
                'Body' => $message
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $this->smsApiKey . ':' . $this->smsApiSecret,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if (!($httpCode >= 200 && $httpCode < 300)) {
                $allSuccess = false;
            }
        }
        
        return $allSuccess;
    }
    
    /**
     * Send SMS via Africa's Talking
     */
    private function sendViaAfricasTalking($recipients, $message, $messageID) {
        $url = 'https://api.sandbox.africastalking.com/version1/messaging';
        
        $data = [
            'username' => $this->smsSenderID,
            'message' => $message,
            'recipients' => implode(',', $recipients)
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'ApiKey: ' . $this->smsApiKey
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode >= 200 && $httpCode < 300;
    }
    
    /**
     * Send SMS via Nexmo (Vonage)
     */
    private function sendViaNexmo($recipients, $message, $messageID) {
        $url = 'https://rest.nexmo.com/sms/json';
        
        // Nexmo sends one at a time
        $allSuccess = true;
        foreach ($recipients as $phone) {
            $data = [
                'api_key' => $this->smsApiKey,
                'api_secret' => $this->smsApiSecret,
                'to' => $phone,
                'from' => $this->smsSenderID,
                'text' => $message
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if (!($httpCode >= 200 && $httpCode < 300)) {
                $allSuccess = false;
            }
        }
        
        return $allSuccess;
    }
    
    /**
     * Log SMS in history table
     */
    private function logSMSHistory($messageID, $recipients, $message, $status = 'sent', $metadata = []) {
        try {
            $stmt = $this->connection->prepare("
                INSERT INTO gibbonTransportSMSHistory 
                (messageID, recipients, message, status, routeID, studentID, alertID, createdBy, timestampCreated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $routeID = $metadata['routeID'] ?? null;
            $studentID = $metadata['studentID'] ?? null;
            $alertID = $metadata['alertID'] ?? null;
            $createdBy = $_SESSION[$this->guid]['gibbonPersonID'] ?? null;
            
            $stmt->bind_param('sssiiis', 
                $messageID, $recipients, $message, $status, 
                $routeID, $studentID, $alertID, $createdBy
            );
            
            $stmt->execute();
        } catch (Exception $e) {
            error_log('SMS History logging failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get SMS history
     */
    public function getHistory($limit = 50, $filters = []) {
        $sql = "SELECT * FROM gibbonTransportSMSHistory WHERE 1=1";
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = '" . $this->connection->real_escape_string($filters['status']) . "'";
        }
        
        if (!empty($filters['routeID'])) {
            $sql .= " AND routeID = " . (int)$filters['routeID'];
        }
        
        if (!empty($filters['studentID'])) {
            $sql .= " AND studentID = " . (int)$filters['studentID'];
        }
        
        $sql .= " ORDER BY timestampCreated DESC LIMIT " . (int)$limit;
        
        $result = $this->connection->query($sql);
        return $result ? $result->fetchAll() : [];
    }
    
    /**
     * Generate unique message ID
     */
    private function generateMessageID() {
        return 'SMS_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    }
}
?>
