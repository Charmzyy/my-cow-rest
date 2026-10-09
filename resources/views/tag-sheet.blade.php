@php
    // dompdf: A4 portrait, 3 stickers per row, tables for layout. Each sticker: QR (drawn as boxes, no image
    // library needed), tag number, name, the code to type if the QR is dirty. Cut along the dotted lines.
    $cell = 2.0;                               // pt per QR module
    $quiet = 3;                                // white margin round the code, in modules
    $perRow = 3;
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Ear tags · {{ $farm->name }}</title>
<style>
    @page { margin: 24pt 20pt; }
    body { font-family: "DejaVu Sans", sans-serif; color: #1d2a20; font-size: 8pt; margin: 0; }
    .head { font-size: 10pt; font-weight: bold; color: #1f5130; margin-bottom: 8pt; }
    .head span { font-weight: normal; color: #5b665e; font-size: 8pt; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    tr { page-break-inside: avoid; }
    td { border: 0.75pt dashed #9aa39c; padding: 8pt 6pt; text-align: center; vertical-align: top; height: 150pt; }
    .qr { position: relative; background: #fff; margin: 0 auto; }
    .qr div { position: absolute; background: #000; }
    .tag { font-size: 13pt; font-weight: bold; margin-top: 5pt; letter-spacing: .5pt; }
    .name { font-size: 9pt; margin-top: 1pt; }
    .code { font-size: 7.5pt; color: #5b665e; margin-top: 2pt; letter-spacing: 1pt; }
</style>
</head>
<body>
<div class="head">MyCow ear tags · {{ $farm->name }} <span>· Scan with the MyCow app or the phone camera to open the cow.</span></div>
<table>
    @foreach ($tags->chunk($perRow) as $row)
        <tr>
            @foreach ($row as $t)
                @php $side = ($t['qr']['size'] + 2 * $quiet) * $cell; @endphp
                <td>
                    <div class="qr" style="width:{{ $side }}pt; height:{{ $side }}pt">
                        @foreach ($t['qr']['runs'] as [$x, $y, $len])
                            <div style="left:{{ ($x + $quiet) * $cell }}pt; top:{{ ($y + $quiet) * $cell }}pt; width:{{ $len * $cell }}pt; height:{{ $cell }}pt"></div>
                        @endforeach
                    </div>
                    <div class="tag">{{ $t['tag_number'] }}</div>
                    @if ($t['name'])<div class="name">{{ $t['name'] }}</div>@endif
                    <div class="code">{{ $t['code'] }}</div>
                </td>
            @endforeach
            @for ($i = $row->count(); $i < $perRow; $i++)
                <td style="border:0"></td>
            @endfor
        </tr>
    @endforeach
</table>
</body>
</html>
