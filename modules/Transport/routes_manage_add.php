<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Add Route');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Manage Routes'), 'routes_manage.php');
$page->breadcrumbs->add(__('Add Route'));

// Load Select2 Assets
echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
echo '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>';

if (!isActionAccessible($guid, $connection2, '/modules/Transport/routes_manage_add.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate supervisor requirement
        $supervisorEnabled = isset($_POST['supervisorEnabled']) ? 'Y' : 'N';
        $gibbonPersonIDSupervisor = null;
        
        if ($supervisorEnabled === 'Y') {
            if (empty($_POST['gibbonPersonIDSupervisor'])) {
                throw new Exception('Supervisor is required when supervisor mode is enabled.');
            }
            
            // Validate supervisor is active staff
            $stmtCheck = $connection2->prepare("
                SELECT p.gibbonPersonID 
                FROM gibbonPerson p
                INNER JOIN gibbonStaff s ON p.gibbonPersonID = s.gibbonPersonID
                WHERE p.gibbonPersonID = :personID AND p.status = 'Full'
            ");
            $stmtCheck->execute(['personID' => $_POST['gibbonPersonIDSupervisor']]);
            
            if (!$stmtCheck->fetch()) {
                throw new Exception('Selected supervisor must be an active staff member.');
            }
            
            $gibbonPersonIDSupervisor = $_POST['gibbonPersonIDSupervisor'];
        }
        
        $sql = "INSERT INTO gibbonTransportRoute (name, routeType, nameShort, vehicleNumber, vehicleType, capacity, driverID, driverPhone, active, comments, supervisorEnabled, gibbonPersonIDSupervisor) 
                VALUES (:name, :routeType, :nameShort, :vehicleNumber, :vehicleType, :capacity, :driverID, :driverPhone, :active, :comments, :supervisorEnabled, :gibbonPersonIDSupervisor)";
        
        $stmt = $connection2->prepare($sql);
        
        $result = $stmt->execute([
            'name'          => $_POST['name'] ?? '',
            'routeType'     => $_POST['routeType'] ?? 'both',
            'nameShort'     => $_POST['nameShort'] ?? '',
            'vehicleNumber' => $_POST['vehicleNumber'] ?? '',
            'vehicleType'   => $_POST['vehicleType'] ?? 'Bus',
            'capacity'      => $_POST['capacity'] ?? 50,
            'driverID'      => !empty($_POST['driverID']) ? $_POST['driverID'] : null,
            'driverPhone'   => !empty($_POST['driverPhone']) ? $_POST['driverPhone'] : null,
            'active'        => isset($_POST['active']) ? 1 : 0,
            'comments'      => $_POST['comments'] ?? null,
            'supervisorEnabled' => $supervisorEnabled,
            'gibbonPersonIDSupervisor' => $gibbonPersonIDSupervisor
        ]);
        
        $page->addSuccess(__('Route created successfully.'));
        header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php');
        exit;
        
    } catch (Exception $e) {
        $page->addError('Failed to create route: ' . $e->getMessage());
    }
}

// Get drivers
$drivers = $connection2->query("
    SELECT gibbonPersonID, firstName, surname, phone1
    FROM gibbonPerson
    WHERE status = 'Full'
    AND gibbonRoleIDPrimary IN (
        SELECT gibbonRoleID FROM gibbonRole WHERE category = 'Staff'
    )
    ORDER BY surname, firstName
")->fetchAll();

echo '<h2>+ ' . __('Add Transport Route') . '</h2>';

echo '<form method="POST" action="" id="routeForm">';

echo '<table class="smallIntBorder" style="width:100%;max-width:800px;">';
echo '<tr><td style="width:30%;"><strong>' . __('Route Name') . ' *</strong></td><td><input type="text" name="name" required style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Route Type') . ' *</strong><br><small>' . __('Determines pickup or dropoff') . '</small></td><td><select name="routeType" style="width:100%;padding:8px;"><option value="to_school">🌅 ' . __('To School (Morning Pickups)') . '</option><option value="from_school">🌆 ' . __('From School (Afternoon Dropoffs)') . '</option><option value="both" selected>🔄 ' . __('Both (Bidirectional)') . '</option></select></td></tr>';
echo '<tr><td><strong>' . __('Route Code') . ' *</strong><br><small>' . __('(e.g., R001)') . '</small></td><td><input type="text" name="nameShort" required style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Vehicle Number') . ' *</strong></td><td><input type="text" name="vehicleNumber" required style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Vehicle Type') . '</strong></td><td><select name="vehicleType" style="width:100%;padding:8px;"><option value="Bus">' . __('Bus') . '</option><option value="Van">' . __('Van') . '</option><option value="Minibus">' . __('Minibus') . '</option><option value="Car">' . __('Car') . '</option></select></td></tr>';
echo '<tr><td><strong>' . __('Capacity') . '</strong><br><small>' . __('Maximum students') . '</small></td><td><input type="number" name="capacity" value="50" min="1" style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Driver') . '</strong></td><td><select name="driverID" style="width:100%;padding:8px;"><option value="">' . __('Not assigned') . '</option>';
foreach ($drivers as $driver) {
    echo '<option value="' . $driver['gibbonPersonID'] . '">' . htmlspecialchars($driver['firstName'] . ' ' . $driver['surname']) . ' (' . htmlspecialchars($driver['phone1']) . ')</option>';
}
echo '</select></td></tr>';
echo '<tr><td><strong>' . __('Driver Phone') . '</strong><br><small>' . __('Backup contact') . '</small></td><td><input type="text" name="driverPhone" style="width:100%;padding:8px;"></td></tr>';

// ===== SUPERVISOR SECTION =====
echo '<tr><td colspan="2" style="background:#f5f5f5;padding:10px;"><strong>👤 ' . __('Route Supervisor') . '</strong></td></tr>';
echo '<tr><td><strong>' . __('Enable Supervisor') . '</strong><br><small>' . __('Assign a staff member to supervise this route') . '</small></td>';
echo '<td><label><input type="checkbox" name="supervisorEnabled" id="supervisorEnabled" value="Y"> ' . __('Yes, enable supervisor for this route') . '</label></td></tr>';

echo '<tr id="supervisorRow" style="display:none;"><td><strong>' . __('Select Supervisor') . ' *</strong><br><small>' . __('Type to search staff members') . '</small></td>';
echo '<td><select name="gibbonPersonIDSupervisor" id="supervisorSelect" style="width:100%;"></select></td></tr>';
// ===== END SUPERVISOR SECTION =====

echo '<tr><td><strong>' . __('Status') . '</strong></td><td><label><input type="checkbox" name="active" value="1" checked> ' . __('Active') . '</label></td></tr>';
echo '<tr><td><strong>' . __('Comments') . '</strong></td><td><textarea name="comments" style="width:100%;padding:8px;height:80px;"></textarea></td></tr>';
echo '<tr><td colspan="2" style="text-align:center;padding:20px;"><button type="submit" class="button" style="background:#4CAF50;color:white;padding:12px 30px;font-size:16px;border:none;border-radius:4px;cursor:pointer;">✅ ' . __('Create Route') . '</button> <a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php" class="button" style="background:#999;color:white;padding:12px 30px;margin-left:10px;text-decoration:none;">' . __('Cancel') . '</a></td></tr>';
echo '</table>';

echo '</form>';
?>

<script>
$(document).ready(function() {
    // Initialize Select2 for supervisor with AJAX search
    $('#supervisorSelect').select2({
        ajax: {
            url: '<?php echo $_SESSION[$guid]['absoluteURL']; ?>/modules/Transport/ajax/staffSearch.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term };
            },
            processResults: function(data) {
                return { results: data.results || [] };
            },
            cache: true
        },
        placeholder: '<?php echo __("Type to search staff..."); ?>',
        minimumInputLength: 2,
        allowClear: true,
        width: '100%'
    });
    
    // Toggle supervisor dropdown visibility
    $('#supervisorEnabled').change(function() {
        if ($(this).is(':checked')) {
            $('#supervisorRow').slideDown();
        } else {
            $('#supervisorRow').slideUp();
            $('#supervisorSelect').val(null).trigger('change');
        }
    });
    
    // Form validation
    $('#routeForm').on('submit', function(e) {
        if ($('#supervisorEnabled').is(':checked') && !$('#supervisorSelect').val()) {
            e.preventDefault();
            alert('<?php echo __("Please select a supervisor when supervisor mode is enabled."); ?>');
            return false;
        }
    });
});
</script>

<style>
.select2-container--default .select2-selection--single {
    height: 38px;
    padding: 5px;
    border: 1px solid #ccc;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}
</style>
