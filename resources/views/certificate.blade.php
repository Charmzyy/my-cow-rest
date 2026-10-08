@php
    // dompdf: A4, tables for layout (its flexbox/grid support is minimal), DejaVu Sans for "×", "’" etc.
    $a = $s['animal'];
    $o = $s['officer'];
    $age = $a['date_of_birth']
        ? \Carbon\Carbon::parse($a['date_of_birth'])->format('j M Y')
        : ($a['age_estimate_months'] !== null ? 'About ' . $a['age_estimate_months'] . ' months (estimated)' : '—');
    $purity = $c->purity === 'purebred' ? 'Purebred' : 'Crossbreed' . ($c->purity_note ? ' · ' . $c->purity_note : '');
    $place = collect([$a['village'], $a['ward'], $a['sub_county'], $a['county'] ? $a['county'] . ' County' : null])->filter()->implode(', ');
    $cell = 2.4;                               // pt per QR module
    $quiet = 4;                                // white margin round the code, in modules (QR spec)
    $qrSide = ($qr['size'] + 2 * $quiet) * $cell;
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $c->certificate_no }}</title>
<style>
    @page { margin: 22pt; }
    body { font-family: "DejaVu Sans", sans-serif; color: #1d2a20; font-size: 9.5pt; margin: 0; }
    /* A4 = 842pt tall − 44pt page margin = 798pt. Keep frame + borders + padding under that, or dompdf adds a blank page 2. */
    .frame { border: 3pt solid #1f5130; padding: 4pt; height: 770pt; }
    .inner { border: 0.75pt solid #b8923a; padding: 22pt 26pt; height: 722pt; position: relative; }
    .brand { font-size: 11pt; font-weight: bold; color: #1f5130; letter-spacing: 1pt; }
    .muted { color: #5b665e; }
    .eyebrow { font-size: 7.5pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #8a6d24; font-weight: bold; }
    h1 { font-size: 21pt; margin: 14pt 0 2pt; color: #1f5130; font-weight: bold; }
    .no { font-size: 11pt; font-weight: bold; letter-spacing: .5pt; }
    .breed { font-size: 26pt; font-weight: bold; margin: 4pt 0 0; color: #1d2a20; }
    .purity { font-size: 12pt; margin: 2pt 0 0; }
    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; }
    .facts td { padding: 4.5pt 0; border-bottom: 0.5pt solid #dfe3dc; }
    .facts td.k { width: 36%; color: #5b665e; }
    .facts td.v { font-weight: bold; }
    .photo { width: 100%; height: 150pt; border: 0.5pt solid #dfe3dc; }
    .qr { position: relative; background: #fff; }
    .qr div { position: absolute; background: #000; }
    .code { font-size: 13pt; font-weight: bold; letter-spacing: 2pt; }
    .sign { border-top: 0.75pt solid #1d2a20; padding-top: 4pt; width: 210pt; }
    .status-revoked { color: #a3261c; font-weight: bold; font-size: 12pt; border: 1.5pt solid #a3261c; padding: 4pt 8pt; }
    .foot { position: absolute; left: 26pt; right: 26pt; bottom: 18pt; font-size: 7pt; color: #5b665e; }
</style>
</head>
<body>
<div class="frame"><div class="inner">

    <table>
        <tr>
            <td>
                <div class="brand">MYCOW</div>
                <div class="muted">Cattle registration &amp; breed certification · Kenya</div>
            </td>
            <td style="text-align:right">
                <div class="eyebrow">Certificate no.</div>
                <div class="no">{{ $c->certificate_no }}</div>
                <div class="muted">Issued {{ $c->issued_at->format('j F Y') }}</div>
            </td>
        </tr>
    </table>

    <h1>Breed Certificate</h1>
    <div class="muted">This certifies that the animal described below was inspected by a registered officer and found to be:</div>

    @if ($c->status !== 'valid')
        <p style="margin:10pt 0 0"><span class="status-revoked">{{ strtoupper($c->status) }}: THIS CERTIFICATE IS NO LONGER VALID</span></p>
    @endif

    <div class="breed">{{ $c->breed }}</div>
    <div class="purity">{{ $purity }}</div>

    <table style="margin-top:18pt">
        <tr>
            <td style="width:58%; padding-right:18pt">
                <div class="eyebrow" style="margin-bottom:4pt">The animal</div>
                <table class="facts">
                    <tr><td class="k">Ear tag no.</td><td class="v">{{ $a['tag_number'] }}</td></tr>
                    @if ($a['national_id'])<tr><td class="k">National livestock ID</td><td class="v">{{ $a['national_id'] }}</td></tr>@endif
                    @if ($a['name'])<tr><td class="k">Name</td><td class="v">{{ $a['name'] }}</td></tr>@endif
                    <tr><td class="k">Sex</td><td class="v">{{ ucfirst($a['sex']) }}</td></tr>
                    <tr><td class="k">Born</td><td class="v">{{ $age }}</td></tr>
                    @if ($a['colour'])<tr><td class="k">Colour &amp; markings</td><td class="v">{{ $a['colour'] }}</td></tr>@endif
                    @if ($a['purpose'])<tr><td class="k">Purpose</td><td class="v">{{ ucfirst($a['purpose']) }}</td></tr>@endif
                    <tr><td class="k">Location</td><td class="v">{{ $place ?: '—' }}</td></tr>
                    <tr><td class="k">Owner</td><td class="v">{{ $s['owner']['fullname'] ?? '—' }}</td></tr>
                    <tr><td class="k">Inspection</td><td class="v">{{ $s['inspection_method'] === 'visit' ? 'Farm visit' : 'Photo review' }}</td></tr>
                </table>
            </td>
            <td style="width:42%">
                @if ($photo)
                    <img src="{{ $photo }}" class="photo" alt="">
                @endif
            </td>
        </tr>
    </table>

    <table style="margin-top:22pt">
        <tr>
            <td style="vertical-align:bottom">
                <div class="eyebrow" style="margin-bottom:26pt">Certified by</div>
                <div class="sign">
                    <strong>{{ $o['fullname'] }}</strong><br>
                    {{ $o['cadre'] }}<br>
                    Licence no. {{ $o['licence_number'] }}@if ($o['county']) · {{ $o['county'] }} County @endif
                </div>
            </td>
            <td style="width:{{ $qrSide + 120 }}pt; text-align:right; vertical-align:bottom">
                <table style="width:auto; margin-left:auto">
                    <tr>
                        <td style="text-align:right; vertical-align:bottom; padding-right:8pt">
                            <div class="eyebrow">Verify</div>
                            <div class="muted" style="font-size:8pt">Scan, or enter<br>this code at /verify</div>
                            <div class="code">{{ $c->verification_code }}</div>
                        </td>
                        <td>
                            <div class="qr" style="width:{{ $qrSide }}pt; height:{{ $qrSide }}pt">
                                @foreach ($qr['runs'] as [$x, $y, $len])
                                    <div style="left:{{ ($x + $quiet) * $cell }}pt; top:{{ ($y + $quiet) * $cell }}pt; width:{{ $len * $cell }}pt; height:{{ $cell }}pt"></div>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="foot">
        Check this certificate is genuine at {{ $c->verify_url }}. The online record is authoritative: a certificate that has been
        revoked or replaced shows as such there. Fingerprint {{ substr($c->content_hash, 0, 16) }}.
    </div>

</div></div>
</body>
</html>
