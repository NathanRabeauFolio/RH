<?php
declare(strict_types=1);

// Loaded only through helpers.php to avoid circular inclusion.

/**
 * Document-only layout functions inspired by the approved RH Figma V2.
 * Business rules, authorizations, data stores and monthly attribution remain unchanged.
 */

function rh2_p(string $value, int $size = 20, string $color = '#17263c', bool $bold = false, array $more = []): string
{
    return word_single_paragraph($value, array_replace([
        'font' => 'Inter', 'size' => $size, 'color' => $color, 'bold' => $bold,
        'space_after' => 40, 'line' => 290,
    ], $more));
}

function rh2_cell(int $width, array $paragraphs, string $fill = '#ffffff', array $padding = []): string
{
    $padding = array_replace(['top' => 150, 'bottom' => 160, 'left' => 150, 'right' => 150], $padding);
    $props = '<w:tcW w:w="' . $width . '" w:type="dxa"/>'
        . '<w:shd w:val="clear" w:color="auto" w:fill="' . word_rgb($fill) . '"/>'
        . '<w:vAlign w:val="center"/><w:tcMar>';
    foreach ($padding as $side => $amount) {
        if (!in_array($side, ['top', 'bottom', 'left', 'right'], true)) continue;
        $props .= '<w:' . $side . ' w:w="' . (int) $amount . '" w:type="dxa"/>';
    }
    $props .= '</w:tcMar>';
    return '<w:tc><w:tcPr>' . $props . '</w:tcPr>'
        . ($paragraphs === [] ? rh2_p('') : implode('', $paragraphs)) . '</w:tc>';
}

function rh2_table(array $columns, array $rows, array $options = []): string
{
    $total = array_sum($columns);
    $border = word_rgb((string) ($options['border'] ?? '#e1e6ed'));
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/>'
        . '<w:tblLayout w:type="fixed"/><w:tblBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/><w:right w:val="nil"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="' . $border . '"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="' . $border . '"/>'
        . '<w:insideV w:val="nil"/></w:tblBorders></w:tblPr><w:tblGrid>';
    foreach ($columns as $w) $xml .= '<w:gridCol w:w="' . (int) $w . '"/>';
    $xml .= '</w:tblGrid>';
    foreach ($rows as $i => $row) {
        if (count($row) !== count($columns)) throw new RuntimeException('Colonnes Word incohérentes.');
        $xml .= '<w:tr>';
        if ($i === 0 && !empty($options['repeat_header'])) {
            $xml .= '<w:trPr><w:tblHeader w:val="true"/></w:trPr>';
        }
        foreach ($row as $j => $c) {
            $xml .= rh2_cell(
                (int) $columns[$j],
                (array) ($c['paragraphs'] ?? []),
                (string) ($c['fill'] ?? ($i % 2 === 0 ? '#ffffff' : '#fafbfd')),
                (array) ($c['padding'] ?? [])
            );
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>';
}

function rh2_header(?string $logoRel): string
{
    if ($logoRel !== null) {
        $image = word_image_paragraph($logoRel, 'logo_mak.png', 480000, 480000,
            ['space_after' => 70, 'docpr_id' => 1]);
        $brand = word_text_run('   M.A.K. GALERIE', ['font' => 'Inter', 'size' => 20,
            'color' => '#1f3561', 'bold' => true]);
        $brand .= '<w:r><w:br/></w:r>';
        $brand .= word_text_run('   DOCUMENT INTERNE', ['font' => 'Inter', 'size' => 14,
            'color' => '#66758a']);
        $xml = str_replace('</w:p>', $brand . '</w:p>', $image);
    } else {
        $xml = rh2_p('M.A.K. GALERIE', 20, '#1f3561', true);
    }
    $xml .= rh2_p('', 10, '#1f3561', false, [
        'border_bottom' => true, 'border_bottom_color' => '#1f3561',
        'space_after' => 400,
    ]);
    return $xml;
}

function rh2_fr_date(string $raw): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) return $raw;
    $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int) $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y');
}

function rh2_head_cell(string $label): array
{
    return ['paragraphs' => [rh2_p($label, 16, '#1f3561', true, ['space_after' => 0])],
        'fill' => '#f0f4fa', 'padding' => ['top' => 135, 'bottom' => 135, 'left' => 145, 'right' => 90]];
}

function rh2_expense_body(array $spec, ?string $logoRel): string
{
    $table = (array) ($spec['table'] ?? []);
    $summary = (array) ($spec['summary'] ?? []);
    $val = static fn(int $i): string => (string) ($summary[$i][1] ?? '—');
    $body = rh2_header($logoRel);
    $body .= rh2_p('RÉCAPITULATIF INDIVIDUEL', 16, '#1f3561', true, ['space_after' => 100, 'keep_next' => true]);
    $body .= rh2_p('Notes de frais', 50, '#17263c', true, ['space_after' => 90, 'keep_next' => true]);
    $body .= rh2_p((string) ($spec['subtitle'] ?? ''), 22, '#66758a', false, ['space_after' => 400]);
    $summaryRow = [
        ['paragraphs' => [
            rh2_p('TOTAL TTC', 17, '#1f3561', true, ['space_after' => 100]),
            rh2_p($val(0), 55, '#17263c', true, ['space_after' => 30]),
        ], 'fill' => '#f0f4fa', 'padding' => ['top' => 200, 'bottom' => 170]],
        ['paragraphs' => [
            rh2_p('TOTAL HT', 16, '#66758a', true, ['space_after' => 110]),
            rh2_p($val(1), 33, '#17263c', true, ['space_after' => 30]),
        ], 'fill' => '#f0f4fa', 'padding' => ['top' => 200, 'bottom' => 170]],
        ['paragraphs' => [
            rh2_p('DÉPENSES', 16, '#66758a', true, ['space_after' => 110]),
            rh2_p(str_pad($val(2), 2, '0', STR_PAD_LEFT), 34, '#17263c', true, ['space_after' => 30]),
        ], 'fill' => '#f0f4fa', 'padding' => ['top' => 200, 'bottom' => 170]],
    ];
    $body .= rh2_table([4100, 3000, 2700], [$summaryRow], ['border' => '#f0f4fa']);
    $body .= rh2_p('', 12, '#ffffff', false, ['space_after' => 350]);
    $body .= rh2_p('DÉTAIL DES DÉPENSES', 16, '#1f3561', true, ['space_after' => 160, 'keep_next' => true]);
    $rows = [[
        rh2_head_cell('DÉPENSE'), rh2_head_cell('TTC'), rh2_head_cell('HT'),
        rh2_head_cell('RÈGLEMENT'), rh2_head_cell('PIÈCE'),
    ]];
    foreach (($table['rows'] ?? []) as $raw) {
        $date = rh2_fr_date((string) ($raw[0] ?? ''));
        $description = (string) ($raw[1] ?? '');
        $pay = match ((string) ($raw[4] ?? '')) {
            'CB' => 'Carte bancaire', 'Espèce' => 'Espèces', default => (string) ($raw[4] ?? '—'),
        };
        $rows[] = [
            ['paragraphs' => [
                rh2_p($description, 21, '#17263c', true, ['space_after' => 95, 'keep_next' => true]),
                rh2_p($date, 17, '#66758a', false, ['space_after' => 20]),
            ]],
            ['paragraphs' => [rh2_p((string) ($raw[2] ?? '—'), 22, '#17263c', true)]],
            ['paragraphs' => [rh2_p((string) ($raw[3] ?? '—'), 20)]],
            ['paragraphs' => [rh2_p($pay, 18)]],
            ['paragraphs' => [rh2_p((string) ($raw[5] ?? '—'), 17, '#1f3561', true)]],
        ];
    }
    if (count($rows) === 1) {
        $rows[] = [
            ['paragraphs' => [rh2_p('Aucune dépense enregistrée', 20, '#66758a')]],
            ...array_fill(0, 4, ['paragraphs' => [rh2_p('—', 20, '#66758a')]]),
        ];
    }
    $body .= rh2_table([3650, 1370, 1240, 1750, 1790], $rows, ['repeat_header' => true]);
    return $body;
}

function rh2_month_body(array $spec, ?string $logoRel): string
{
    $month = (string) ($spec['month'] ?? '');
    $table = (array) ($spec['table'] ?? []);
    $hasRest = !empty($table['has_rest_work']);
    $body = rh2_header($logoRel);
    $body .= rh2_p('SYNTHÈSE COLLABORATEURS', 16, '#1f3561', true, ['space_after' => 100, 'keep_next' => true]);
    $body .= rh2_p('Déclarations RH', 47, '#17263c', true, ['space_after' => 95, 'keep_next' => true]);
    $body .= rh2_p(month_label_fr($month) . '   ·   Édité le ' . date('d/m/Y'),
        21, '#66758a', false, ['space_after' => 320]);
    $headers = $hasRest
        ? ['COLLABORATEUR', 'TITRES-RESTAURANT', 'CONGÉS POSÉS', 'NAVIGO', 'REPOS TRAVAILLÉS · DATES ET MOTIFS']
        : ['COLLABORATEUR', 'TITRES-RESTAURANT', 'CONGÉS POSÉS', 'NAVIGO'];
    $cols = $hasRest ? [2400, 3800, 1600, 1100, 5900] : [2550, 4200, 1750, 1450];
    $rows = [array_map('rh2_head_cell', $headers)];
    $grouped = $hasRest ? rest_work_month_report($month) : [];
    $employees = employees();
    foreach ((array) ($table['rows'] ?? []) as $i => $row) {
        $employee = $employees[$i] ?? null;
        if (!$employee) continue;
        $remaining = (int) ($row[4] ?? 0);
        $eligible = (int) ($row[1] ?? 0);
        $withdrawn = (int) ($row[3] ?? 0);
        $ticketLabel = $remaining . ' titre' . ($remaining === 1 ? '' : 's');
        $ticketInfo = $eligible . ' jour' . ($eligible === 1 ? '' : 's') . ' ouvrant droit · '
            . $withdrawn . ' retrait' . ($withdrawn === 1 ? '' : 's');
        $cellRow = [
            ['paragraphs' => [rh2_p((string) $employee['name'], 21, '#17263c', true, ['space_after' => 25])]],
            ['paragraphs' => [
                rh2_p($ticketLabel, 27, '#17263c', true, ['space_after' => 50, 'keep_next' => true]),
                rh2_p($ticketInfo, 16, '#66758a', false, ['space_after' => 20]),
            ]],
            ['paragraphs' => [rh2_p((string) ($row[2] ?? '0') . ' j', 24, '#17263c', true)]],
            ['paragraphs' => [rh2_p((string) ($row[5] ?? 'Non'), 21)]],
        ];
        if ($hasRest) {
            $dates = $grouped[$employee['id']] ?? [];
            $notes = [];
            foreach ($dates as $record) {
                $date = (string) $record['date'];
                $label = rest_work_day($date) . ' ' . rh2_fr_date($date);
                $reason = trim((string) ($record['reason'] ?? ''));
                $notes[] = rh2_p($label, 19, '#17263c', true, [
                    'space_after' => $reason === '' ? 135 : 35,
                    'keep_next' => $reason !== '',
                ]);
                if ($reason !== '') $notes[] = rh2_p($reason, 17, '#66758a', false, ['space_after' => 150]);
            }
            if ($notes === []) $notes[] = rh2_p('—', 23, '#8896a6');
            $cellRow[] = ['paragraphs' => $notes];
        }
        $rows[] = $cellRow;
    }
    return $body . rh2_table($cols, $rows, ['repeat_header' => true]);
}

function rh2_docx_body(array $spec, ?string $logoRel): string
{
    return match ((string) $spec['layout_v2']) {
        'expenses' => rh2_expense_body($spec, $logoRel),
        'monthly' => rh2_month_body($spec, $logoRel),
        default => throw new InvalidArgumentException('Format documentaire inconnu.'),
    };
}

function rh2_appendix_header(array $appendix, int $index): string
{
    $text = rh2_p('PIÈCE ASSOCIÉE', 16, '#1f3561', true, ['space_after' => 100, 'keep_next' => true]);
    $text .= rh2_p('Justificatif n° ' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        40, '#17263c', true, ['space_after' => 110, 'keep_next' => true]);
    $text .= rh2_p((string) ($appendix['filename'] ?? ''), 19, '#66758a', false,
        ['space_after' => 230, 'keep_next' => true]);
    return $text;
}

function rh2_calendar_month(string $month, float $x, float $top, float $width, float $bottom): string
{
    $grid = month_calendar($month);
    $weekday = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
    $cmd = [];
    $headerH = 27.0;
    $daysH = 22.0;
    $cellW = $width / 7;
    $cellH = ($top - $bottom - $headerH - $daysH) / 6;
    $cmd[] = pdf_rect($x, $top - $headerH, $width, $headerH, '#1f3561', '#1f3561', 0);
    $cmd[] = pdf_text_line($x + 12, $top - 18, month_label_fr($month), 12, '#ffffff', 'F2');
    for ($col = 0; $col < 7; $col++) {
        $cx = $x + $col * $cellW;
        $cmd[] = pdf_rect($cx, $top - $headerH - $daysH, $cellW, $daysH, '#e2e7ed', '#eaf0f8', 0.4);
        $cmd[] = pdf_text_line($cx + 8, $top - $headerH - 15, $weekday[$col], 7, '#1f3561', 'F2');
    }
    foreach ($grid['weeks'] as $row => $week) {
        foreach ($week as $col => $day) {
            $cx = $x + $col * $cellW;
            $cy = $top - $headerH - $daysH - ($row + 1) * $cellH;
            $fill = !$day['in_month'] ? '#f4f6f9'
                : ($day['holiday'] ? '#faeeee' : ($col >= 5 ? '#fafbfd' : '#ffffff'));
            $cmd[] = pdf_rect($cx, $cy, $cellW, $cellH, '#e1e6ed', $fill, 0.4);
            $color = !$day['in_month'] ? '#a2afbf' : ($day['holiday'] ? '#9d4b4b' : '#17263c');
            $cmd[] = pdf_text_line($cx + 7, $cy + $cellH - 13, (string) $day['day'], 10, $color, 'F2');
            $entries = array_slice($day['entries'], 0, 2);
            $py = $cy + $cellH - 30;
            foreach ($entries as $entry) {
                $base = employee_accent((string) ($entry['employee_id'] ?? ''));
                $approved = (string) ($entry['status'] ?? 'pending') === 'approved';
                $chip = $approved ? $base : tint_hex($base, 0.58);
                $cmd[] = pdf_rect($cx + 3, $py - 2, $cellW - 6, 12, $chip, $chip, 0);
                $cmd[] = pdf_text_line($cx + 5, $py + 1, leave_chip_label($entry), 6,
                    $approved ? '#ffffff' : '#1f3561', 'F1');
                $py -= 13;
            }
            if (count($day['entries']) > count($entries)) {
                $cmd[] = pdf_text_line($cx + 5, $py, '+' . (count($day['entries']) - count($entries)), 6, '#64748b', 'F1');
            }
        }
    }
    return implode("\n", $cmd);
}

function rh2_calendar_pdf_binary(string $month): string
{
    [$m1, $m2] = calendar_pdf_months($month);
    $cmd = [pdf_rect(0, 0, 842, 595, '#ffffff', '#ffffff', 0)];
    $cmd[] = pdf_rect(34, 532, 40, 40, '#1f3561', '#1f3561', 0);
    $cmd[] = pdf_text_line(38, 551, 'M.A.K.', 8, '#ffffff', 'F2');
    $cmd[] = pdf_text_line(87, 557, 'M.A.K. GALERIE', 10, '#1f3561', 'F2');
    $cmd[] = pdf_text_line(87, 543, 'DOCUMENT INTERNE', 7, '#66758a', 'F1');
    $cmd[] = pdf_rect(34, 519, 774, 1.3, '#1f3561', '#1f3561', 0);
    $cmd[] = pdf_text_line(34, 478, 'Calendrier de l\'equipe', 19, '#17263c', 'F2');
    $cmd[] = pdf_text_line(34, 457, month_label_fr($m1) . ' - ' . month_label_fr($m2), 10, '#66758a');
    foreach (employees() as $index => $employee) {
        $column = $index % 2;
        $row = intdiv($index, 2);
        $lx = 618 + $column * 102;
        $ly = 485 - $row * 14;
        $accent = employee_accent($employee['id']);
        $cmd[] = pdf_rect($lx, $ly - 2, 9, 9, $accent, $accent, 0);
        $cmd[] = pdf_text_line($lx + 14, $ly, (string) $employee['first_name'], 8, '#17263c');
    }
    $cmd[] = rh2_calendar_month($m1, 34, 426, 378, 37);
    $cmd[] = rh2_calendar_month($m2, 430, 426, 378, 37);
    $stream = implode("\n", $cmd);
    $pdf = "%PDF-1.4\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1)
        . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

function rh2_response_pdf(string $filename, string $month): never
{
    $binary = rh2_calendar_pdf_binary($month);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . sanitize_filename($filename) . '"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}
