<?php
/**
 * Attendance Repository
 * Handles all attendance-related database operations
 */

namespace NamosaAPI\Repositories;

class AttendanceRepository extends BaseRepository
{
    /**
     * Get attendance for a specific date
     */
    public function getAttendanceByDate($date)
    {
        $sql = "
            SELECT 
                a.gibbonAttendanceLogPersonID as id,
                a.gibbonPersonID as studentId,
                p.firstName,
                p.surname,
                p.preferredName,
                p.image_240 as photo,
                a.date,
                a.direction as status,
                a.timestampTaken as recordedAt,
                a.gibbonPersonIDTaker as recordedBy,
                r.firstName as recorderFirstName,
                r.surname as recorderSurname,
                y.nameShort as yearGroupName,
                c.nameShort as className
            FROM gibbonAttendanceLogPerson a
            INNER JOIN gibbonPerson p ON a.gibbonPersonID = p.gibbonPersonID
            LEFT JOIN gibbonPerson r ON a.gibbonPersonIDTaker = r.gibbonPersonID
            LEFT JOIN gibbonYearGroup y ON p.gibbonYearGroupID = y.gibbonYearGroupID
            LEFT JOIN gibbonFormGroup fg ON p.gibbonFormGroupID = fg.gibbonFormGroupID
            LEFT JOIN gibbonCourseClass c ON fg.gibbonCourseClassID = c.gibbonCourseClassID
            WHERE a.date = :date
            AND p.status = 'Full'
            AND p.gibbonRoleIDPrimary IN (
                SELECT gibbonRoleID FROM gibbonRole WHERE category = 'Student'
            )
            ORDER BY p.surname ASC, p.firstName ASC
        ";
        
        return $this->fetchAll($sql, [':date' => $date]);
    }
    
    /**
     * Get attendance history for a student
     */
    public function getStudentAttendanceHistory($studentId, $limit = 30, $offset = 0, $startDate = null, $endDate = null)
    {
        $sql = "
            SELECT 
                a.gibbonAttendanceLogPersonID as id,
                a.gibbonPersonID as studentId,
                a.date,
                a.direction as status,
                a.timestampTaken as recordedAt,
                a.gibbonPersonIDTaker as recordedBy,
                r.firstName as recorderFirstName,
                r.surname as recorderSurname,
                a.notes,
                CASE 
                    WHEN a.direction = 'Present' THEN 1
                    WHEN a.direction = 'Late' THEN 0.5
                    ELSE 0
                END as attendanceScore
            FROM gibbonAttendanceLogPerson a
            LEFT JOIN gibbonPerson r ON a.gibbonPersonIDTaker = r.gibbonPersonID
            WHERE a.gibbonPersonID = :studentId
        ";
        
        $params = [':studentId' => $studentId];
        
        if ($startDate) {
            $sql .= " AND a.date >= :startDate";
            $params[':startDate'] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND a.date <= :endDate";
            $params[':endDate'] = $endDate;
        }
        
        $sql .= " ORDER BY a.date DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        $attendance = $stmt->fetchAll();
        
        // Calculate summary stats
        $total = count($attendance);
        $present = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));
        $late = count(array_filter($attendance, fn($a) => $a['status'] === 'Late'));
        $absent = count(array_filter($attendance, fn($a) => $a['status'] === 'Absent'));
        
        return [
            'records' => $attendance,
            'summary' => [
                'total' => $total,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'attendanceRate' => $total > 0 ? round(($present + $late * 0.5) / $total * 100, 2) : 0
            ]
        ];
    }
    
    /**
     * Get attendance for a class/course
     */
    public function getClassAttendance($classId, $date)
    {
        $sql = "
            SELECT 
                a.gibbonAttendanceLogPersonID as id,
                a.gibbonPersonID as studentId,
                p.firstName,
                p.surname,
                p.preferredName,
                p.image_240 as photo,
                a.date,
                a.direction as status,
                a.timestampTaken as recordedAt,
                a.notes
            FROM gibbonAttendanceLogPerson a
            INNER JOIN gibbonPerson p ON a.gibbonPersonID = p.gibbonPersonID
            INNER JOIN gibbonFormGroup fg ON p.gibbonFormGroupID = fg.gibbonFormGroupID
            INNER JOIN gibbonCourseClass c ON fg.gibbonCourseClassID = c.gibbonCourseClassID
            WHERE c.gibbonCourseClassID = :classId
            AND a.date = :date
            ORDER BY p.surname ASC, p.firstName ASC
        ";
        
        return $this->fetchAll($sql, [
            ':classId' => $classId,
            ':date' => $date
        ]);
    }
    
    /**
     * Record attendance for a student
     */
    public function recordAttendance($data)
    {
        // Check if attendance already exists for this student/date
        $existing = $this->fetchOne("
            SELECT gibbonAttendanceLogPersonID 
            FROM gibbonAttendanceLogPerson 
            WHERE gibbonPersonID = :studentId AND date = :date
        ", [
            ':studentId' => $data['studentId'],
            ':date' => $data['date']
        ]);
        
        if ($existing) {
            // Update existing record
            $updateData = [
                'direction' => $data['status'],
                'timestampTaken' => date('Y-m-d H:i:s'),
                'gibbonPersonIDTaker' => $data['recordedBy'],
                'notes' => $data['notes'] ?? null
            ];
            
            $this->update(
                'AttendanceLogPerson',
                $updateData,
                'gibbonAttendanceLogPersonID = :id',
                [':id' => $existing['gibbonAttendanceLogPersonID']]
            );
            
            return $existing['gibbonAttendanceLogPersonID'];
        }
        
        // Insert new record
        $attendanceData = [
            'gibbonPersonID' => $data['studentId'],
            'date' => $data['date'],
            'direction' => $data['status'],
            'timestampTaken' => date('Y-m-d H:i:s'),
            'gibbonPersonIDTaker' => $data['recordedBy'],
            'notes' => $data['notes'] ?? null
        ];
        
        return $this->insert('AttendanceLogPerson', $attendanceData);
    }
    
    /**
     * Get attendance statistics for a date
     */
    public function getAttendanceStatistics($date)
    {
        $sql = "
            SELECT 
                COUNT(*) as totalRecords,
                SUM(CASE WHEN direction = 'Present' THEN 1 ELSE 0 END) as presentCount,
                SUM(CASE WHEN direction = 'Absent' THEN 1 ELSE 0 END) as absentCount,
                SUM(CASE WHEN direction = 'Late' THEN 1 ELSE 0 END) as lateCount,
                SUM(CASE WHEN direction = 'Excused' THEN 1 ELSE 0 END) as excusedCount,
                ROUND(SUM(CASE WHEN direction IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as attendanceRate
            FROM gibbonAttendanceLogPerson
            WHERE date = :date
        ";
        
        $stats = $this->fetchOne($sql, [':date' => $date]);
        
        return [
            'date' => $date,
            'totalStudents' => (int) $stats['totalRecords'],
            'present' => (int) $stats['presentCount'],
            'absent' => (int) $stats['absentCount'],
            'late' => (int) $stats['lateCount'],
            'excused' => (int) $stats['excusedCount'],
            'attendanceRate' => (float) $stats['attendanceRate']
        ];
    }
    
    /**
     * Get monthly attendance summary for a student
     */
    public function getMonthlyAttendanceSummary($studentId, $year, $month)
    {
        $startDate = sprintf('%d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $sql = "
            SELECT 
                DATE_FORMAT(date, '%Y-%m-%d') as attendanceDate,
                direction as status,
                notes
            FROM gibbonAttendanceLogPerson
            WHERE gibbonPersonID = :studentId
            AND date BETWEEN :startDate AND :endDate
            ORDER BY date ASC
        ";
        
        $dailyRecords = $this->fetchAll($sql, [
            ':studentId' => $studentId,
            ':startDate' => $startDate,
            ':endDate' => $endDate
        ]);
        
        // Count by status
        $summary = [
            'Present' => 0,
            'Absent' => 0,
            'Late' => 0,
            'Excused' => 0
        ];
        
        foreach ($dailyRecords as $record) {
            $summary[$record['status']] = ($summary[$record['status']] ?? 0) + 1;
        }
        
        // Calculate total days in month
        $daysInMonth = (int) date('t', strtotime($startDate));
        $schoolDays = $daysInMonth - 8; // Assuming weekends off
        
        return [
            'studentId' => $studentId,
            'year' => $year,
            'month' => $month,
            'daysInMonth' => $daysInMonth,
            'schoolDays' => $schoolDays,
            'records' => count($dailyRecords),
            'summary' => $summary,
            'attendanceRate' => $schoolDays > 0 ? round((($summary['Present'] ?? 0) + ($summary['Late'] ?? 0) * 0.5) / $schoolDays * 100, 2) : 0,
            'dailyRecords' => $dailyRecords
        ];
    }
}