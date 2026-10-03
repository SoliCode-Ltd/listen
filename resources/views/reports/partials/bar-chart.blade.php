{{-- Vertical bars, one per month. $chart = ['max' => float, 'bars' => [['label','value','height','partial']]] --}}
<table class="chart">
  <tr>
    @foreach ($chart['bars'] as $bar)
      <td style="height: 34mm">
        <div class="val">{{ $r->num($bar['value']) }}</div>
        <div class="bar{{ $bar['partial'] ? ' partial' : '' }}" style="height: {{ max(0.4, round($bar['height'] * 0.28, 1)) }}mm"></div>
      </td>
    @endforeach
  </tr>
  <tr>
    @foreach ($chart['bars'] as $bar)
      <td class="lbl">{{ $bar['label'] }}</td>
    @endforeach
  </tr>
</table>
