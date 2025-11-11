<?php
// api/fires.php - Try MariaDB (XAMPP) first, fall back to file-backed JSON if DB unavailable
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$dataFile = __DIR__ . '/data.json';

$pdo = get_pdo(); // returns PDO or null

// Helper: ensure file exists
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// We will use the existing `vuchay` table if available (schema provided by chay_rung.sql).
// Do not attempt to create a new table here.

// File-backed helper loader
function load_file_data($dataFile) {
    $raw = file_get_contents($dataFile);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    return $data;
}

if ($method === 'GET') {
    if ($pdo) {
        try {
            // Query vuchay and join to get district and province names when available
            $sql = 'SELECT v.ma_vu_chay, v.ten, v.vi_do, v.kinh_do, v.thoi_gian_phat_hien, v.ma_muc_do, v.dien_tich_anh_huong_ha, v.nguyen_nhan, v.link_bao_chi, v.ma_trang_thai, q.ten AS district_name, t.ten AS province_name
                    FROM vuchay v
                    LEFT JOIN quanhuyen q ON v.ma_huyen = q.ma_huyen
                    LEFT JOIN tinhthanh t ON q.ma_tinh = t.ma_tinh
                    ORDER BY v.ma_vu_chay ASC';
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => isset($r['ma_vu_chay']) ? (int)$r['ma_vu_chay'] : (int)$r['ma_vu_chay'],
                    'name' => $r['ten'],
                    'coordinates' => [floatval($r['vi_do']), floatval($r['kinh_do'])],
                    'discoveryTime' => date('Y-m-d\TH:i:s', strtotime($r['thoi_gian_phat_hien'])),
                    'dangerLevel' => $r['ma_muc_do'],
                    'affectedArea' => isset($r['dien_tich_anh_huong_ha']) ? floatval($r['dien_tich_anh_huong_ha']) : 0,
                    'province' => $r['province_name'] ?: '',
                    'status' => $r['ma_trang_thai'],
                    'district' => $r['district_name'] ?: '',
                    'cause' => $r['nguyen_nhan'],
                    'newsUrl' => $r['link_bao_chi'],
                ];
            }
            echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            error_log('DB GET failed: ' . $e->getMessage());
            // fallback to file
        }
    }

    // file fallback
    $data = load_file_data($dataFile);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['name']) || !isset($input['coordinates']) || count($input['coordinates']) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }

    // normalize fields
    $lat = floatval($input['coordinates'][0]);
    $lng = floatval($input['coordinates'][1]);
    $discoveryTimeRaw = isset($input['discoveryTime']) ? $input['discoveryTime'] : '';
    $discoveryTime = null;
    if ($discoveryTimeRaw) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $discoveryTimeRaw) ?: new DateTime($discoveryTimeRaw);
        if ($dt) $discoveryTime = $dt->format('Y-m-d H:i:s');
    }
    if (!$discoveryTime) {
        $discoveryTime = date('Y-m-d H:i:s');
    }

    $dangerLevel = isset($input['dangerLevel']) ? $input['dangerLevel'] : null;
    $affectedArea = isset($input['affectedArea']) ? floatval($input['affectedArea']) : 0;
    $province = isset($input['province']) ? $input['province'] : '';
    $status = isset($input['status']) ? $input['status'] : 'active';
    $district = isset($input['district']) ? $input['district'] : '';
    $cause = isset($input['cause']) ? $input['cause'] : '';
    $newsUrl = isset($input['newsUrl']) ? $input['newsUrl'] : '';

    if ($pdo) {
        try {
            // Resolve district name to ma_huyen if possible
            $ma_huyen = null;
            if ($district) {
                $q = $pdo->prepare('SELECT ma_huyen FROM quanhuyen WHERE ten = ? LIMIT 1');
                $q->execute([$district]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['ma_huyen'])) {
                    $ma_huyen = (int)$row['ma_huyen'];
                } else {
                    // not found: leave NULL (or optionally insert new quanhuyen)
                    $ma_huyen = null;
                }
            }

            $sql = 'INSERT INTO vuchay (ten, vi_do, kinh_do, thoi_gian_phat_hien, dien_tich_anh_huong_ha, nguyen_nhan, link_bao_chi, ma_huyen, ma_muc_do, ma_trang_thai) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['name'], $lat, $lng, $discoveryTime, $affectedArea, $cause, $newsUrl, $ma_huyen, $dangerLevel, $status
            ]);
            $insertedId = (int)$pdo->lastInsertId();
            http_response_code(201);
            echo json_encode(['id' => $insertedId]);
            exit;
        } catch (Exception $e) {
            error_log('DB INSERT failed: ' . $e->getMessage());
            // fallback to file
        }
    }

    // file-backed fallback behavior (append to data.json)
    $data = load_file_data($dataFile);

    // compute new id
    $maxId = 0;
    foreach ($data as $item) {
        if (isset($item['id']) && is_numeric($item['id']) && (int)$item['id'] > $maxId) {
            $maxId = (int)$item['id'];
        }
    }
    $newId = $maxId + 1;

    $entry = [
        'id' => $newId,
        'name' => $input['name'],
        'coordinates' => [$lat, $lng],
        'discoveryTime' => date('Y-m-d\TH:i:s', strtotime($discoveryTime)),
        'dangerLevel' => $dangerLevel,
        'affectedArea' => $affectedArea,
        'province' => $province,
        'status' => $status,
        'district' => $district,
        'cause' => $cause,
        'newsUrl' => $newsUrl,
    ];

    // append and save using lock
    $data[] = $entry;

    $fp = fopen($dataFile, 'c+');
    if ($fp === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not open data file']);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'Could not lock data file']);
        exit;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    http_response_code(201);
    echo json_encode(['id' => $newId]);
    exit;
}

// Support PUT (update) and DELETE (remove)
if ($method === 'PUT') {
    // Expect id in query string
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$id || !$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id or payload']);
        exit;
    }

    // normalize fields similar to POST
    $lat = isset($input['coordinates'][0]) ? floatval($input['coordinates'][0]) : null;
    $lng = isset($input['coordinates'][1]) ? floatval($input['coordinates'][1]) : null;
    $discoveryTimeRaw = isset($input['discoveryTime']) ? $input['discoveryTime'] : '';
    $discoveryTime = null;
    if ($discoveryTimeRaw) {
        $dt = DateTime::createFromFormat('Y-m-d\\TH:i:s', $discoveryTimeRaw) ?: new DateTime($discoveryTimeRaw);
        if ($dt) $discoveryTime = $dt->format('Y-m-d H:i:s');
    }
    $dangerLevel = isset($input['dangerLevel']) ? $input['dangerLevel'] : null;
    $affectedArea = isset($input['affectedArea']) ? floatval($input['affectedArea']) : 0;
    $province = isset($input['province']) ? $input['province'] : '';
    $status = isset($input['status']) ? $input['status'] : 'active';
    $district = isset($input['district']) ? $input['district'] : '';
    $cause = isset($input['cause']) ? $input['cause'] : '';
    $newsUrl = isset($input['newsUrl']) ? $input['newsUrl'] : '';

    if ($pdo) {
        try {
            $ma_huyen = null;
            if ($district) {
                $q = $pdo->prepare('SELECT ma_huyen FROM quanhuyen WHERE ten = ? LIMIT 1');
                $q->execute([$district]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['ma_huyen'])) $ma_huyen = (int)$row['ma_huyen'];
            }

            $sql = 'UPDATE vuchay SET ten = ?, vi_do = ?, kinh_do = ?, thoi_gian_phat_hien = ?, dien_tich_anh_huong_ha = ?, nguyen_nhan = ?, link_bao_chi = ?, ma_huyen = ?, ma_muc_do = ?, ma_trang_thai = ? WHERE ma_vu_chay = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['name'], $lat, $lng, $discoveryTime, $affectedArea, $cause, $newsUrl, $ma_huyen, $dangerLevel, $status, $id
            ]);

            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            error_log('DB UPDATE failed: ' . $e->getMessage());
            // fallback to file
        }
    }

    // file fallback: update item with matching id
    $data = load_file_data($dataFile);
    $found = false;
    foreach ($data as &$item) {
        if (isset($item['id']) && intval($item['id']) === $id) {
            $item['name'] = $input['name'];
            if ($lat !== null && $lng !== null) $item['coordinates'] = [$lat, $lng];
            if ($discoveryTime) $item['discoveryTime'] = date('Y-m-d\\TH:i:s', strtotime($discoveryTime));
            $item['dangerLevel'] = $dangerLevel;
            $item['affectedArea'] = $affectedArea;
            $item['province'] = $province;
            $item['status'] = $status;
            $item['district'] = $district;
            $item['cause'] = $cause;
            $item['newsUrl'] = $newsUrl;
            $found = true;
            break;
        }
    }
    if ($found) {
        $fp = fopen($dataFile, 'c+');
        if ($fp === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not open data file']);
            exit;
        }
        if (!flock($fp, LOCK_EX)) { fclose($fp); http_response_code(500); echo json_encode(['error' => 'Could not lock data file']); exit; }
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['success' => true]);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }

    if ($pdo) {
        try {
            $stmt = $pdo->prepare('DELETE FROM vuchay WHERE ma_vu_chay = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            error_log('DB DELETE failed: ' . $e->getMessage());
            // fallback to file
        }
    }

    // file fallback
    $data = load_file_data($dataFile);
    $new = [];
    $removed = false;
    foreach ($data as $item) {
        if (isset($item['id']) && intval($item['id']) === $id) {
            $removed = true; continue;
        }
        $new[] = $item;
    }
    if (!$removed) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $fp = fopen($dataFile, 'c+');
    if ($fp === false) { http_response_code(500); echo json_encode(['error' => 'Could not open data file']); exit; }
    if (!flock($fp, LOCK_EX)) { fclose($fp); http_response_code(500); echo json_encode(['error' => 'Could not lock data file']); exit; }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
