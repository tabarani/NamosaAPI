# Transport REST API Feature Scenarios

The Transport module API now exposes endpoints for the full feature roadmap. All endpoints are served by `modules/Transport/api/v1/index.php` through the `path` query parameter used by the existing module router.

| Feature | Endpoint | Scenario |
| --- | --- | --- |
| Parent status | `GET /transport-status/child/{gibbonPersonID}` | Parent checks today's assignment, latest boarding/dropoff events, and active alerts. |
| Boarding confirmation | `GET /boarding/route/{routeID}`, `POST /boarding/events` | Supervisor loads the route checklist and records pickup/dropoff. |
| Notifications | `POST /notifications` | System queues SMS/email after boarding, delay, emergency, or route cancellation. |
| Missing event alerts | `GET /missing-alerts`, `POST /missing-alerts/run` | Cron/admin detects students assigned to transport without today's event. |
| Supervisor mobile | `GET /supervisor/routes/{routeID}` | Mobile UI gets route metadata and checklist in one payload. |
| Offline sync | `POST /sync/offline-events` | Mobile app uploads cached events after connectivity returns. |
| Vehicle tracking | `POST /tracking/locations`, `GET /tracking/vehicles/{routeID}` | Mobile phone or hardware GPS posts latest vehicle location. |
| ETA | `GET /eta/route/{routeID}` | Parent/admin views scheduled stop ETAs with latest event context. |
| Route planning | `GET /planning/routes` | Admin reviews route capacity utilization. |
| Vehicles | `GET|POST /vehicles` | Admin manages vehicle, license, insurance, and maintenance metadata. |
| Emergency | `POST /emergency` | Supervisor raises a critical alert with optional event/location details. |
| Incidents | `GET|POST /incidents` | Staff records breakdown, accident, medical, behavior, or unauthorized pickup incidents. |
| Pickup rules | `GET|POST /pickup-rules` | Staff manages authorised/blocked pickup people for a student. |
| QR boarding | `POST /qr/resolve` | Supervisor scans a QR token and resolves the student assignment. |
| Photos | `POST /photos` | Supervisor attaches photo evidence to a transport event. |
| Billing | `GET|POST /billing` | Finance tracks route transport fees by student and period. |
| Reports | `GET /reports/{capacity|late-events|alerts}` | Admin gets operational summaries. |
| Audit logs | `GET /audit-logs` | Admin reviews sensitive transport changes. |
| OneRoster export | `GET /integrations/oneroster/export` | External systems consume roster-aligned assignment data. |
| Scenario catalogue | `GET /scenarios` | API consumers discover suggested scenarios and endpoints. |

Run `modules/Transport/sql/migrate_v1.2_to_v1.3.sql` before using endpoints backed by new tables: vehicles, vehicle locations, incidents, pickup rules, billing, and audit logs.
