{{--
    A channel as a coloured disc with a WHITE glyph.

    Not App\Support\BrandIcons::render() — that emits each mark filled with
    its own brand colour, so a green WhatsApp glyph inside a green disc is
    invisible, and an unsupported slug like `web` renders as the literal
    word "web". Both were visible on the contacts table.

    The paths here are the same marks, drawn with currentColor so the disc
    supplies the colour and the glyph stays white. Matches the inbox exactly,
    so a channel looks identical wherever it appears.

    @param string $channel  whatsapp | instagram | facebook | messenger | web | phone
    @param int    $size     disc diameter in px
--}}
@php
    $ch = in_array($channel, ['messenger', 'facebook_page'], true) ? 'facebook' : $channel;

    $marks = [
        'whatsapp'  => 'M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 2.1.55 4.15 1.6 5.96L2 22l4.26-1.68a9.9 9.9 0 0 0 5.78 1.85h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.06c-.24.68-1.42 1.3-1.96 1.35-.5.05-.99.23-3.35-.7-2.82-1.11-4.6-3.97-4.74-4.16-.14-.19-1.13-1.5-1.13-2.86 0-1.36.71-2.03.96-2.31.25-.28.55-.35.73-.35h.52c.17 0 .4-.06.62.48.24.57.8 1.98.87 2.12.07.14.12.31.02.5-.09.19-.14.31-.28.47l-.42.49c-.14.14-.28.29-.12.57.16.28.72 1.18 1.54 1.91 1.06.94 1.95 1.23 2.23 1.37.28.14.44.12.6-.07.17-.19.7-.81.88-1.09.19-.28.37-.23.63-.14.26.09 1.66.78 1.94.93.28.14.47.21.54.33.07.12.07.68-.17 1.36z',
        'instagram' => 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 5.68A4.16 4.16 0 1 0 16.16 12 4.16 4.16 0 0 0 12 7.84zm0 6.86A2.7 2.7 0 1 1 14.7 12 2.7 2.7 0 0 1 12 14.7zm5.3-7.1a.97.97 0 1 1-.97-.97.97.97 0 0 1 .97.97z',
        'facebook'  => 'M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z',
    ];

    $backgrounds = [
        'whatsapp'  => '#25d366',
        'facebook'  => '#1877f2',
        'instagram' => 'radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%)',
    ];

    $size  = $size ?? 24;
    $glyph = round($size * 0.55);
@endphp

<span title="{{ ucfirst($ch) }}"
      style="display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;
             width:{{ $size }}px;height:{{ $size }}px;border-radius:50%;color:#fff;
             background:{{ $backgrounds[$ch] ?? '#94a3b8' }};
             box-shadow:0 1px 2px rgba(15,23,42,.16);">
    @if (isset($marks[$ch]))
        <svg viewBox="0 0 24 24" width="{{ $glyph }}" height="{{ $glyph }}" fill="currentColor" aria-hidden="true">
            <path d="{{ $marks[$ch] }}"/>
        </svg>
    @else
        {{-- Web chat, phone and anything new: a lucide glyph rather than the
             raw slug, which is what BrandIcons fell back to. --}}
        <i data-lucide="{{ $ch === 'phone' ? 'phone' : 'globe' }}"
           style="width:{{ $glyph }}px;height:{{ $glyph }}px"></i>
    @endif
</span>
