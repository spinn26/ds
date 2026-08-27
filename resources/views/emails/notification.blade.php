<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subject ?? 'Уведомление' }}</title>
</head>
{{-- Шаблон уведомления платформы. Верстка повторяет emails.reset-password:
     inline-only стили (gmail и yandex.mail отрезают <style>), table-layout для
     старых клиентов, те же бренд-цвета — primary #2E7D32, brand-ink #0A2B10.
     Акцент шапки меняется по типу уведомления ($accent), чтобы «Терминация»
     и «Пул зафиксирован» не выглядели одинаково. --}}
<body style="margin:0; padding:0; background:#F4F6F4; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color:#0A2B10;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F4F6F4; padding:32px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px; width:100%; background:#FFFFFF; border-radius:14px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.06);">

        {{-- Header с логотипом --}}
        <tr>
          <td style="background:linear-gradient(135deg, #1B5E20 0%, #2E7D32 100%); padding:32px 40px; text-align:center;">
            <img src="{{ $logoUrl }}" alt="DS Consulting"
              style="display:inline-block; max-width:200px; height:auto;" />
            <div style="margin-top:14px; color:#FFFFFF; font-size:13px; letter-spacing:0.6px; opacity:0.9; text-transform:uppercase;">
              Партнёрская платформа
            </div>
          </td>
        </tr>

        {{-- Плашка типа уведомления --}}
        <tr>
          <td style="padding:28px 40px 0; text-align:left;">
            <span style="display:inline-block; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:0.6px; text-transform:uppercase; color:{{ $accent }}; background:{{ $accentBg }};">
              {{ $typeLabel }}
            </span>
          </td>
        </tr>

        {{-- Заголовок и текст --}}
        <tr>
          <td style="padding:14px 40px 8px; text-align:left;">
            <h1 style="margin:0 0 10px; font-size:22px; line-height:1.3; font-weight:700; color:#0A2B10; letter-spacing:-0.2px;">
              {{ $title }}
            </h1>
            <p style="margin:0 0 6px; font-size:14px; line-height:1.5; color:#5A6B5C;">
              {{ $greeting }}
            </p>
            @if (!empty($bodyText))
              <p style="margin:0; font-size:15px; line-height:1.6; color:#0A2B10;">
                {!! nl2br(e($bodyText)) !!}
              </p>
            @endif
          </td>
        </tr>

        {{-- CTA --}}
        <tr>
          <td style="padding:26px 40px 8px; text-align:center;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
              <tr>
                <td style="border-radius:10px; background:#2E7D32;">
                  <a href="{{ $url }}"
                    style="display:inline-block; padding:14px 32px; font-size:15px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:10px; letter-spacing:0.2px;">
                    Открыть в личном кабинете
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Fallback-ссылка: часть клиентов режет кнопки --}}
        <tr>
          <td style="padding:14px 40px 24px; text-align:center;">
            <p style="margin:0; font-size:12px; color:#8A968C; word-break:break-all;">
              Если кнопка не работает — скопируйте ссылку:<br>
              <a href="{{ $url }}" style="color:#2E7D32; text-decoration:underline;">{{ $url }}</a>
            </p>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="background:#F0F4F1; padding:20px 40px; text-align:center;">
            <p style="margin:0 0 4px; font-size:12px; color:#5A6B5C; font-weight:600;">
              DS Consulting · Партнёрская платформа
            </p>
            <p style="margin:0; font-size:11px; color:#8A968C;">
              © {{ date('Y') }} DS Consulting · автоматическое уведомление, не отвечайте на это письмо
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
