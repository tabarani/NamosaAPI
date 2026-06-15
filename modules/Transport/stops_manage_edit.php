<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Edit Stop');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Manage Stops'), 'stops_manage.php');
$page->breadcrumbs->add(__('Edit Stop'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/stops_manage_edit.php')) {
    $page->addError(__('Access denied'));
    return;
}

$stopID = $_GET['id'] ?? $_POST['stopID'] ?? null;
if (!$stopID) {
    $page->addError(__('Stop ID required'));
    return;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $routeID = $_POST['routeID'] ?? null;
        
        // FIXED: Using standard SQL UPDATE to avoid "undefined method update()"
        $sql = "UPDATE gibbonTransportStop SET 
                gibbonTransportRouteID = :routeID,
                name = :name,
                sequenceNumber = :seq,
                latitude = :lat,
                longitude = :lng,
                address = :addr,
                landmark = :land,
                estimatedArrivalTime = :time,
                comments = :comm,
                active = :act
                WHERE gibbonTransportStopID = :stopID";
        
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
            'act'     => isset($_POST['active']) ? 1 : 0,
            'stopID'  => $stopID
        ]);
        
        $page->addSuccess(__('Stop updated successfully'));
        header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage.php&route=' . $routeID);
        exit;
        
    } catch (Exception $e) {
        $page->addError('Failed to update stop: ' . $e->getMessage());
    }
}

// FIXED: Get stop details using prepare/execute
$stmt = $connection2->prepare("SELECT * FROM gibbonTransportStop WHERE gibbonTransportStopID = :id");
$stmt->execute(['id' => $stopID]);
$stop = $stmt->fetch();

if (!$stop) {
    $page->addError(__('Stop not found'));
    return;
}

// Get routes for dropdown
$routes = $connection2->query("SELECT gibbonTransportRouteID, name FROM gibbonTransportRoute WHERE active = 1 ORDER BY name")->fetchAll();

// MAP SUPPORT ASSETS
echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />';
echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';

echo '<h2>✏️ ' . __('Edit Transport Stop') . '</h2>';

echo '<form method="POST" action="">';
echo '<input type="hidden" name="stopID" value="' . $stopID . '">';

echo '<table class="smallIntBorder" style="width:100%;max-width:850px;">';
echo '<tr><td style="width:30%;"><strong>' . __('Route *') . '</strong></td><td><select name="routeID" required style="width:100%;padding:8px;">';
foreach ($routes as $route) {
    $selected = ($route['gibbonTransportRouteID'] == $stop['gibbonTransportRouteID']) ? 'selected' : '';
    echo '<option value="' . $route['gibbonTransportRouteID'] . '" ' . $selected . '>' . htmlspecialchars($route['name']) . '</option>';
}
echo '</select></td></tr>';

echo '<tr><td><strong>' . __('Stop Name *') . '</strong></td><td><input type="text" name="name" value="' . htmlspecialchars($stop['name']) . '" required style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Sequence Number *') . '</strong></td><td><input type="number" name="sequenceNumber" value="' . $stop['sequenceNumber'] . '" required min="1" style="width:100%;padding:8px;"></td></tr>';

// GPS SECTION WITH MAP
echo '<tr><td><strong>' . __('GPS Location') . '</strong></td><td>';
echo '<input type="text" id="lat" name="latitude" value="' . ($stop['latitude'] ?? '') . '" placeholder="Latitude" style="width:30%;padding:8px;display:inline-block;margin-right:2%;"> ';
echo '<input type="text" id="lng" name="longitude" value="' . ($stop['longitude'] ?? '') . '" placeholder="Longitude" style="width:30%;padding:8px;display:inline-block;margin-right:2%;"> ';
echo '<button type="button" class="button" onclick="toggleMap()" style="background:#2196F3;color:white;padding:8px;">🗺️ ' . __('Pick on Map') . '</button>';
echo '<div id="map-container" style="display:none; margin-top:10px; border:1px solid #ccc; border-radius:4px;">';
echo '<div id="map" style="height: 300px; width:100%;"></div>';
echo '</div>';
echo '</td></tr>';

echo '<tr><td><strong>' . __('Address') . '</strong></td><td><textarea name="address" style="width:100%;padding:8px;height:60px;">' . htmlspecialchars($stop['address'] ?? '') . '</textarea></td></tr>';
echo '<tr><td><strong>' . __('Estimated Arrival') . '</strong></td><td><input type="time" name="estimatedArrivalTime" value="' . ($stop['estimatedArrivalTime'] ? date('H:i', strtotime($stop['estimatedArrivalTime'])) : '') . '" style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Status') . '</strong></td><td><label><input type="checkbox" name="active" value="1" ' . ($stop['active'] ? 'checked' : '') . '> ' . __('Active') . '</label></td></tr>';
echo '<tr><td colspan="2" style="text-align:center;padding:20px;">';
echo '<button type="submit" class="button" style="background:#2196F3;color:white;padding:12px 30px;font-size:16px;border:none;border-radius:4px;cursor:pointer;margin-right:10px;">💾 ' . __('Update Stop') . '</button>';
echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage.php&route=' . $stop['gibbonTransportRouteID'] . '" class="button" style="background:#999;color:white;padding:12px 30px;font-size:16px;text-decoration:none;border-radius:4px;">' . __('Cancel') . '</a>';
echo '</td></tr>';
echo '</table>';
echo '</form>';
?>

<script>
let map, marker;
const initialLat = <?php echo !empty($stop['latitude']) ? $stop['latitude'] : '-4.4419'; ?>;
const initialLng = <?php echo !empty($stop['longitude']) ? $stop['longitude'] : '15.2663'; ?>;

function toggleMap() {
    const container = document.getElementById('map-container');
    container.style.display = (container.style.display === 'none') ? 'block' : 'none';
    if (container.style.display === 'block') initMap();
}

function initMap() {
    if (map) {
        map.invalidateSize();
        return;
    }

    map = L.map('map').setView([initialLat, initialLng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    // Place marker on existing location
    if (document.getElementById('lat').value) {
        marker = L.marker([initialLat, initialLng]).addTo(map);
    }

    map.on('click', function(e) {
        const lat = e.latlng.lat.toFixed(6);
        const lng = e.latlng.lng.toFixed(6);
        document.getElementById('lat').value = lat;
        document.getElementById('lng').value = lng;
        if (marker) marker.setLatLng(e.latlng);
        else marker = L.marker(e.latlng).addTo(map);
    });
}
</script>