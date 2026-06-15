<?php
/*
Gibbon: the flexible, open school platform
Transport Module - SMS Test API
*/

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include Gibbon bootstrap
require_once '../../../gibbon.php';

// Check if user is logged in
if (!isset($_SESSION[$guid]['gibbonPersonID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Phone number is required']);
    exit;
}

// Clean phone number
$phone = preg_replace('/[^0-9+]/', '', $phone);

// Get SMS settings
function getTransportSetting($connection2, $name, $default = '') {
    $stmt = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Transport' AND name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

$smsEnabled = getTransportSetting($connection2, 'smsEnabled', '0');
$smsProvider = getTransportSetting($connection2, 'smsProvider', 'infobip');
$smsApiKey = getTransportSetting($connection2, 'smsApiKey', '');
$smsApiSecret = getTransportSetting($connection2, 'smsApiSecret', '');
$smsBaseUrl = getTransportSetting($connection2, 'smsBaseUrl', '');
$smsSenderID = getTransportSetting($connection2, 'smsSenderID', 'Transport');

if (!$smsEnabled) {
    echo json_encode(['success' => false, 'error' => 'SMS is not enabled']);
    exit;
}

if (empty($smsApiKey) || empty($smsApiSecret)) {
    echo json_encode(['success' => false, 'error' => 'SMS API credentials not configured']);
    exit;
}

// Test message
$testMessage = "This is a test message from your school Transport system. If you received this, your SMS configuration is working correctly!";

try {
    $result = false;
    $responseData = null;
    
    switch ($smsProvider) {
        case 'infobip':
            $result = sendInfobipSMS($smsBaseUrl, $smsApiKey, $smsSenderID, $phone, $testMessage);
            break;
            
        case 'twilio':
            $result = sendTwilioSMS($smsApiKey, $smsApiSecret, $smsSenderID, $phone, $testMessage);
            break;
            
        case 'africastalking':
            $result = sendAfricasTalkingSMS($smsApiKey, $smsApiSecret, $smsSenderID, $phone, $testMessage);
            break;
            
        case 'nexmo':
            $result = sendNexmoSMS($smsApiKey, $smsApiSecret, $smsSenderID, $phone, $testMessage);
            break;
            
        default:
            // Custom provider - would need implementation
            echo json_encode(['success' => false, 'error' => 'Custom provider requires implementation']);
            exit;
    }
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Test SMS sent successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send SMS']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// SMS Provider Functions

function sendInfobipSMS($baseUrl, $apiKey, $from, $to, $message) {
    $url = rtrim($baseUrl, '/') . '/sms/2/text/advanced';
    
    $payload = [
        'messages' => [
            [
                'from' => $from,
                'destinations' => [['to' => $to]],
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
            'Authorization: App ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode >= 200 && $httpCode < 300;
}

function sendTwilioSMS($accountSid, $authToken, $from, $to, $message) {
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    
    $data = [
        'From' => $from,
        'To' => $to,
        'Body' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode >= 200 && $httpCode < 300;
}

function sendAfricasTalkingSMS($apiKey, $username, $from, $to, $message) {
    $url = 'https://api.africastalking.com/version1/messaging';
    
    $data = [
        'username' => $username,
        'to' => $to,
        'message' => $message,
        'from' => $from
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apiKey: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode >= 200 && $httpCode < 300;
}

function sendNexmoSMS($apiKey, $apiSecret, $from, $to, $message) {
    $url = 'https://rest.nexmo.com/sms/json';
    
    $data = [
        'api_key' => $apiKey,
        'api_secret' => $apiSecret,
        'from' => $from,
        'to' => $to,
        'text' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    return isset($result['messages'][0]['status']) && $result['messages'][0]['status'] == '0';
}
