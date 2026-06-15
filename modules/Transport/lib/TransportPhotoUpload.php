<?php
/**
 * Transport Photo Upload Handler
 * Manages secure file uploads, compression, and storage for boarding events
 */

class TransportPhotoUpload {
    
    private $connection;
    private $guid;
    private $uploadDir;
    private $maxFileSize = 5242880; // 5MB in bytes
    private $allowedMimes = ['image/jpeg', 'image/png', 'image/heic', 'image/webp'];
    private $allowedExtensions = ['jpg', 'jpeg', 'png', 'heic', 'webp'];
    
    /**
     * Constructor
     */
    public function __construct($connection, $guid) {
        $this->connection = $connection;
        $this->guid = $guid;
        $this->uploadDir = $this->getUploadDirectory();
    }
    
    /**
     * Get or create upload directory
     */
    private function getUploadDirectory() {
        $basePath = dirname(__DIR__, 4) . '/uploads/transport/photos/' . date('Y/m');
        
        if (!is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }
        
        // Create .htaccess to prevent direct execution
        $htaccess = $basePath . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\nAddType text/plain .php .phtml .php3 .php4 .php5\n");
        }
        
        return $basePath;
    }
    
    /**
     * Upload photo from POST
     * @param $fileInputName string Name of the file input field
     * @param $eventID int Transport event ID (for linking)
     * @param $type string 'boarding_event' or 'verification'
     * @return array ['success' => bool, 'photoUrl' => string, 'error' => string]
     */
    public function uploadFromPost($fileInputName, $eventID, $type = 'boarding_event') {
        if (!isset($_FILES[$fileInputName])) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }
        
        $file = $_FILES[$fileInputName];
        
        // Validate file
        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        
        // Process image
        $result = $this->processImage($file['tmp_name'], $eventID, $type);
        
        if ($result['success']) {
            // Store photo record in database
            $this->storePhotoRecord($eventID, $result['photoUrl'], $type, filesize($result['filePath']));
        }
        
        return $result;
    }
    
    /**
     * Validate file
     */
    private function validateFile($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File upload incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
                UPLOAD_ERR_CANT_WRITE => 'Cannot write file',
                UPLOAD_ERR_EXTENSION => 'Forbidden extension'
            ];
            return ['valid' => false, 'error' => $errors[$file['error']] ?? 'Unknown error'];
        }
        
        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            return ['valid' => false, 'error' => 'File exceeds 5MB limit'];
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime, $this->allowedMimes)) {
            return ['valid' => false, 'error' => 'Invalid file type: ' . $mime];
        }
        
        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            return ['valid' => false, 'error' => 'Invalid file extension'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Process and compress image
     */
    private function processImage($tempFile, $eventID, $type) {
        try {
            // Generate filename
            $filename = $this->generateFilename($type);
            $filepath = $this->uploadDir . '/' . $filename;
            
            // Handle HEIC to JPEG conversion if needed
            $srcFile = $tempFile;
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if ($ext === 'heic') {
                // Convert HEIC to JPEG
                $jpegFile = $this->uploadDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
                if (!$this->convertHeicToJpeg($tempFile, $jpegFile)) {
                    return ['success' => false, 'error' => 'Failed to convert HEIC image'];
                }
                $srcFile = $jpegFile;
                $filepath = $jpegFile;
                $filename = pathinfo($jpegFile, PATHINFO_BASENAME);
            }
            
            // Compress/optimize image
            if (!$this->compressImage($srcFile, $filepath)) {
                return ['success' => false, 'error' => 'Failed to process image'];
            }
            
            // Generate thumbnail
            $this->generateThumbnail($filepath);
            
            // Get relative URL
            $photoUrl = '/uploads/transport/photos/' . date('Y/m') . '/' . $filename;
            
            return [
                'success' => true,
                'photoUrl' => $photoUrl,
                'filePath' => $filepath
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Compress image while maintaining quality
     */
    private function compressImage($source, $destination) {
        try {
            $image = null;
            
            // Detect image type and load
            $info = getimagesize($source);
            if (!$info) {
                return false;
            }
            
            switch ($info[2]) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($source);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($source);
                    break;
                case IMAGETYPE_WEBP:
                    $image = imagecreatefromwebp($source);
                    break;
                default:
                    return false;
            }
            
            if (!$image) {
                return false;
            }
            
            // Resize if larger than 1920px
            $maxWidth = 1920;
            if ($info[0] > $maxWidth) {
                $ratio = $maxWidth / $info[0];
                $newWidth = $maxWidth;
                $newHeight = (int)($info[1] * $ratio);
                
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, 
                    $newWidth, $newHeight, $info[0], $info[1]);
                
                imagedestroy($image);
                $image = $resized;
            }
            
            // Save optimized JPEG (75% quality)
            imagejpeg($image, $destination, 75);
            imagedestroy($image);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Convert HEIC to JPEG
     * Requires imagick PHP extension
     */
    private function convertHeicToJpeg($source, $destination) {
        if (!extension_loaded('imagick')) {
            return false;
        }
        
        try {
            $image = new Imagick($source);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(75);
            $image->writeImage($destination);
            $image->destroy();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Generate thumbnail for preview
     */
    private function generateThumbnail($filepath) {
        try {
            $thumbDir = $this->uploadDir . '/thumbs';
            if (!is_dir($thumbDir)) {
                @mkdir($thumbDir, 0755, true);
            }
            
            $filename = pathinfo($filepath, PATHINFO_BASENAME);
            $thumbPath = $thumbDir . '/' . $filename;
            
            $image = imagecreatefromjpeg($filepath);
            if (!$image) {
                return;
            }
            
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);
            
            $thumbWidth = 200;
            $thumbHeight = (int)($origHeight * ($thumbWidth / $origWidth));
            
            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, 
                $thumbWidth, $thumbHeight, $origWidth, $origHeight);
            
            imagejpeg($thumb, $thumbPath, 75);
            imagedestroy($image);
            imagedestroy($thumb);
        } catch (Exception $e) {
            // Thumbnail generation not critical
        }
    }
    
    /**
     * Generate unique filename
     */
    private function generateFilename($type) {
        $prefix = ($type === 'boarding_event') ? 'boarding' : 'verification';
        $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        return $filename;
    }
    
    /**
     * Store photo record in database
     */
    private function storePhotoRecord($eventID, $photoUrl, $type, $fileSize) {
        try {
            $stmt = $this->connection->prepare("
                INSERT INTO gibbonTransportPhoto 
                (gibbonTransportEventID, photoUrl, photoType, fileSize, uploadedBy, timestampCreated)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $uploadedBy = $_SESSION[$this->guid]['gibbonPersonID'] ?? null;
            
            $stmt->bind_param('issii', $eventID, $photoUrl, $type, $fileSize, $uploadedBy);
            $stmt->execute();
        } catch (Exception $e) {
            error_log('Photo record storage failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete photo by event ID
     */
    public function deletePhotoByEvent($eventID) {
        try {
            // Get photo records
            $stmt = $this->connection->prepare("
                SELECT photoUrl FROM gibbonTransportPhoto 
                WHERE gibbonTransportEventID = ?
            ");
            $stmt->bind_param('i', $eventID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $this->deletePhotoFile($row['photoUrl']);
            }
            
            // Delete records
            $stmt = $this->connection->prepare("
                DELETE FROM gibbonTransportPhoto 
                WHERE gibbonTransportEventID = ?
            ");
            $stmt->bind_param('i', $eventID);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete physical photo file
     */
    private function deletePhotoFile($photoUrl) {
        try {
            $filepath = dirname(__DIR__, 4) . $photoUrl;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            
            // Try to delete thumbnail
            $thumbPath = str_replace('/photos/', '/photos/thumbs/', $filepath);
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
        } catch (Exception $e) {
            // Non-critical
        }
    }
    
    /**
     * Get photo info
     */
    public function getPhotoByEvent($eventID) {
        $stmt = $this->connection->prepare("
            SELECT * FROM gibbonTransportPhoto 
            WHERE gibbonTransportEventID = ? 
            ORDER BY timestampCreated DESC 
            LIMIT 1
        ");
        $stmt->bind_param('i', $eventID);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
?>
