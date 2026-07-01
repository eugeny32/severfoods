<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$start_date = preg_replace('/[^0-9\-]/', '', $_GET['start_date'] ?? date('Y-m-d', strtotime(localToday() . ' -30 days')));
$end_date   = preg_replace('/[^0-9\-]/', '', $_GET['end_date']   ?? localToday());
$meal_type  = in_array($_GET['meal_type'] ?? '', ['breakfast','lunch','dinner','night'])
              ? $_GET['meal_type'] : 'all';
$point_id   = (isset($_GET['point_id']) && ctype_digit((string)$_GET['point_id']))
              ? (int)$_GET['point_id'] : null;

$user_role    = $_SESSION['role']              ?? 'admin';
$is_super     = ($user_role === 'super_admin');
$assigned_pid = $_SESSION['assigned_point_id'] ?? null;
if (!$is_super && $assigned_pid) $point_id = $assigned_pid;

$scannedLocal = "CONVERT_TZ(ml.scanned_at, '+00:00', COALESCE(mpt.tz_offset, '" . APP_TZ_OFFSET . "'))";
$sql = "SELECT e.id, e.full_name, e.organization, e.department,
               COUNT(*) as meals,
               COUNT(DISTINCT DATE($scannedLocal)) as days
        FROM meal_logs ml
        JOIN employees e ON ml.employee_id = e.id
        LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
        WHERE DATE($scannedLocal) BETWEEN :s AND :e
          AND ml.access_granted = 1";
$params = [':s' => $start_date, ':e' => $end_date];
if ($meal_type !== 'all') { $sql .= " AND ml.meal_type = :mt";      $params[':mt']  = $meal_type; }
if ($point_id)            { $sql .= " AND ml.meal_point_id = :pid"; $params[':pid'] = $point_id; }
$sql .= " GROUP BY e.id, e.full_name, e.organization, e.department ORDER BY e.organization, e.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dry rations per employee in the period
$dryByEmp = [];
$dryDetails = [];
try {
    $stmtDry = $pdo->prepare(
        "SELECT dr.employee_id, dr.ration_date, dr.ration_type, dr.status,
                e.full_name, e.organization, e.department, e.vjg_type,
                op.full_name as created_by_name
         FROM dry_rations dr
         JOIN employees e ON dr.employee_id = e.id
         LEFT JOIN employees op ON dr.created_by = op.id
         WHERE dr.ration_date BETWEEN :s AND :e
         ORDER BY e.organization, e.full_name, dr.ration_date"
    );
    $stmtDry->execute([':s' => $start_date, ':e' => $end_date]);
    $dryDetails = $stmtDry->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dryDetails as $d) {
        if ($d['status'] === 'active') {
            $dryByEmp[$d['employee_id']] = ($dryByEmp[$d['employee_id']] ?? 0) + 1;
        }
    }
} catch (PDOException $e) {}

// Enrich rows with dry_rations count
foreach ($rows as &$r) {
    $r['dry_rations'] = $dryByEmp[$r['id']] ?? 0;
}
unset($r);

// Group by org
$byOrg = [];
foreach ($rows as $r) {
    $byOrg[$r['organization']][] = $r;
}

function ec(mixed $v): string {
    $s = (string)($v ?? '');
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo '  xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
echo '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
echo '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo '  xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

echo '<Styles>' . "\n";
echo '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/><Font ss:FontName="Calibri" ss:Size="11"/></Style>' . "\n";
// Title row
echo '<Style ss:ID="s_title"><Font ss:FontName="Calibri" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#003366" ss:Pattern="Solid"/><Alignment ss:Horizontal="Left" ss:Vertical="Center"/></Style>' . "\n";
// Column header
echo '<Style ss:ID="s_head"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#003366"/><Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#B0BEC5"/></Borders></Style>' . "\n";
// Org section header
echo '<Style ss:ID="s_org"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#1e3a5f"/><Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Left" ss:Vertical="Center"/></Style>' . "\n";
// Data cell
echo '<Style ss:ID="s_data"><Font ss:FontName="Calibri" ss:Size="11"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>' . "\n";
// Number cell
echo '<Style ss:ID="s_num"><Font ss:FontName="Calibri" ss:Size="11"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>' . "\n";
// Subtotal row
echo '<Style ss:ID="s_sub"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/><Alignment ss:Horizontal="Right"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>' . "\n";
// Subtotal number
echo '<Style ss:ID="s_subn"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>' . "\n";
// Grand total
echo '<Style ss:ID="s_total"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#003366" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>' . "\n";
echo '</Styles>' . "\n";

echo '<Worksheet ss:Name="Сотрудники">' . "\n";
echo '<Table>' . "\n";

$widths = [180, 200, 170, 90, 90, 90];
foreach ($widths as $w) {
    echo '<Column ss:Width="' . $w . '"/>' . "\n";
}

// Title row
$title = 'Сводный отчёт по сотрудникам: ' . $start_date . ' — ' . $end_date
       . ($meal_type !== 'all' ? ' / ' . getMealTypeName($meal_type) : '');
echo '<Row ss:Height="28">' . "\n";
echo '<Cell ss:StyleID="s_title" ss:MergeAcross="5"><Data ss:Type="String">' . ec($title) . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";

// Column headers
$headers = ['Организация', 'ФИО', 'Подразделение', 'Приёмов пищи', 'Дней в столовой', 'Сух. паёк (дней)'];
echo '<Row ss:Height="24">' . "\n";
foreach ($headers as $h) {
    echo '<Cell ss:StyleID="s_head"><Data ss:Type="String">' . ec($h) . '</Data></Cell>' . "\n";
}
echo '</Row>' . "\n";

$grandMeals = 0;
$grandDays  = 0;
$grandDry   = 0;

foreach ($byOrg as $org => $emps) {
    $orgMeals = array_sum(array_column($emps, 'meals'));
    $orgDays  = array_sum(array_column($emps, 'days'));
    $orgDry   = array_sum(array_column($emps, 'dry_rations'));
    $grandMeals += $orgMeals;
    $grandDays  += $orgDays;
    $grandDry   += $orgDry;

    // Org header row
    echo '<Row ss:Height="20">' . "\n";
    echo '<Cell ss:StyleID="s_org" ss:MergeAcross="5"><Data ss:Type="String">' . ec($org) . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";

    foreach ($emps as $emp) {
        $dryStyle = (int)$emp['dry_rations'] > 0 ? 's_subn' : 's_num';
        echo '<Row ss:Height="18">' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String"></Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($emp['full_name'])       . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($emp['department'])      . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_num"><Data ss:Type="Number">'  . (int)$emp['meals']          . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_num"><Data ss:Type="Number">'  . (int)$emp['days']           . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="' . $dryStyle . '"><Data ss:Type="Number">' . (int)$emp['dry_rations'] . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
    }

    // Org subtotal
    echo '<Row ss:Height="20">' . "\n";
    echo '<Cell ss:StyleID="s_sub" ss:MergeAcross="2"><Data ss:Type="String">Итого по ' . ec($org) . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s_subn"><Data ss:Type="Number">' . $orgMeals . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s_subn"><Data ss:Type="Number">' . $orgDays  . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s_subn"><Data ss:Type="Number">' . $orgDry   . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";

    // Spacer
    echo '<Row ss:Height="6"><Cell><Data ss:Type="String"></Data></Cell></Row>' . "\n";
}

// Grand total
echo '<Row ss:Height="24">' . "\n";
echo '<Cell ss:StyleID="s_total" ss:MergeAcross="2"><Data ss:Type="String">ИТОГО</Data></Cell>' . "\n";
echo '<Cell ss:StyleID="s_total"><Data ss:Type="Number">' . $grandMeals . '</Data></Cell>' . "\n";
echo '<Cell ss:StyleID="s_total"><Data ss:Type="Number">' . $grandDays  . '</Data></Cell>' . "\n";
echo '<Cell ss:StyleID="s_total"><Data ss:Type="Number">' . $grandDry   . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";

echo '</Table>' . "\n";
echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
echo '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane>' . "\n";
echo '<ActivePane>2</ActivePane>' . "\n";
echo '</WorksheetOptions>' . "\n";
echo '</Worksheet>' . "\n";

// ── Sheet 2: dry rations detail ──────────────────────────────
if (!empty($dryDetails)) {
    echo '<Worksheet ss:Name="Сухой паёк">' . "\n";
    echo '<Table>' . "\n";
    $dryWidths = [90, 200, 180, 130, 140, 130, 100, 160];
    foreach ($dryWidths as $w) echo '<Column ss:Width="' . $w . '"/>' . "\n";
    $dryTitle = 'Сухой паёк / Выездное питание: ' . $start_date . ' — ' . $end_date;
    echo '<Row ss:Height="28"><Cell ss:StyleID="s_title" ss:MergeAcross="7"><Data ss:Type="String">' . ec($dryTitle) . '</Data></Cell></Row>' . "\n";
    echo '<Row ss:Height="24">' . "\n";
    foreach (['Дата', 'ФИО', 'Организация', 'Отдел', 'ВЖГ', 'Тип', 'Статус', 'Создал'] as $h) {
        echo '<Cell ss:StyleID="s_head"><Data ss:Type="String">' . ec($h) . '</Data></Cell>' . "\n";
    }
    echo '</Row>' . "\n";
    foreach ($dryDetails as $dr) {
        $typeLabel   = $dr['ration_type'] === 'dry_ration' ? 'Сухой паёк' : 'Выездное питание';
        $statusLabel = $dr['status'] === 'active' ? 'Активен' : 'Аннулирован';
        echo '<Row ss:Height="18">' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec(date('d.m.Y', strtotime($dr['ration_date']))) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($dr['full_name'])       . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($dr['organization'])    . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($dr['department'] ?? '') . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($dr['vjg_type'] ?? '')  . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($typeLabel)             . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($statusLabel)           . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($dr['created_by_name'] ?? '') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
    }
    echo '</Table>' . "\n";
    echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions>' . "\n";
    echo '</Worksheet>' . "\n";
}

echo '</Workbook>';

$content = ob_get_clean();
while (ob_get_level() > 0) { ob_end_clean(); }

$fname = 'sotrudniki_' . $start_date . '_' . $end_date . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: max-age=0, no-store');
header('Pragma: public');
echo $content;
exit;
