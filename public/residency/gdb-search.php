<?php
// Name lookup on glassdatabase.org for the resident editor. Only for a signed-in
// resident or admin, so it can't be used as an open proxy.
require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json');
if (!editing_resident() && !current_admin()) {
    http_response_code(403);
    exit('{"makers":[]}');
}
echo json_encode(['makers' => gdb_search(clip((string)($_GET['q'] ?? ''), 80))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
