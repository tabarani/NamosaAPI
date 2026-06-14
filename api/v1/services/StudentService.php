<?php
/**
 * Student Service
 * Business logic for student operations
 */

namespace NamosaAPI\Services;

use NamosaAPI\Repositories\StudentRepository;

class StudentService extends BaseService
{
    private $repository;
    
    public function __construct()
    {
        $this->repository = new StudentRepository();
    }
    
    /**
     * Get all students with enrichment
     */
    public function getAllStudents($limit = 100, $offset = 0, $filters = [])
    {
        $result = $this->repository->getAll($limit, $offset, $filters);
        
        // Enrich with additional data if needed
        foreach ($result['data'] as &$student) {
            $student['fullName'] = $student['firstName'] . ' ' . $student['surname'];
            $student['age'] = $this->calculateAge($student['dob'] ?? null);
        }
        
        return $result;
    }
    
    /**
     * Get student by ID with full details
     */
    public function getStudentById($studentId)
    {
        $student = $this->repository->getById($studentId);
        
        if (!$student) {
            return null;
        }
        
        // Add calculated fields
        $student['fullName'] = $student['firstName'] . ' ' . $student['surname'];
        $student['age'] = $this->calculateAge($student['dob']);
        $student['gradeLevel'] = $this->calculateGradeLevel($student['yearGroupName'] ?? '');
        
        // Add attendance summary (last 30 days)
        $student['attendanceSummary'] = $this->getRecentAttendanceSummary($studentId);
        
        return $student;
    }
    
    /**
     * Search students
     */
    public function searchStudents($query, $limit = 20)
    {
        return $this->repository->search($query, $limit);
    }
    
    /**
     * Get students by class
     */
    public function getStudentsByClass($classId)
    {
        // This would require a class repository method
        // For now, filter from all students
        $allStudents = $this->repository->getAll(500, 0)['data'];
        
        // In a real implementation, you'd have a specific query
        // This is a placeholder
        return $allStudents;
    }
    
    /**
     * Calculate age from DOB
     */
    private function calculateAge($dob)
    {
        if (empty($dob)) {
            return null;
        }
        
        $dobDate = new \DateTime($dob);
        $now = new \DateTime();
        $interval = $now->diff($dobDate);
        
        return $interval->y;
    }
    
    /**
     * Calculate grade level from year group
     */
    private function calculateGradeLevel($yearGroup)
    {
        // Simple mapping - adjust based on your system
        $mapping = [
            'Grade 1' => 1,
            'Grade 2' => 2,
            'Grade 3' => 3,
            'Grade 4' => 4,
            'Grade 5' => 5,
            'Grade 6' => 6,
            'Grade 7' => 7,
            'Grade 8' => 8,
            'Grade 9' => 9,
            'Grade 10' => 10,
            'Grade 11' => 11,
            'Grade 12' => 12
        ];
        
        return $mapping[$yearGroup] ?? null;
    }
    
    /**
     * Get recent attendance summary (last 30 days)
     */
    private function getRecentAttendanceSummary($studentId)
    {
        // This would require an attendance repository
        // Placeholder implementation
        return [
            'totalDays' => 30,
            'present' => 25,
            'absent' => 2,
            'late' => 3,
            'rate' => 88.33
        ];
    }
    
    /**
     * Get student statistics
     */
    public function getStatistics()
    {
        // Get all students
        $result = $this->repository->getAll(1000, 0);
        
        $students = $result['data'];
        
        // Count by year group
        $yearGroupCounts = [];
        foreach ($students as $student) {
            $yearGroup = $student['yearGroupName'] ?? 'Unknown';
            $yearGroupCounts[$yearGroup] = ($yearGroupCounts[$yearGroup] ?? 0) + 1;
        }
        
        // Calculate average age
        $ages = array_filter(array_map(fn($s) => $this->calculateAge($s['dob']), $students));
        $averageAge = count($ages) > 0 ? round(array_sum($ages) / count($ages), 1) : 0;
        
        return [
            'totalStudents' => count($students),
            'byYearGroup' => $yearGroupCounts,
            'averageAge' => $averageAge,
            'genderDistribution' => $this->getGenderDistribution($students)
        ];
    }
    
    /**
     * Get gender distribution
     */
    private function getGenderDistribution($students)
    {
        $counts = [
            'Male' => 0,
            'Female' => 0,
            'Other' => 0,
            'Unspecified' => 0
        ];
        
        foreach ($students as $student) {
            $gender = $student['gender'] ?? 'Unspecified';
            $counts[$gender] = ($counts[$gender] ?? 0) + 1;
        }
        
        return $counts;
    }
}