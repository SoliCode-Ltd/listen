{{-- Horizontal share bars. $rows = [['label', 'value', 'share']]; $compact stacks the bar under the label. --}}
<table class="share">
  @forelse ($rows as $row)
    @if (! empty($compact))
      <tr>
        <td style="width: 70%">{{ $row['label'] }}</td>
        <td class="num" style="width: 30%">{{ $r->pct($row['share'], 0) }}</td>
      </tr>
      <tr>
        <td colspan="2" style="padding-top: 0"><div class="track"><div class="fill" style="width: {{ max(1, round($row['share'] * 100)) }}%"></div></div></td>
      </tr>
    @else
      <tr>
        <td style="width: 38%">{{ $row['label'] }}</td>
        <td style="width: 42%; padding-right: 3mm">
          <div class="track"><div class="fill" style="width: {{ max(1, round($row['share'] * 100)) }}%"></div></div>
        </td>
        <td class="num" style="width: 20%">{{ $r->pct($row['share'], 0) }} <span class="muted small">({{ $r->num($row['value']) }})</span></td>
      </tr>
    @endif
  @empty
    <tr><td class="muted">No data for this period yet.</td></tr>
  @endforelse
</table>
