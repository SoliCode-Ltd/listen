{{-- Seven bars from the Listen wordmark. Drawn in HTML so the mark shows before a mail app loads images. --}}
<table role="presentation" cellpadding="0" cellspacing="0" aria-label="Listen" style="border-collapse:collapse;">
    <tr>
        @foreach ([6, 14, 22, 32, 22, 14, 6] as $height)
            <td valign="bottom" style="padding:0 2px 0 0;font-size:0;line-height:0;">
                <div style="width:5px;height:{{ $height }}px;background:#7659FA;border-radius:3px;font-size:0;line-height:{{ $height }}px;">&nbsp;</div>
            </td>
        @endforeach
    </tr>
</table>
