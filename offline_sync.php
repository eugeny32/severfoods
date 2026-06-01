<?php
/**
 * Offline Sync API
 *
 * GET  ?action=employees  — full active employee list for offline cache
 * GET  ?action=status     — server time, session info
 * POST JSON { action:"batch_scan", scans:[...] } — process queued offline scans
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

checkAuth();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'employees') {
        $rows = $pdo->query(
            "SELECT id, full_name, organization, department, vjg_type,
                    qr_code, qr_status, qr_expires_at, is_active, price
             FROM employees
             WHERE is_active = 1
             ORDER BY full_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'   => true,
            'data'      => $rows,
            'count'     => count($rows),
            'timestamp' => time(),
            'date'      => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'status') {
        echo json_encode([
            'success'     => true,
            'server_time' => date('Y-m-d H:i:s'),
            'user'        => $_SESSION['user_name']       ?? '',
            'role'        => $_SESSION['role']            ?? '',
            'point_id'    => $_SESSION['meal_point_id']   ?? null,
            'point_name'  => $_SESSION['meal_point_name'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── POST ──────────────────────────────────────────────────
if ($method === 'POST') {
    Csrf::guard();

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!$body || !is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $action = $body['action'] ?? 'batch_scan';

    if ($action === 'batch_scan') {
        $scans          = $body['scans']         ?? [];
        $savedPointId   = $_SESSION['meal_point_id']   ?? null;
        $savedPointName = $_SESSION['meal_point_name'] ?? null;

        $results  = [];
        $ok = $fail = $skipped = 0;

        foreach ($scans as $scan) {
            $qr        = trim($scan['qr_code']        ?? '');
            $localId   = $scan['local_id']             ?? null;
            $pointId   = $scan['meal_point_id']        ?? $savedPointId;
            $pointName = $scan['meal_point_name']      ?? $savedPointName;
            $scanTime  = $scan['scanned_at']           ?? null;

            if (!$qr) {
                $results[] = [
                    'local_id' => $localId,
                    'success'  => false,
                    'message'  => 'Пустой QR-код',
                    'code'     => 'EMPTY_QR',
                ];
                $fail++;
                continue;
            }

            // Temporarily override session meal point for processAccess
            $_SESSION['meal_point_id']   = $pointId;
            $_SESSION['meal_point_name'] = $pointName;

            $result             = processAccess($pdo, $qr, null);
            $result['local_id'] = $localId;
            $results[]          = $result;

            // Best-effort: backfill original offline scan timestamp
            if ($result['success'] && !empty($scanTime)) {
                try {
                    $pdo->prepare(
                        "UPDATE meal_logs SET scanned_at = ?
                         WHERE id = (
                             SELECT ml2.id FROM (
                                 SELECT id FROM meal_logs
                                 WHERE employee_id = (
                                     SELECT id FROM employees WHERE qr_code = ? LIMIT 1
                                 )
                                 ORDER BY id DESC LIMIT 1
                             ) ml2
                         )"
                    )->execute([$scanTime, $qr]);
                } catch (PDOException $e) {}
            }

            $code = $result['code'] ?? '';
            if ($result['success'] || in_array($code, ['ALREADY_ATE', 'REPEAT_SCAN'])) {
                $ok++;
            } elseif ($code === 'NO_MEAL_TIME') {
                $skipped++;
            } else {
                $fail++;
            }
        }

        // Restore session
        $_SESSION['meal_point_id']   = $savedPointId;
        $_SESSION['meal_point_name'] = $savedPointName;

        echo json_encode([
            'success'   => true,
            'processed' => count($results),
            'ok'        => $ok,
            'fail'      => $fail,
            'skipped'   => $skipped,
            'results'   => $results,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
