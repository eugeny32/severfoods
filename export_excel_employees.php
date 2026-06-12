<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$start_date = preg_replace('/[^0-9\-]/', '', $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$end_date   = preg_replace('/[^0-9\-]/', '', $_GET['end_date']   ?? date('Y-m-d'));

$sql = "SELECT e.id, e.full_name, e.organization, e.department,
               COUNT(*) as meals,
               COUNT(DISTINCT DATE(ml.scanned_at)) as days
        FROM meal_logs ml
        JOIN employees e ON ml.employee_id = e.id
        WHERE DATE(ml.scanned_at) BETWEEN :s AND :e
          AND ml.access_granted = 1
        GROUP BY e.id, e.full_name, e.organization, e.department
        ORDER BY e.organization, e.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute([':s' => $start_date, ':e' => $end_date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

$widths = [180, 200, 170, 90, 90];
foreach ($widths as $w) {
    echo '<Column ss:Width="' . $w . '"/>' . "\n";
}

// Title row
$title = 'Сводный отчёт по сотрудникам: ' . $start_date . ' — ' . $end_date;
echo '<Row ss:Height="28">' . "\n";
echo '<Cell ss:StyleID="s_title" ss:MergeAcross="4"><Data ss:Type="String">' . ec($title) . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";

// Column headers
$headers = ['Организация', 'ФИО', 'Подразделение', 'Приёмов пищи', 'Дней в столовой'];
echo '<Row ss:Height="24">' . "\n";
foreach ($headers as $h) {
    echo '<Cell ss:StyleID="s_head"><Data ss:Type="String">' . ec($h) . '</Data></Cell>' . "\n";
}
echo '</Row>' . "\n";

$grandMeals = 0;
$grandDays  = 0;

foreach ($byOrg as $org => $emps) {
    $orgMeals = array_sum(array_column($emps, 'meals'));
    $orgDays  = array_sum(array_column($emps, 'days'));
    $grandMeals += $orgMeals;
    $grandDays  += $orgDays;

    // Org header row
    echo '<Row ss:Height="20">' . "\n";
    echo '<Cell ss:StyleID="s_org" ss:MergeAcross="4"><Data ss:Type="String">' . ec($org) . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";

    foreach ($emps as $emp) {
        echo '<Row ss:Height="18">' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String"></Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($emp['full_name'])  . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_data"><Data ss:Type="String">' . ec($emp['department']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_num"><Data ss:Type="Number">' . (int)$emp['meals']      . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="s_num"><Data ss:Type="Number">' . (int)$emp['days']       . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
    }

    // Org subtotal
    echo '<Row ss:Height="20">' . "\n";
    echo '<Cell ss:StyleID="s_sub" ss:MergeAcross="2"><Data ss:Type="String">Итого по ' . ec($org) . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s_subn"><Data ss:Type="Number">' . $orgMeals . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s_subn"><Data ss:Type="Number">' . $orgDays  . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";

    // Spacer
    echo '<Row ss:Height="6"><Cell><Data ss:Type="String"></Data></Cell></Row>' . "\n";
}

// Grand total
echo '<Row ss:Height="24">' . "\n";
echo '<Cell ss:StyleID="s_total" ss:MergeAcross="2"><Data ss:Type="String">ИТОГО</Data></Cell>' . "\n";
echo '<Cell ss:StyleID="s_total"><Data ss:Type="Number">' . $grandMeals . '</Data></Cell>' . "\n";
echo '<Cell ss:StyleID="s_total"><Data ss:Type="Number">' . $grandDays  . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";

echo '</Table>' . "\n";
echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
echo '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane>' . "\n";
echo '<ActivePane>2</ActivePane>' . "\n";
echo '</WorksheetOptions>' . "\n";
echo '</Worksheet>' . "\n";
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
