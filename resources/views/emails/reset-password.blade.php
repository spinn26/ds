<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subject ?? 'Восстановление пароля' }}</title>
</head>
{{-- DS Consulting brand colors:
     primary  #2E7D32 (dark green), brand #6EE87A (mint), brand-ink #0A2B10.
     Inline-only стили: gmail и yandex.mail отрезают <style>. Table-layout
     для совместимости со старыми клиентами. --}}
<body style="margin:0; padding:0; background:#F4F6F4; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color:#0A2B10;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F4F6F4; padding:32px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px; width:100%; background:#FFFFFF; border-radius:14px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.06);">

        {{-- Header с зелёным градиентом + логотип --}}
        <tr>
          <td style="background:linear-gradient(135deg, #1B5E20 0%, #2E7D32 100%); padding:32px 40px; text-align:center;">
            <img src="{{ $logoUrl }}" alt="DS Consulting"
              style="display:inline-block; max-width:200px; height:auto;" />
            <div style="margin-top:14px; color:#FFFFFF; font-size:13px; letter-spacing:0.6px; opacity:0.9; text-transform:uppercase;">
              Партнёрская платформа
            </div>
          </td>
        </tr>

        {{-- Заголовок --}}
        <tr>
          <td style="padding:36px 40px 8px; text-align:left;">
            <h1 style="margin:0 0 8px; font-size:24px; line-height:1.3; font-weight:700; color:#0A2B10; letter-spacing:-0.2px;">
              Восстановление пароля
            </h1>
            <p style="margin:0; font-size:14px; line-height:1.5; color:#5A6B5C;">
              Здравствуйте! Вы запросили сброс пароля для аккаунта на платформе DS Consulting.
            </p>
          </td>
        </tr>

        {{-- CTA-кнопка --}}
        <tr>
          <td style="padding:28px 40px 8px; text-align:center;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
              <tr>
                <td style="border-radius:10px; background:#2E7D32;">
                  <a href="{{ $url }}"
                    style="display:inline-block; padding:14px 32px; font-size:15px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:10px; letter-spacing:0.2px;">
                    Установить новый пароль
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Длительность и техссылка fallback --}}
        <tr>
          <td style="padding:16px 40px 8px; text-align:center;">
            <p style="margin:0; font-size:13px; color:#5A6B5C;">
              Ссылка действительна <strong>{{ $expireMinutes }} минут</strong>.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 40px 24px; text-align:center;">
            <p style="margin:0; font-size:12px; color:#8A968C; word-break:break-all;">
              Если кнопка не работает — скопируйте ссылку:<br>
              <a href="{{ $url }}" style="color:#2E7D32; text-decoration:underline;">{{ $url }}</a>
            </p>
          </td>
        </tr>

        {{-- Дисклеймер --}}
        <tr>
          <td style="padding:18px 40px 28px;">
            <div style="border-top:1px solid #E4EAE5; padding-top:16px;">
              <p style="margin:0; font-size:12px; line-height:1.5; color:#8A968C;">
                Если вы <strong>не запрашивали</strong> сброс пароля — проигнорируйте письмо,
                ваш текущий пароль останется без изменений. Никому не сообщайте эту ссылку.
              </p>
            </div>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="background:#F0F4F1; padding:20px 40px; text-align:center;">
            <p style="margin:0 0 4px; font-size:12px; color:#5A6B5C; font-weight:600;">
              DS Consulting · Партнёрская платформа
            </p>
            <p style="margin:0; font-size:11px; color:#8A968C;">
              © {{ date('Y') }} DS Consulting · 152-ФЗ · автоматическое уведомление, не отвечайте на это письмо
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
