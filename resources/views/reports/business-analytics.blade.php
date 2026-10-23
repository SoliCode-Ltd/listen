<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $product }} · Business Analytics Report</title>
<style>
  @page { margin: 20mm 16mm 20mm 16mm; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #1c1d2b; font-size: 9pt; line-height: 1.45; margin: 0; }
  h1, h2, h3, h4 { margin: 0; font-weight: bold; color: #11121c; }
  .page { page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .muted { color: #6b6e80; }
  .brand { color: {{ $brand }}; }
  .num { text-align: right; white-space: nowrap; }
  .small { font-size: 7.6pt; }

  /* Cover */
  .cover { margin: -20mm -16mm; height: 297mm; background: #0b0b0f; color: #fff; position: relative; }
  .cover-inner { padding: 30mm 22mm 0 22mm; }
  .cover-logo { height: 15mm; }
  .cover-kicker { margin-top: 46mm; font-size: 9pt; letter-spacing: 2.5pt; text-transform: uppercase; color: #a596ff; }
  .cover-title { margin-top: 5mm; font-size: 30pt; line-height: 1.12; color: #fff; }
  .cover-sub { margin-top: 6mm; font-size: 12pt; color: #c9cbe0; }
  .cover-rule { margin-top: 12mm; width: 26mm; height: 1.4mm; background: {{ $brand }}; }
  .cover-meta { margin-top: 12mm; width: 100%; border-collapse: collapse; }
  .cover-meta td { padding: 3mm 6mm 3mm 0; vertical-align: top; }
  .cover-meta .k { font-size: 7.4pt; letter-spacing: 1pt; text-transform: uppercase; color: #8f92ad; }
  .cover-meta .v { font-size: 11pt; color: #fff; margin-top: 1mm; }
  .cover-foot { position: absolute; left: 22mm; right: 22mm; bottom: 18mm; font-size: 7.6pt; color: #8f92ad; border-top: 0.3mm solid #2a2c40; padding-top: 4mm; }

  /* Section heading */
  .eyebrow { font-size: 7.4pt; letter-spacing: 1.6pt; text-transform: uppercase; color: {{ $brand }}; font-weight: bold; }
  .h1 { font-size: 18pt; margin-top: 1.5mm; font-weight: bold; color: #11121c; }
  .lead { margin-top: 2mm; color: #4b4e63; font-size: 9.4pt; }
  .section { margin-top: 7mm; }
  .h2 { font-size: 10.5pt; margin-bottom: 2.5mm; font-weight: bold; color: #11121c; }
  .rule { height: 0.3mm; background: #e6e7ef; margin: 6mm 0 0 0; }

  /* KPI tiles */
  .kpis { width: 100%; border-collapse: separate; border-spacing: 2.4mm; margin: 4mm -2.4mm 0 -2.4mm; }
  .kpi { background: #f6f5ff; border: 0.3mm solid #e4e0ff; border-radius: 2.4mm; padding: 3.6mm 3.6mm 3.2mm 3.6mm; vertical-align: top; width: 25%; }
  .kpi .k { font-size: 7.4pt; color: #6b6e80; white-space: nowrap; }
  .kpi .v { font-size: 16pt; font-weight: bold; color: #11121c; margin-top: 1.2mm; }
  .kpi .n { font-size: 7.2pt; color: #4b4e63; margin-top: 1mm; }

  /* Highlights */
  .highlights { margin: 0; padding: 0; list-style: none; }
  .highlights li { padding: 2.2mm 0 2.2mm 6mm; line-height: 1.3; border-bottom: 0.25mm solid #eceef4; position: relative; font-size: 9.2pt; }
  .highlights li:last-child { border-bottom: none; }
  .dot { position: absolute; left: 0; top: 3.8mm; width: 2.2mm; height: 2.2mm; border-radius: 1.1mm; background: {{ $brand }}; }

  .about { background: #0b0b0f; color: #d7d8e8; border-radius: 2.4mm; padding: 5mm 6mm; font-size: 8.8pt; }
  .about b { color: #fff; }

  /* Tables */
  table.data { width: 100%; border-collapse: collapse; font-size: 8.2pt; }
  table.data th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b6e80; font-weight: bold; padding: 2mm 2mm; border-bottom: 0.4mm solid #1c1d2b; }
  table.data th.num { text-align: right; }
  table.data td { padding: 1.8mm 2mm; border-bottom: 0.25mm solid #eceef4; }
  table.data tr.total td { font-weight: bold; border-top: 0.4mm solid #1c1d2b; border-bottom: none; }
  table.data tr.partial td { color: #8a8da0; }

  /* Bar charts */
  .chart { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .chart td { vertical-align: bottom; text-align: center; padding: 0 0.8mm; }
  .chart .val { font-size: 6.4pt; color: #4b4e63; padding-bottom: 0.8mm; }
  .chart .bar { margin: 0 auto; width: 70%; border-radius: 0.8mm 0.8mm 0 0; background: {{ $brand }}; }
  .chart .bar.partial { background: #c9c0ff; }
  .chart .lbl { font-size: 6.6pt; color: #6b6e80; padding-top: 1.2mm; border-top: 0.3mm solid #cfd1dc; }
  .chart-box { border: 0.3mm solid #eceef4; border-radius: 2.4mm; padding: 4mm 4mm 3mm 4mm; }
  .chart-title { font-size: 9pt; font-weight: bold; }
  .chart-note { font-size: 7.2pt; color: #6b6e80; margin: 0.6mm 0 3mm 0; }

  /* Horizontal share bars */
  .share { width: 100%; border-collapse: collapse; font-size: 8.2pt; }
  .share td { padding: 1.1mm 0; vertical-align: middle; }
  .track { background: #efeff6; height: 2.6mm; border-radius: 1.3mm; }
  .fill { background: {{ $brand }}; height: 2.6mm; border-radius: 1.3mm; }

  .two { width: 100%; border-collapse: collapse; }
  .three { width: 100%; border-collapse: collapse; }
  .three > tbody > tr > td { width: 33.3%; vertical-align: top; padding-right: 5mm; }
  .three > tbody > tr > td:last-child { padding-right: 0; }
  .two > tbody > tr > td { width: 50%; vertical-align: top; }
  .two > tbody > tr > td.l { padding-right: 4mm; }
  .two > tbody > tr > td.r { padding-left: 4mm; }

  .stat-row { width: 100%; border-collapse: collapse; }
  .stat-row td { padding: 1.6mm 0; border-bottom: 0.25mm solid #eceef4; font-size: 8.6pt; }
  .stat-row td.num { font-weight: bold; }

  .callout { border-left: 1mm solid {{ $brand }}; background: #f6f5ff; padding: 3mm 4mm; font-size: 8.4pt; color: #33354a; }
  .defs dt { font-weight: bold; font-size: 8.2pt; margin-top: 2mm; }
  .defs dd { margin: 0.4mm 0 0 0; font-size: 8pt; color: #4b4e63; line-height: 1.35; }
</style>
</head>
<body>

{{-- ============================== Cover ============================== --}}
<div class="page">
  <div class="cover">
    <div class="cover-inner">
      @if ($logo)<img class="cover-logo" src="{{ $logo }}" alt="{{ $product }}">@endif
      <div class="cover-kicker">Business analytics report</div>
      <div class="cover-title">{{ $product }}<br>Performance &amp; Growth</div>
      <div class="cover-sub">Arabic-first AI voice platform: text-to-speech, voice cloning and a browser reader, with full English support.</div>
      <div class="cover-rule"></div>
      <table class="cover-meta">
        <tr>
          <td><div class="k">Reporting period</div><div class="v">{{ $periodLabel }}</div></td>
          <td><div class="k">Prepared</div><div class="v">{{ $generatedAt->format('j F Y') }}</div></td>
        </tr>
        <tr>
          <td><div class="k">Company</div><div class="v">{{ $company }} (United Kingdom)</div></td>
          <td>@if ($website)<div class="k">Website</div><div class="v">{{ $website }}</div>@endif</td>
        </tr>
      </table>
    </div>
    <div class="cover-foot">
      Confidential. Prepared by {{ $company }} for the intended recipient only. All figures are aggregate platform data
      generated directly from the production system on {{ $generatedAt->format('j F Y, H:i') }} UTC.
    </div>
  </div>
</div>

{{-- ========================= Executive summary ======================= --}}
<div class="page">
  <div class="eyebrow">01 · Executive summary</div>
  <div class="h1">The business at a glance</div>
  <div class="lead">Headline figures for {{ $product }}, measured over the last 30 days unless stated otherwise. Internal and administrator accounts are excluded throughout.</div>

  <table class="kpis">
    @foreach (array_chunk($kpis, 4) as $row)
      <tr>
        @foreach ($row as $kpi)
          <td class="kpi">
            <div class="k">{{ $kpi['label'] }}</div>
            <div class="v">{{ $kpi['value'] }}</div>
            <div class="n">{{ $kpi['note'] }}</div>
          </td>
        @endforeach
      </tr>
    @endforeach
  </table>

  @if (count($highlights))
    <div class="section">
      <div class="h2">Key highlights</div>
      <ul class="highlights">
        @foreach ($highlights as $line)
          <li><span class="dot"></span>{{ $line }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="section about">
    <b>About {{ $product }}.</b> {{ $product }} turns written text into natural speech for creators, marketers, educators and businesses
    across the Arabic-speaking world, with English alongside. Customers choose from Standard and premium HD voices, control
    style, emotion and Arabic dialect (Saudi, Egyptian, Gulf, Levantine and more), clone their own voice, and listen to any web page
    through the {{ $product }} browser extension. The product is self-serve, with free and paid subscription plans.
  </div>
</div>

{{-- =============================== Growth ============================= --}}
<div class="page">
  <div class="eyebrow">02 · Growth</div>
  <div class="h1">Member growth and activity</div>
  <div class="lead">New sign-ups and members who created audio each month. The lighter bar is the current month to date.</div>

  <table class="two section">
    <tr>
      @foreach ([['New members per month', 'Accounts created', $charts['signups']], ['Active members per month', 'Members who created at least one audio file', $charts['active']]] as $i => [$title, $note, $chart])
        <td class="{{ $i === 0 ? 'l' : 'r' }}">
          <div class="chart-box">
            <div class="chart-title">{{ $title }}</div>
            <div class="chart-note">{{ $note }}</div>
            @include('reports.partials.bar-chart', ['chart' => $chart])
          </div>
        </td>
      @endforeach
    </tr>
  </table>

  <div class="section">
    <div class="h2">Monthly detail</div>
    <table class="data">
      <thead>
        <tr>
          <th>Month</th>
          <th class="num">New members</th>
          <th class="num">Active members</th>
          <th class="num">Generations</th>
          <th class="num">Audio minutes</th>
          <th class="num">Demo plays</th>
          <th class="num">New paid</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($monthly as $row)
          <tr class="{{ $row['partial'] ? 'partial' : '' }}">
            <td>{{ $row['label'] }}{{ $row['partial'] ? ' (to date)' : '' }}</td>
            <td class="num">{{ $r->num($row['signups']) }}</td>
            <td class="num">{{ $r->num($row['active_members']) }}</td>
            <td class="num">{{ $r->num($row['generations']) }}</td>
            <td class="num">{{ $r->num($row['audio_minutes'], 1) }}</td>
            <td class="num">{{ $r->num($row['demo_generations']) }}</td>
            <td class="num">{{ $r->num($row['paid_starts']) }}</td>
          </tr>
        @endforeach
        <tr class="total">
          <td>Total</td>
          <td class="num">{{ $r->num($monthlyTotals['signups']) }}</td>
          <td class="num">—</td>
          <td class="num">{{ $r->num($monthlyTotals['generations']) }}</td>
          <td class="num">{{ $r->num($monthlyTotals['audio_minutes'], 1) }}</td>
          <td class="num">{{ $r->num($monthlyTotals['demo_generations']) }}</td>
          <td class="num">—</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

{{-- ============================== Audience ============================ --}}
<div class="page">
  <div class="eyebrow">03 · Audience</div>
  <div class="h1">Who our members are</div>
  <div class="lead">Where members are based, how they sign up and what they ask for. Country comes from the network location of each member's sign-in; only the country is stored.</div>

  @if ($audience['has_countries'])
    <table class="kpis">
      <tr>
        <td class="kpi"><div class="k">Countries reached</div><div class="v">{{ $r->num($audience['country_count']) }}</div><div class="n">With at least one member</div></td>
        <td class="kpi"><div class="k">Arab world</div><div class="v">{{ $r->pct($audience['arab_share'], 0) }}</div><div class="n">Of members with a known country</div></td>
        <td class="kpi"><div class="k">Largest market</div><div class="v" style="font-size: 12pt; margin-top: 2.6mm">{{ $audience['top_country'] }}</div><div class="n">{{ $r->pct($audience['top_country_share'], 0) }} of members</div></td>
        <td class="kpi"><div class="k">Work email</div><div class="v">{{ $r->pct($audience['work_email_share'], 0) }}</div><div class="n">Sign-ups with a company or school address</div></td>
      </tr>
    </table>

    <table class="two section">
      <tr>
        <td class="l">
          <div class="h2">Top countries</div>
          <table class="data" style="font-size: 7.8pt">
            <thead>
              <tr>
                <th>Country</th>
                <th class="num">Members</th>
                <th class="num">Share</th>
                <th class="num">Active</th>
                <th class="num">Paying</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($audience['countries'] as $c)
                <tr>
                  <td style="white-space: nowrap">{{ $c['country'] }}</td>
                  <td class="num">{{ $r->num($c['members']) }}</td>
                  <td class="num">{{ $r->pct($c['share'], 0) }}</td>
                  <td class="num">{{ $r->num($c['active_30d']) }}</td>
                  <td class="num">{{ $r->num($c['paying']) }}</td>
                </tr>
              @endforeach
              @if ($audience['other'])
                <tr>
                  <td class="muted" style="white-space: nowrap">{{ $audience['other']['country'] }}</td>
                  <td class="num">{{ $r->num($audience['other']['members']) }}</td>
                  <td class="num">{{ $r->pct($audience['other']['share'], 0) }}</td>
                  <td class="num">{{ $r->num($audience['other']['active_30d']) }}</td>
                  <td class="num">{{ $r->num($audience['other']['paying']) }}</td>
                </tr>
              @endif
            </tbody>
          </table>
          <div class="small muted" style="margin-top: 2mm">
            Active: created audio in the last 30 days. Country known for {{ $r->num($audience['known']) }} of {{ $r->num($audience['members']) }} members ({{ $r->pct($audience['coverage'], 0) }}){{ $audience['unknown'] > 0 ? '; the rest are added at their next sign-in' : '' }}.
          </div>
        </td>
        <td class="r">
          <div class="h2">Members by region</div>
          @include('reports.partials.share-bars', ['rows' => $audience['regions']])
        </td>
      </tr>
    </table>

    <table class="three section">
      <tr>
        <td><div class="h2">How members sign up</div>@include('reports.partials.share-bars', ['rows' => $audience['signup'], 'compact' => true])</td>
        <td><div class="h2">Email type</div>@include('reports.partials.share-bars', ['rows' => $audience['emails'], 'compact' => true])</td>
        <td><div class="h2">Audio language, 30 days</div>@include('reports.partials.share-bars', ['rows' => $languages, 'compact' => true])</td>
      </tr>
    </table>

  @else
    <div class="section callout">
      Member countries are being collected: each member's country is saved automatically the next time they sign in.
      The country breakdown will appear here as soon as it is available.
    </div>
    <table class="two section">
      <tr>
        <td class="l">
          <div class="h2">How members sign up</div>
          @include('reports.partials.share-bars', ['rows' => $audience['signup']])
        </td>
        <td class="r">
          <div class="h2">Email type</div>
          @include('reports.partials.share-bars', ['rows' => $audience['emails']])
        </td>
      </tr>
    </table>
    <table class="two section">
      <tr>
        <td class="l">
          <div class="h2">Language of the audio, 30 days</div>
          @include('reports.partials.share-bars', ['rows' => $languages])
        </td>
        <td class="r"></td>
      </tr>
    </table>
  @endif
</div>

{{-- ======================= Engagement & retention ===================== --}}
<div class="page">
  <div class="eyebrow">04 · Engagement</div>
  <div class="h1">Usage, engagement and retention</div>
  <div class="lead">How much audio members create, how usage is spread across the base, and how sign-up cohorts keep using the product.</div>

  <table class="two section">
    <tr>
      @foreach ([['Generations per month', 'Audio files created by members', $charts['generations']], ['Audio minutes per month', 'Minutes of speech produced', $charts['minutes']]] as $i => [$title, $note, $chart])
        <td class="{{ $i === 0 ? 'l' : 'r' }}">
          <div class="chart-box">
            <div class="chart-title">{{ $title }}</div>
            <div class="chart-note">{{ $note }}</div>
            @include('reports.partials.bar-chart', ['chart' => $chart])
          </div>
        </td>
      @endforeach
    </tr>
  </table>

  <table class="two section">
    <tr>
      <td class="l">
        <div class="h2">Engagement</div>
        <table class="stat-row">
          <tr><td>Members who have created audio</td><td class="num">{{ $r->num($engagement['activated'] ?? 0) }}</td></tr>
          <tr><td>Activation rate</td><td class="num">{{ $r->pct($engagement['activation_rate'] ?? 0) }}</td></tr>
          <tr><td>Active members, last 7 days</td><td class="num">{{ $r->num($engagement['active_7d'] ?? 0) }}</td></tr>
          <tr><td>Active members, last 30 days</td><td class="num">{{ $r->num($engagement['active_30d'] ?? 0) }}</td></tr>
          <tr><td>Generations per active member, 30 days</td><td class="num">{{ $r->num($engagement['generations_per_active_member_30d'] ?? 0, 1) }}</td></tr>
          <tr><td>Share of usage from the top 10% of members</td><td class="num">{{ $r->pct($engagement['top_10pct_share'] ?? 0) }}</td></tr>
        </table>
      </td>
      <td class="r">
        <div class="h2">Generations per member (lifetime)</div>
        @include('reports.partials.share-bars', ['rows' => array_map(fn ($row) => ['label' => $row['label'].' generation'.($row['label'] === '1' ? '' : 's')] + $row, $distribution)])
      </td>
    </tr>
  </table>

  @if (count($cohorts))
    <div class="section">
      <div class="h2">Sign-up cohorts</div>
      <table class="data">
        <thead>
          <tr>
            <th>Joined</th>
            <th class="num">Members</th>
            <th class="num">Created audio</th>
            <th class="num">Activation</th>
            <th class="num">Active month +1</th>
            <th class="num">Active month +2</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($cohorts as $c)
            <tr>
              <td>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $c['month'].'-01')->format('M Y') }}</td>
              <td class="num">{{ $r->num($c['signups']) }}</td>
              <td class="num">{{ $r->num($c['ever_generated']) }}</td>
              <td class="num">{{ $r->pct($c['activation_rate']) }}</td>
              <td class="num">{{ $c['retention_month_1'] === null ? '—' : $r->pct($c['retention_month_1']) }}</td>
              <td class="num">{{ $c['retention_month_2'] === null ? '—' : $r->pct($c['retention_month_2']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>

{{-- ============================ Product usage ========================= --}}
<div class="page">
  <div class="eyebrow">05 · Product</div>
  <div class="h1">What customers create</div>
  <div class="lead">The mix of voice quality, Arabic dialects and delivery styles in the last 30 days, and how the voice infrastructure performed.</div>

  <table class="two section">
    <tr>
      <td class="l">
        <div class="h2">Voice quality</div>
        @include('reports.partials.share-bars', ['rows' => $tiers])
        @if (count($audience['dialects']))
          <div class="h2" style="margin-top: 6mm">Arabic dialects requested <span class="muted small" style="font-weight: normal">(report period)</span></div>
          @include('reports.partials.share-bars', ['rows' => $audience['dialects']])
        @endif
      </td>
      <td class="r">
        @if (count($styles))
          <div class="h2">Delivery styles</div>
          @include('reports.partials.share-bars', ['rows' => $styles])
        @endif
        <div class="h2" style="margin-top: 6mm">Product facts</div>
        <table class="stat-row">
          <tr><td>Generations, 30 days</td><td class="num">{{ $r->num($generations['last_30d'] ?? 0) }}</td></tr>
          <tr><td>Characters converted, 30 days</td><td class="num">{{ $r->num($generations['billable_chars_30d'] ?? 0) }}</td></tr>
          <tr><td>Success rate, 30 days</td><td class="num">{{ $r->pct(1 - (float) ($generations['failure_rate_30d'] ?? 0)) }}</td></tr>
          <tr><td>Generations with translation</td><td class="num">{{ $r->pct($generations['translate_share_30d'] ?? 0) }}</td></tr>
          @isset($generations['cloned_voices'])
            <tr><td>Custom cloned voices created</td><td class="num">{{ $r->num($generations['cloned_voices']) }}</td></tr>
          @endisset
          <tr><td>Lifetime generations by members</td><td class="num">{{ $r->num($generations['total'] ?? 0) }}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  @if (count($providers))
    <div class="section">
      <div class="h2">Voice infrastructure, last 30 days</div>
      <table class="data">
        <thead>
          <tr>
            <th>Engine</th>
            <th class="num">Generations</th>
            <th class="num">Audio minutes</th>
            <th class="num">Avg. response</th>
            <th class="num">Success rate</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($providers as $p)
            <tr>
              <td>{{ $p['label'] }}</td>
              <td class="num">{{ $r->num($p['generations']) }}</td>
              <td class="num">{{ $r->num($p['audio_minutes'], 1) }}</td>
              <td class="num">{{ $p['avg_latency_s'] === null ? '—' : $r->num($p['avg_latency_s'], 1).' s' }}</td>
              <td class="num">{{ $r->pct(1 - (float) $p['failure_rate']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>

{{-- ========================= Revenue & economics ====================== --}}
<div class="page">
  <div class="eyebrow">06 · Revenue</div>
  <div class="h1">Revenue and unit economics</div>
  <div class="lead">Recurring revenue from card subscriptions {{ ($revenue['mrr_source'] ?? '') === 'stripe' ? 'at what they actually charge, after discounts' : 'estimated at list price' }}, plan mix, and what the voice infrastructure costs to serve.</div>

  <table class="kpis">
    <tr>
      <td class="kpi"><div class="k">Monthly revenue (MRR)</div><div class="v">{{ $r->money($mrr) }}</div><div class="n">{{ ($revenue['mrr_source'] ?? '') === 'stripe' ? 'Card subscriptions, after discounts' : 'Card subscriptions, list-price estimate' }}</div></td>
      <td class="kpi"><div class="k">Annual run rate</div><div class="v">{{ $r->money($mrr * 12) }}</div><div class="n">MRR × 12</div></td>
      <td class="kpi"><div class="k">Paying members</div><div class="v">{{ $r->num($revenue['members_on_paid_plans'] ?? 0) }}</div><div class="n">{{ $r->pct($revenue['paid_conversion'] ?? 0, 2) }} of all members</div></td>
      <td class="kpi"><div class="k">Revenue per subscriber</div><div class="v">{{ $r->money(($revenue['arpu_cents'] ?? 0) / 100) }}</div><div class="n">Per month</div></td>
    </tr>
  </table>

  @if (count($plans))
    <div class="section">
      <div class="h2">Plans</div>
      <table class="data">
        <thead>
          <tr>
            <th>Plan</th>
            <th class="num">Price / month</th>
            <th class="num">Members</th>
            <th class="num">Paying</th>
            <th class="num">Card, monthly</th>
            <th class="num">Card, yearly</th>
            <th class="num">MRR</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($plans as $plan)
            @continue(! $plan['active'] && ! $plan['members'] && ! $plan['mrr_usd'])
            <tr>
              <td>{{ $plan['plan'] }}</td>
              <td class="num">{{ $plan['price_monthly_usd'] === null ? '—' : ($plan['price_monthly_usd'] > 0 ? $r->money($plan['price_monthly_usd']) : 'Free') }}</td>
              <td class="num">{{ $r->num($plan['members']) }}</td>
              <td class="num">{{ $r->num($plan['paying'] ?? 0) }}</td>
              <td class="num">{{ $r->num($plan['stripe_monthly']) }}</td>
              <td class="num">{{ $r->num($plan['stripe_yearly']) }}</td>
              <td class="num">{{ $r->money($plan['mrr_usd']) }}</td>
            </tr>
          @endforeach
          <tr class="total">
            <td>Total</td><td></td>
            <td class="num">{{ $r->num(array_sum(array_column($plans, 'members'))) }}</td>
            <td class="num">{{ $r->num(array_sum(array_column($plans, 'paying'))) }}</td>
            <td class="num">{{ $r->num(array_sum(array_column($plans, 'stripe_monthly'))) }}</td>
            <td class="num">{{ $r->num(array_sum(array_column($plans, 'stripe_yearly'))) }}</td>
            <td class="num">{{ $r->money(array_sum(array_column($plans, 'mrr_usd'))) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  @endif

  <table class="two section">
    <tr>
      <td class="l">
        <div class="h2">Unit economics, last 30 days</div>
        <table class="stat-row">
          <tr><td>Voice API cost</td><td class="num">{{ $r->money($unit['api_cost_30d'] ?? 0, 2) }}</td></tr>
          <tr><td>Cost per 1,000 characters</td><td class="num">{{ $r->money($api['cost_per_1k_chars'] ?? 0, 4) }}</td></tr>
          <tr><td>Cost per audio minute</td><td class="num">{{ $r->money($api['cost_per_audio_minute'] ?? 0, 4) }}</td></tr>
          <tr><td>Cost per active member</td><td class="num">{{ $r->money($unit['api_cost_per_active_member'] ?? 0, 4) }}</td></tr>
          <tr><td>Gross margin after API cost</td><td class="num">{{ ($unit['gross_margin_rate'] ?? null) === null ? '—' : $r->pct($unit['gross_margin_rate']) }}</td></tr>
          <tr><td>Audio served from cache</td><td class="num">{{ $r->pct($api['cache_hit_rate'] ?? 0) }}</td></tr>
        </table>
      </td>
      <td class="r">
        @if (! empty($trials['total']))
          <div class="h2">Free trials</div>
          <table class="stat-row">
            <tr><td>Trials started</td><td class="num">{{ $r->num($trials['total']) }}</td></tr>
            <tr><td>Converted to paid</td><td class="num">{{ $r->num($trials['paid'] ?? 0) }}</td></tr>
            <tr><td>Currently in trial</td><td class="num">{{ $r->num($trials['open'] ?? 0) }}</td></tr>
            <tr><td>Conversion of finished trials</td><td class="num">{{ $r->pct($trials['conversion'] ?? 0) }}</td></tr>
          </table>
        @endif
        @if (! empty($manual['approved_by_currency']))
          <div class="h2" style="margin-top: 5mm">Local payments</div>
          <table class="stat-row">
            @foreach ($manual['approved_by_currency'] as $currency => $amount)
              <tr><td>Approved local payments ({{ $currency }})</td><td class="num">{{ number_format($amount, 2) }} {{ $currency }}</td></tr>
            @endforeach
          </table>
        @endif
        @if (! empty($channels['demo_generations_total']))
          <div class="h2" style="margin-top: 5mm">Acquisition</div>
          <table class="stat-row">
            <tr><td>Free demo generations, lifetime</td><td class="num">{{ $r->num($channels['demo_generations_total']) }}</td></tr>
            <tr><td>Free demo generations, 30 days</td><td class="num">{{ $r->num($channels['demo_generations_30d'] ?? 0) }}</td></tr>
            <tr><td>Unique demo visitors</td><td class="num">{{ $r->num($channels['demo_visitors'] ?? 0) }}</td></tr>
            @isset($channels['demo_paid_rate'])
              <tr><td>Demo users who went on to pay</td><td class="num">{{ $r->pct($channels['demo_paid_rate']) }}</td></tr>
            @endisset
            @isset($channels['referral_visits'])
              <tr><td>Referral link visits</td><td class="num">{{ $r->num($channels['referral_visits']) }}</td></tr>
            @endisset
          </table>
        @endif
      </td>
    </tr>
  </table>
</div>

{{-- ============================ Methodology =========================== --}}
<div class="page">
  <div class="eyebrow">07 · Notes</div>
  <div class="h1">Definitions and methodology</div>

  <div class="section callout">
    All figures are produced automatically from the {{ $product }} production database at the time stated on the cover.
    They are aggregate counts: no personal data is included. Administrator and internal test accounts are excluded from every member,
    usage and revenue figure.
  </div>

  <dl class="defs section">
    <dt>Member</dt><dd>A registered, non-administrator account.</dd>
    <dt>Active member</dt><dd>A member who created at least one audio file in the period.</dd>
    <dt>Activation rate</dt><dd>Members who have ever created audio, divided by all members.</dd>
    <dt>Generation</dt><dd>One text-to-speech request by a member that produced, or tried to produce, an audio file. Free web-demo plays are reported separately.</dd>
    <dt>Monthly recurring revenue (MRR)</dt><dd>What the active card subscriptions charge at their next bill, after discounts and at the price each member kept, read from the payment processor; yearly plans count at one twelfth. Subscriptions set to cancel count until their paid time ends; free trials and overdue renewals do not count. Local (manual) payments are listed separately and are not included in MRR.</dd>
    <dt>Paying member</dt><dd>A member whose paid plan is active now and was paid for, by card or by local payment. Members in a free trial and plans given without a payment are not counted.</dd>
    <dt>Annual run rate</dt><dd>MRR multiplied by twelve.</dd>
    <dt>Voice API cost</dt><dd>What the speech providers charge for the audio produced, using the cost recorded per request or, where none was recorded, provider list prices.</dd>
    <dt>Gross margin after API cost</dt><dd>MRR minus voice API cost for the last 30 days, divided by MRR. Hosting and other costs are not included.</dd>
    <dt>Cohort retention</dt><dd>Of the members who joined in a month, the share who created audio in the following month (+1) and the month after (+2).</dd>
    <dt>Country and region</dt><dd>The country of the network a member signed in from, saved once per member; no address, city or IP address is kept. Regions group countries: Gulf (GCC), Egypt, Levant &amp; Iraq, North Africa, other Arab states, Europe, North America and the rest of the world.</dd>
    <dt>Work email</dt><dd>A sign-up email on a company, school or other organisation domain rather than a personal webmail service.</dd>
    <dt>Current month</dt><dd>Shown "to date" and in a lighter colour, as it is not yet complete.</dd>
  </dl>

  <div class="rule"></div>
  <div class="small muted" style="margin-top: 3mm">
    {{ $company }} · {{ $product }}@if ($website) · {{ $website }}@endif @if ($contact) · {{ $contact }}@endif
  </div>
</div>

</body>
</html>
