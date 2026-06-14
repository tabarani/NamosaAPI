<?php
/**
 * Student Controller
 * Handles all student-related endpoints
 */

namespace NamosaAPI\Controllers;

use NamosaAPI\Lib\Response;
use NamosaAPI\Repositories\StudentRepository;

class StudentController extends BaseController
{
    private $repository;
    
    public function __construct()
    {
        parent::__construct();
        $this->repository = new StudentRepository();
    }
    
    /**
     * GET /students
     * Get all students with pagination
     */
    public function index()
    {
        $limit = (int) ($_GET['limit'] ?? 100);
        $offset = (int) ($_GET['offset'] ?? 0);
        $limit = min($limit, 200); // Max 200 per page
        
        $filters = [
            'yearGroup' => $_GET['yearGroup'] ?? null,
            'class' => $_GET['class'] ?? null,
            'search' => $_GET['search'] ?? null
        ];
        
        $result = $this->repository->getAll($limit, $offset, $filters);
        
        // Build pagination metadata
        $baseUrl = $this->getBaseUrl() . '/students';
        
        Response::paginated(
            $result['data'],
            $result['total'],
            $limit,
            $offset,
            $baseUrl,
            array_filter($filters)
        );
    }
    
    /**
     * GET /students/{id}
     * Get single student by ID
     */
    public function show($id)
    {
        if (empty($id)) {
            Response::error('Student ID is required', 400, 'VALIDATION_ERROR');
        }
        
        $student = $this->repository->getById($id);
        
        if (!$student) {
            Response::error('Student not found', 404, 'NOT_FOUND');
        }
        
        Response::success($student, 'Student retrieved successfully');
    }
    
    /**
     * GET /students/search
     * Search students by name
     */
    public function search()
    {
        $query = $_GET['q'] ?? $_GET['query'] ?? '';
        
        if (strlen($query) < 2) {
            Response::error('Search query must be at least 2 characters', 400, 'VALIDATION_ERROR');
        }
        
        $limit = min((int) ($_GET['limit'] ?? 20), 50);
        $results = $this->repository->search($query, $limit);
        
        Response::success($results, 'Search completed', 200, [
            'query' => $query,
            'count' => count($results)
        ]);
    }
    
    /**
     * GET /students/{id}/parents
     * Get student's parents
     */
    public function getParents($id)
    {
        if (empty($id)) {
            Response::error('Student ID is required', 400, 'VALIDATION_ERROR');
        }
        
        $student = $this->repository->getById($id);
        
        if (!$student) {
            Response::error('Student not found', 404, 'NOT_FOUND');
        }
        
        Response::success($student['parents'], 'Parents retrieved successfully');
    }
    
    /**
     * GET /students/{id}/siblings
     * Get student's siblings
     */
    public function getSiblings($id)
    {
        if (empty($id)) {
            Response::error('Student ID is required', 400, 'VALIDATION_ERROR');
        }
        
        $student = $this->repository->getById($id);
        
        if (!$student) {
            Response::error('Student not found', 404, 'NOT_FOUND');
        }
        
        Response::success($student['siblings'], 'Siblings retrieved successfully');
    }
    
    /**
     * Helper: Get base URL
     */
    private function getBaseUrl()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['PHP_SELF']);
        
        return $protocol . '://' . $host . $path;
    }
}