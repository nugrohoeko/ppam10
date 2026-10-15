<!doctype html>
<html lang="id"><head><meta charset="utf-8"><style>
@page { margin: 38px 24px 42px; } body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#142b24} h1{font-size:17px;margin:0 0 3px} h2{font-size:13px;margin:8px 0} .meta{font-size:8px;color:#52675f;margin-bottom:10px} table{border-collapse:collapse;width:100%} thead{display:table-header-group} th{background:#145a48;color:#fff;text-align:left} th,td{padding:5px 4px;border:1px solid #dce5df;overflow-wrap:anywhere} tr{page-break-inside:avoid}.numeric{text-align:right}.subtotal{background:#e9f2ec;font-weight:bold}.total{background:#d2e6d8;font-weight:bold} footer{position:fixed;bottom:-24px;color:#65756b}
</style></head><body>
<h1>{{ $report['identity']['name'] ?? 'PAM RW.10' }}</h1>
<div>{{ $report['identity']['address'] ?? '' }} {{ $report['identity']['contact'] ?? '' }}</div>
<h2>{{ $report['title'] }}</h2>
<div class="meta">{{ app(\App\Services\ExportService::class)->filterText($report['filters'] ?? []) }}<br>Dicetak {{ now()->format('d/m/Y H:i') }} WIB · {{ count($report['rows']) }} baris</div>
<table><thead><tr>@foreach($report['columns'] as $column)<th>{{ $column['label'] }}</th>@endforeach</tr></thead><tbody>
@forelse($report['rows'] as $row)<tr>@foreach($report['columns'] as $column)<td class="{{ in_array($column['type'], ['money','decimal','number','balance','reading','age']) ? 'numeric' : '' }}">@if(in_array($column['type'], ['money','decimal','number','balance','reading','age']) && is_numeric($row[$column['key']] ?? null)){{ number_format($row[$column['key']], in_array($column['type'],['decimal','reading']) ? 3 : 0, ',', '.') }}@else{{ $row[$column['key']] ?? '' }}@endif</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['columns']) }}">Tidak ada data sesuai filter.</td></tr>@endforelse
@foreach($report['rtTotals'] ?? [] as $rt => $totals)<tr class="subtotal">@foreach($report['columns'] as $column)<td>@if($loop->first)Subtotal {{ $rt }}@elseif(isset($totals[$column['key']])){{ number_format($totals[$column['key']], $column['type'] === 'decimal' ? 3 : 0, ',', '.') }}@endif</td>@endforeach</tr>@endforeach
<tr class="total">@foreach($report['columns'] as $column)<td>@if($loop->first)TOTAL @elseif(isset($report['totals'][$column['key']])){{ number_format($report['totals'][$column['key']], $column['type'] === 'decimal' ? 3 : 0, ',', '.') }}@endif</td>@endforeach</tr>
</tbody></table><footer>Laporan berdasarkan data transaksi tersimpan. Nilai tagihan dan penerimaan kas disajikan terpisah.</footer>
</body></html>
