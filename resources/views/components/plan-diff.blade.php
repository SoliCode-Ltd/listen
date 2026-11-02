{{-- What changes when a lower plan starts: the plan comparison's rows that differ, the
     member's plan beside the new one (App\Services\Billing\PlanChanges::differences). The
     billing page fills it from the order details; Manage Subscription draws it as booked. --}}
@props(['id' => null, 'title' => '', 'from' => '', 'to' => '', 'changes' => [], 'hidden' => false])
<div class="plan-diff" @if($id) id="{{ $id }}" @endif @if($hidden) hidden @endif>
  <p class="plan-diff-title" data-plan-diff-title>{{ $title }}</p>
  <table class="plan-diff-table">
    <thead>
      <tr>
        <td></td>
        <th scope="col" data-plan-diff-from>{{ $from }}</th>
        <th scope="col" data-plan-diff-to>{{ $to }}</th>
      </tr>
    </thead>
    <tbody data-plan-diff-rows>
      @foreach($changes as $row)
        <tr>
          <th scope="row">{{ $row['label'] }}</th>
          <td class="was">{{ $row['from'] }}</td>
          <td class="will">{{ $row['to'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@once
  <style>
    .plan-diff { margin-top: 1rem; }
    .plan-diff[hidden] { display: none; }
    .plan-diff-title { margin: 0 0 .45rem; font-size: .86rem; font-weight: 700; color: var(--text); }
    .plan-diff-table { width: 100%; border-collapse: collapse; font-size: .82rem; line-height: 1.45; }
    .plan-diff-table th, .plan-diff-table td {
      padding: .45rem .35rem;
      text-align: start;
      vertical-align: top;
      border-top: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
    }
    .plan-diff-table th:first-child, .plan-diff-table td:first-child { padding-inline-start: 0; }
    .plan-diff-table th:last-child, .plan-diff-table td:last-child { padding-inline-end: 0; }
    .plan-diff-table thead th, .plan-diff-table thead td {
      border-top: 0;
      padding-top: 0;
      font-size: .76rem;
      font-weight: 600;
      color: var(--muted);
    }
    .plan-diff-table tbody th { font-weight: 500; color: color-mix(in oklab, var(--text) 82%, var(--muted)); }
    .plan-diff-table td { white-space: nowrap; }
    .plan-diff-table .was { color: var(--muted); }
    .plan-diff-table .will { color: color-mix(in oklab, var(--brand-accent) 62%, var(--text)); font-weight: 600; }
    /* Narrow screens: the values may wrap rather than push the table wider than the page. */
    @media (max-width: 420px) { .plan-diff-table td { white-space: normal; } }
  </style>
@endonce
