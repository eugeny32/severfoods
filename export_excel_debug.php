<?php
/**
 * Отладка Excel-экспорта — показывает сгенерированный XML в браузере
 * Использовать ТОЛЬКО для диагностики, потом удалить!
 * Доступ: /export_excel_debug.php?token=DEBUG2024
 */
if (($_GET['token'] ?? '') !== 'DEBUG2024') { http_response_code(403); die('Forbidden'); }

while (ob_get_level() > 0) ob_end_clean();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
while (ob_get_level() > 0) ob_end_clean();

// Берём 3 последних записи
$stmt = $pdo->query(
    "SELECT ml.scanned_at, e.full_name, e.organization, e.department,
            e.vjg_type, e.price, ml.meal_type, ml.operator_name, ml.meal_point_name
     FROM meal_logs ml JOIN employees e ON ml.employee_id = e.id
     WHERE ml.access_granted = 1
     ORDER BY ml.scanned_at DESC LIMIT 3"
);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

function xmlSafe(string $v): string {
    $v = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v);
    return str_replace(['&','<','>','"',"'"],['&amp;','&lt;','&gt;','&quot;','&apos;'], $v);
}

// Показываем RAW данные
echo '<pre style="font-size:12px">';
echo "=== RAW данные из БД ===\n";
foreach ($logs as $i => $log) {
    echo "Строка $i:\n";
    foreach ($log as $k => $v) {
        $safe = xmlSafe((string)($v ?? ''));
        echo "  $k: " . var_export($v, true) . " => xmlSafe: " . var_export($safe, true) . "\n";
    }
}

// Проверяем валидность XML
function colLetter(int $n): string {
    $s=''; while($n>0){$n--;$s=chr(65+($n%26)).$s;$n=(int)($n/26);} return $s;
}
function cellStr(string $ref, string $val, int $style=0): string {
    return '<c r="'.$ref.'" t="inlineStr" s="'.$style.'"><is><t>'.xmlSafe($val).'</t></is></c>';
}

$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($logs as $ri => $log) {
    $row = $ri + 1;
    $xml .= '<row r="'.$row.'">';
    $xml .= cellStr(colLetter(1).$row, date('d.m.Y', strtotime($log['scanned_at'])));
    $xml .= cellStr(colLetter(2).$row, (string)($log['full_name'] ?? ''));
    $xml .= cellStr(colLetter(3).$row, (string)($log['organization'] ?? ''));
    $xml .= '</row>'."\n";
}
$xml .= '</sheetData></worksheet>';

echo "\n=== XML (первые 3 строки) ===\n";
echo htmlspecialchars($xml);

echo "\n=== Валидация XML ===\n";
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$ok = $doc->loadXML($xml);
if ($ok) {
    echo "XML ВАЛИДНЫЙ ✓\n";
} else {
    $errs = libxml_get_errors();
    foreach ($errs as $e) {
        echo "ОШИБКА: {$e->message} (строка {$e->line}, столбец {$e->column})\n";
    }
}
echo '</pre>';
