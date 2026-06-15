<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Add Stop');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Manage Stops'), 'stops_manage.php');
$page->breadcrumbs->add(__('Add Stop'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/stops_manage_add.php')) {
    $page->addError(__('Access denied'));
    return;
}

$routeID = $_GET['route'] ?? $_POST['routeID'] ?? null;
if (!$routeID) {
    $page->addError(__('Route ID required'));
    return;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // FIXED: Using standard PDO Prepare for the Insert to avoid "undefined method insert()"
        $sql = "INSERT INTO gibbonTransportStop (gibbonTransportRouteID, name, sequenceNumber, latitude, longitude, address, landmark, estimatedArrivalTime, comments, active) 
                VALUES (:routeID, :name, :seq, :lat, :lng, :addr, :land, :time, :comm, :act)";
        
        $stmt = $connection2->prepare($sql);
        $stmt->execute([
            'routeID' => $routeID,
            'name'    => $_POST['name'] ?? '',
            'seq'     => $_POST['sequenceNumber'] ?? 1,
            'lat'     => !empty($_POST['latitude']) ? $_POST['latitude'] : null,
            'lng'     => !empty($_POST['longitude']) ? $_POST['longitude'] : null,
            'addr'    => $_POST['address'] ?? '',
            'land'    => $_POST['landmark'] ?? null,
            'time'    => $_POST['estimatedArrivalTime'] ?? null,
            'comm'    => $_POST['comments'] ?? null,
            'act'     => isset($_POST['active']) ? 1 : 0
        ]);
        
        $page->addSuccess(__('Stop added successfully'));
        header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage.php&route=' . $routeID);
        exit;
        
    } catch (Exception $e) {
        $page->addError('Failed to add stop: ' . $e->getMessage());
    }
}

// Get Route Info
$stmt = $connection2->prepare("SELECT name FROM gibbonTransportRoute WHERE gibbonTransportRouteID = :id");
$stmt->execute(['id' => $routeID]);
$route = $stmt->fetch();

// Get next sequence number
$stmtSeq = $connection2->prepare("SELECT MAX(sequenceNumber) + 1 as next FROM gibbonTransportStop WHERE gibbonTransportRouteID = :routeID");
$stmtSeq->execute(['routeID' => $routeID]);
$nextSeqResult = $stmtSeq->fetch();
$nextSeq = $nextSeqResult['next'] ?? 1;

// --- MAP SUPPORT ASSETS ---
echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />';
echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';

echo '<h2>📍 ' . __('Add Stop to Route: ') . htmlspecialchars($route['name']) . '</h2>';

echo '<form method="POST" action="">';
echo '<input type="hidden" name="routeID" value="' . $routeID . '">';

echo '<table class="smallIntBorder" style="width:100%;max-width:850px;">';
echo '<tr><td style="width:30%;"><strong>' . __('Stop Name *') . '</strong></td><td><input type="text" name="name" required style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Order (Sequence) *') . '</strong></td><td><input type="number" name="sequenceNumber" value="' . $nextSeq . '" min="1" required style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Address *') . '</strong></td><td><textarea name="address" required style="width:100%;padding:8px;height:60px;"></textarea></td></tr>';

// GPS SECTION WITH MAP
echo '<tr><td><strong>' . __('GPS Location') . '</strong><br><small>' . __('Click the button to pick from map') . '</small></td><td>';
echo '<input type="text" id="lat" name="latitude" placeholder="Latitude" style="width:30%;padding:8px;display:inline-block;margin-right:2%;"> ';
echo '<input type="text" id="lng" name="longitude" placeholder="Longitude" style="width:30%;padding:8px;display:inline-block;margin-right:2%;"> ';
echo '<button type="button" class="button" onclick="toggleMap()" style="background:#2196F3;color:white;padding:8px;">🗺️ ' . __('Pick on Map') . '</button>';
echo '<div id="map-container" style="display:none; margin-top:10px; border:1px solid #ccc; border-radius:4px;">';
echo '<div id="map" style="height: 300px; width:100%;"></div>';
echo '<p style="font-size:11px; color:#666; margin:5px;">' . __('Click anywhere on the map to set the coordinates.') . '</p>';
echo '</div>';
echo '</td></tr>';

echo '<tr><td><strong>' . __('Estimated Arrival') . '</strong></td><td><input type="time" name="estimatedArrivalTime" style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Status') . '</strong></td><td><label><input type="checkbox" name="active" value="1" checked> ' . __('Active') . '</label></td></tr>';
echo '<tr><td colspan="2" style="text-align:center;padding:20px;"><button type="submit" class="button" style="background:#4CAF50;color:white;padding:12px 30px;font-size:16px;border:none;border-radius:4px;">✅ ' . __('Save Stop') . '</button> <a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage.php&route=' . $routeID . '" class="button" style="background:#999;color:white;padding:12px 30px;margin-left:10px;text-decoration:none;">' . __('Cancel') . '</a></td></tr>';
echo '</table>';
echo '</form>';

// MAP JAVASCRIPT
?>
<script>
let map, marker;

function toggleMap() {
    const container = document.getElementById('map-container');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        initMap();
    } else {
        container.style.display = 'none';
    }
}

function initMap() {
    if (map) return; // Already initialized

    // Default view: Kinshasa, Congo (Adjust coordinates if needed)
    map = L.map('map').setView([-4.4419, 15.2663], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    map.on('click', function(e) {
        const lat = e.latlng.lat.toFixed(6);
        const lng = e.latlng.lng.toFixed(6);
        
        document.getElementById('lat').value = lat;
        document.getElementById('lng').value = lng;

        if (marker) {
            marker.setLatLng(e.latlng);
        } else {
            marker = L.marker(e.latlng).addTo(map);
        }
    });
}
</script>