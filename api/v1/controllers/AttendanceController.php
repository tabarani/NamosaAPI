<?php
/**
 * Attendance Controller
 * Handles student attendance endpoints
 */

namespace NamosaAPI\Controllers;

use NamosaAPI\Lib\Response;
use NamosaAPI\Repositories\AttendanceRepository;

class AttendanceController extends BaseController
{
    private $repository;
    
    public function __construct()
    {
        parent::__construct();
        $this->repository = new AttendanceRepository();
    }
    
    /**
     * GET /attendance/today
     * Get today's attendance for all students
     */
    public function getTodaysAttendance()
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        $attendance = $this->repository->getAttendanceByDate($date);
        
        Response::success($attendance, 'Attendance retrieved successfully', 200, [
            'date' => $date,
            'count' => count($attendance)
        ]);
    }
    
    /**
     * GET /attendance/student/{id}
     * Get attendance history for a student
     */
    public function getStudentAttendance($studentId)
    {
        $limit = min((int) ($_GET['limit'] ?? 30), 100);
        $offset = (int) ($_GET['offset'] ?? 0);
        $startDate = $_GET['startDate'] ?? null;
        $endDate = $_GET['endDate'] ?? null;
        
        $attendance = $this->repository->getStudentAttendanceHistory(
            $studentId,
            $limit,
            $offset,
            $startDate,
            $endDate
        );
        
        Response::success($attendance, 'Attendance history retrieved', 200, [
            'studentId' => $studentId,
            'count' => count($attendance)
        ]);
    }
    
    /**
     * GET /attendance/class/{id}
     * Get attendance for a class/course
     */
    public function getClassAttendance($classId)
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        $attendance = $this->repository->getClassAttendance($classId, $date);
        
        Response::success($attendance, 'Class attendance retrieved', 200, [
            'classId' => $classId,
            'date' => $date,
            'count' => count($attendance)
        ]);
    }
    
    /**
     * POST /attendance
     * Record attendance for a student
     */
    public function recordAttendance()
    {
        $input = $this->getJsonInput();
        
        // Validate required fields
        $required = ['studentId', 'date', 'status', 'recordedBy'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                Response::error("Missing required field: {$field}", 400, 'VALIDATION_ERROR');
            }
        }
        
        // Validate status
        $validStatus = ['Present', 'Absent', 'Late', 'Excused'];
        if (!in_array($input['status'], $validStatus)) {
            Response::error('Invalid status. Must be: ' . implode(', ', $validStatus), 400, 'VALIDATION_ERROR');
        }
        
        try {
            $attendanceId = $this->repository->recordAttendance($input);
            
            Response::success([
                'attendanceId' => $attendanceId,
                'studentId' => $input['studentId'],
                'date' => $input['date'],
                'status' => $input['status']
            ], 'Attendance recorded successfully', 201);
            
        } catch (\Exception $e) {
            Response::error('Failed to record attendance: ' . $e->getMessage(), 500, 'INTERNAL_ERROR');
        }
    }
    
    /**
     * POST /attendance/bulk
     * Record attendance for multiple students at once
     */
    public function recordBulkAttendance()
    {
        $input = $this->getJsonInput();
        
        $records = $input['records'] ?? [];
        
        if (empty($records) || !is_array($records)) {
            Response::error('Records array is required', 400, 'VALIDATION_ERROR');
        }
        
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($records as $record) {
            try {
                $attendanceId = $this->repository->recordAttendance($record);
                $results[] = [
                    'success' => true,
                    'attendanceId' => $attendanceId,
                    'studentId' => $record['studentId'] ?? null
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'studentId' => $record['studentId'] ?? null
                ];
                $failureCount++;
            }
        }
        
        Response::success($results, "Processed {$successCount} records successfully", 201, [
            'successCount' => $successCount,
            'failureCount' => $failureCount,
            'totalCount' => count($records)
        ]);
    }
    
    /**
     * GET /attendance/stats/{date}
     * Get attendance statistics for a date
     */
    public function getAttendanceStats($date)
    {
        if (empty($date)) {
            $date = date('Y-m-d');
        }
        
        $stats = $this->repository->getAttendanceStatistics($date);
        
        Response::success($stats, 'Attendance statistics retrieved', 200);
    }
}