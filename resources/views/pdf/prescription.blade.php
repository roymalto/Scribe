{{-- An issued prescription, A5, from its snapshot ($s). Dompdf: plain tables and inline-friendly CSS only. --}}
@php
    $c = $s['clinic'] ?? [];
    $d = $s['doctor'] ?? [];
    $pt = $s['patient'] ?? [];
    $issued = \Illuminate\Support\Carbon::parse($s['issued_at']);
    $sex = ['M' => 'Male', 'F' => 'Female'][$pt['sex'] ?? ''] ?? null;
    $hasDangerous = collect($s['items'] ?? [])->contains(fn ($i) => $i['is_dangerous_drug'] ?? false);
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $s['reference'] }}</title>
<style>
    @page { margin: 14mm 12mm 16mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; }
    .head td { vertical-align: middle; }
    .clinic { font-size: 14pt; font-weight: bold; }
    .muted { color: #555; font-size: 8.5pt; }
    hr { border: 0; border-top: 1.5px solid #0f766e; margin: 6px 0 8px; }
    .patient td { padding: 1px 0; vertical-align: top; }
    .label { color: #555; width: 18%; }
    .rx { font-size: 26pt; font-weight: bold; font-family: DejaVu Serif, serif; color: #0f766e; margin: 6px 0 2px; }
    .item { margin: 0 0 9px 0; }
    .item .name { font-weight: bold; font-size: 10.5pt; }
    .item .sig { margin-left: 16px; }
    .dd { color: #b91c1c; font-size: 8pt; font-weight: bold; }
    .notes { margin-top: 8px; }
    .doctor { margin-top: 26px; }
    .sign { border-top: 1px solid #111; width: 60%; margin-left: 40%; padding-top: 3px; text-align: center; }
    .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5pt; color: #555; }
</style>
</head>
<body>
<table class="head" width="100%">
    <tr>
        @if ($logo)
            <td width="22%"><img src="{{ $logo }}" style="max-height: 55px; max-width: 95%;"></td>
        @endif
        <td>
            <div class="clinic">{{ $c['name'] ?? '' }}</div>
            @if (! empty($c['header_note']))<div>{{ $c['header_note'] }}</div>@endif
            <div class="muted">
                {{ collect([$c['address'] ?? null, $c['city'] ?? null])->filter()->implode(', ') }}
                @if (! empty($c['phone'])) &middot; {{ $c['phone'] }}@endif
                @if (! empty($c['email'])) &middot; {{ $c['email'] }}@endif
            </div>
            @if (! empty($c['hours']))<div class="muted">Clinic hours: {{ $c['hours'] }}</div>@endif
        </td>
    </tr>
</table>
<hr>

<table class="patient" width="100%">
    <tr>
        <td class="label">Patient</td>
        <td><strong>{{ trim(($pt['first_name'] ?? '').' '.($pt['middle_name'] ?? '').' '.($pt['last_name'] ?? '')) }}</strong></td>
        <td class="label">Date</td>
        <td>{{ $issued->format('j M Y') }}</td>
    </tr>
    <tr>
        <td class="label">Age / sex</td>
        <td>{{ collect([isset($pt['age']) ? $pt['age'].' years' : null, $sex])->filter()->implode(' / ') ?: '-' }}</td>
        <td class="label">Patient no.</td>
        <td>{{ $pt['number'] ?? '' }}</td>
    </tr>
    @if (! empty($pt['address']))
        <tr><td class="label">Address</td><td colspan="3">{{ $pt['address'] }}</td></tr>
    @endif
</table>

<div class="rx">R<span style="font-size: 15pt;">x</span></div>
@foreach ($s['items'] ?? [] as $n => $i)
    <div class="item">
        <div class="name">
            {{ $n + 1 }}. {{ $i['generic_name'] }}@if (! empty($i['brand_name'])) ({{ $i['brand_name'] }})@endif
            {{ collect([$i['strength'] ?? null, $i['dosage_form'] ?? null])->filter()->implode(' ') }}
            @if (! empty($i['quantity'])) &nbsp;&mdash;&nbsp; {{ $i['quantity'] }}@endif
            @if ($i['is_dangerous_drug'] ?? false) <span class="dd">DANGEROUS DRUG</span>@endif
        </div>
        <div class="sig">Sig: {{ $i['sig'] }}@if (! empty($i['duration'])) &nbsp;({{ $i['duration'] }})@endif</div>
    </div>
@endforeach

@if (! empty($s['notes']))<div class="notes">{!! nl2br(e($s['notes'])) !!}</div>@endif
@if (! empty($s['follow_up']))<div class="notes"><strong>Follow-up:</strong> {{ $s['follow_up'] }}</div>@endif

<div class="doctor">
    <div class="sign">
        <strong>{{ $d['name'] ?? '' }}</strong>@if (! empty($d['specialty']))<br>{{ $d['specialty'] }}@endif
        <br><span class="muted">PRC Lic. No. {{ $d['prc_license_no'] ?? '' }}
            @if (! empty($d['prc_valid_until'])) (valid until {{ \Illuminate\Support\Carbon::parse($d['prc_valid_until'])->format('j M Y') }})@endif
        </span>
        @if (! empty($d['ptr_no']))<br><span class="muted">PTR No. {{ $d['ptr_no'] }}</span>@endif
        @if ($hasDangerous || ! empty($d['s2_no']))<br><span class="muted">S2 No. {{ $d['s2_no'] ?? '' }}</span>@endif
    </div>
</div>

<div class="foot">
    <table width="100%"><tr>
        <td>Ref. {{ $s['reference'] }} &middot; issued {{ $issued->format('j M Y H:i') }}@if (! empty($s['supersedes'])) &middot; replaces {{ $s['supersedes'] }}@endif</td>
        <td align="right">{{ $c['footer_note'] ?? '' }}</td>
    </tr></table>
</div>
</body>
</html>
