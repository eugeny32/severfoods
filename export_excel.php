<?php
/**
 * Excel export via SpreadsheetML (XML) — максимально совместимый формат
 * Использует старый формат .xls (SpreadsheetML) который 100% читается Excel
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$start_date = preg_replace('/[^0-9\-]/', '', $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$end_date   = preg_replace('/[^0-9\-]/', '', $_GET['end_date']   ?? date('Y-m-d'));
$meal_type  = in_array($_GET['meal_type'] ?? '', ['breakfast','lunch','dinner','night'])
              ? $_GET['meal_type'] : 'all';
$point_id   = (isset($_GET['point_id']) && ctype_digit((string)($_GET['point_id'])))
              ? (int)$_GET['point_id'] : null;

$user_role    = $_SESSION['role']              ?? 'admin';
$is_super     = ($user_role === 'super_admin');
$assigned_pid = $_SESSION['assigned_point_id'] ?? null;
if (!$is_super && $assigned_pid) $point_id = $assigned_pid;

// Данные
$sql = "SELECT ml.scanned_at, e.full_name, e.organization, e.department,
               e.vjg_type, e.price, ml.meal_type,
               ml.operator_name, ml.meal_point_name
        FROM meal_logs ml
        JOIN employees e ON ml.employee_id = e.id
        WHERE DATE(ml.scanned_at) BETWEEN :s AND :e
          AND ml.access_granted = 1";
$params = [':s' => $start_date, ':e' => $end_date];
if ($meal_type !== 'all') { $sql .= " AND ml.meal_type = :mt";  $params[':mt']  = $meal_type; }
if ($point_id)            { $sql .= " AND ml.meal_point_id = :pid"; $params[':pid'] = $point_id; }
$sql .= " ORDER BY ml.scanned_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Безопасная строка для ячейки Excel XML:
 * - удаляем ВСЕ управляющие символы (включая \r\n\t внутри значений)
 * - экранируем XML
 */
function ec(mixed $v): string {
    $s = (string)($v ?? '');
    // Удаляем все управляющие символы 0x00-0x1F и 0x7F
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s);
    // Экранируем XML-спецсимволы
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$title = 'Отчет по питанию: ' . $start_date . ' - ' . $end_date;
if ($meal_type !== 'all') $title .= ' / ' . getMealTypeName($meal_type);

$headers = [
    'Дата', 'Время', 'ФИО', 'Организация', 'Отдел',
    'ВЖГ', 'Цена (руб)', 'Тип питания', 'Точка питания', 'Оператор'
];

// Строим содержимое файла
ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo '  xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
echo '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
echo '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo '  xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

// Стили
echo '<Styles>' . "\n";
echo '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/><Font ss:FontName="Calibri" ss:Size="11"/></Style>' . "\n";
echo '<Style ss:ID="s1"><Font ss:FontName="Calibri" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#003366" ss:Pattern="Solid"/><Alignment ss:Horizontal="Left" ss:Vertical="Center"/></Style>' . "\n";
echo '<Style ss:ID="s2"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#003366"/><Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#B0BEC5"/></Borders></Style>' . "\n";
echo '<Style ss:ID="s3"><Font ss:FontName="Calibri" ss:Size="11"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>' . "\n";
echo '<Style ss:ID="s4"><Font ss:FontName="Calibri" ss:Size="11"/><NumberFormat ss:Format="0.00"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>' . "\n";
echo '<Style ss:ID="s5"><Font ss:FontName="Calibri" ss:Size="11"/><Alignment ss:WrapText="1" ss:Vertical="Top"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>' . "\n";
echo '</Styles>' . "\n";

// Лист
echo '<Worksheet ss:Name="Питание">' . "\n";
echo '<Table>' . "\n";

// Ширины колонок
$widths = [80, 60, 200, 180, 130, 60, 80, 90, 170, 120];
foreach ($widths as $w) {
    echo '<Column ss:Width="' . $w . '"/>' . "\n";
}

// Строка 1: заголовок отчёта
echo '<Row ss:Height="28">' . "\n";
echo '<Cell ss:StyleID="s1" ss:MergeAcross="' . (count($headers) - 1) . '">';
echo '<Data ss:Type="String">' . ec($title) . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";

// Строка 2: шапка колонок
echo '<Row ss:Height="24">' . "\n";
foreach ($headers as $h) {
    echo '<Cell ss:StyleID="s2"><Data ss:Type="String">' . ec($h) . '</Data></Cell>' . "\n";
}
echo '</Row>' . "\n";

// Данные
foreach ($logs as $log) {
    $ts = strtotime($log['scanned_at'] ?? 'now');
    echo '<Row ss:Height="18">' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec(date('d.m.Y', $ts))                       . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec(date('H:i:s', $ts))                       . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s5"><Data ss:Type="String">' . ec($log['full_name']        ?? '')            . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s5"><Data ss:Type="String">' . ec($log['organization']     ?? '')            . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec($log['department']       ?? '')            . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec($log['vjg_type']         ?? '')            . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s4"><Data ss:Type="Number">' . (float)($log['price']        ?? 0)            . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec(getMealTypeName($log['meal_type'] ?? ''))  . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec($log['meal_point_name'] ?? '')             . '</Data></Cell>' . "\n";
    echo '<Cell ss:StyleID="s3"><Data ss:Type="String">' . ec($log['operator_name']   ?? '')             . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
}

echo '</Table>' . "\n";
echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
echo '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane>' . "\n";
echo '<ActivePane>2</ActivePane>' . "\n";
echo '<Print><ValidPrinterInfo/><HorizontalResolution>600</HorizontalResolution><VerticalResolution>600</VerticalResolution></Print>' . "\n";
echo '</WorksheetOptions>' . "\n";
echo '</Worksheet>' . "\n";
echo '</Workbook>';

$content = ob_get_clean();

// Сбрасываем ВСЕ буферы после сборки контента
while (ob_get_level() > 0) { ob_end_clean(); }

$fname = 'pitanie_' . $start_date . '_' . $end_date . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: max-age=0, no-store');
header('Pragma: public');
echo $content;
exit;
