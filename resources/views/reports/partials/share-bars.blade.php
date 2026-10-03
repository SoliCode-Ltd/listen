{{-- Horizontal share bars. $rows = [['label', 'value', 'share']] --}}
<table class="share">
  @forelse ($rows as $row)
    <tr>
      <td style="width: 34%">{{ $row['label'] }}</td>
      <td style="width: 46%; padding-right: 3mm">
        <div class="track"><div class="fill" style="width: {{ max(1, round($row['share'] * 100)) }}%"></div></div>
      </td>
      <td class="num" style="width: 20%">{{ $r->pct($row['share'], 0) }} <span class="muted small">({{ $r->num($row['value']) }})</span></td>
    </tr>
  @empty
    <tr><td class="muted">No data for this period yet.</td></tr>
  @endforelse
</table>
