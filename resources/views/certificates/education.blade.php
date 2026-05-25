<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Сертификат · {{ $courseTitle }}</title>
  <style>
    @page { size: A4 landscape; margin: 0; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, 'Times New Roman', serif;
      color: #1A1F1B;
      background: #f0f3f0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .sheet {
      width: 297mm;
      max-width: 100%;
      aspect-ratio: 297 / 210;
      padding: 50px 60px;
      background:
        linear-gradient(135deg, #f8fbf8 0%, #fff 50%, #f4f9f4 100%);
      border: 14px double #2E7D32;
      border-radius: 6px;
      box-shadow: 0 30px 70px rgba(27, 94, 32, 0.15);
      position: relative;
      overflow: hidden;
    }
    .ornament {
      position: absolute;
      pointer-events: none;
      opacity: 0.08;
    }
    .ornament.tl { top: -40px; left: -40px; width: 220px; height: 220px; background: radial-gradient(closest-side, #2E7D32, transparent); }
    .ornament.br { bottom: -40px; right: -40px; width: 260px; height: 260px; background: radial-gradient(closest-side, #6EE87A, transparent); }
    .inner {
      position: relative;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .logo {
      font-size: 14px;
      letter-spacing: 4px;
      font-weight: 700;
      color: #1B5E20;
      text-transform: uppercase;
      margin-bottom: 12px;
    }
    .crest {
      width: 80px; height: 80px; border-radius: 50%;
      background: linear-gradient(135deg, #1B5E20, #6EE87A);
      display: flex; align-items: center; justify-content: center;
      color: white; font-weight: 700; font-size: 28px;
      box-shadow: 0 8px 24px rgba(27, 94, 32, 0.3);
      margin-bottom: 20px;
    }
    h1 {
      font-family: 'Georgia', serif;
      font-size: 44px;
      font-weight: 400;
      letter-spacing: 6px;
      text-transform: uppercase;
      margin: 0 0 6px;
      color: #1B5E20;
    }
    .subtitle {
      font-size: 14px;
      letter-spacing: 3px;
      color: #4A524C;
      text-transform: uppercase;
      margin-bottom: 36px;
    }
    .body-text {
      font-size: 18px;
      color: #4A524C;
      margin: 0;
    }
    .name {
      font-family: 'Georgia', serif;
      font-size: 42px;
      font-weight: 700;
      margin: 20px 0 24px;
      color: #1A1F1B;
      border-bottom: 2px solid #2E7D32;
      padding: 0 40px 14px;
      letter-spacing: 1px;
    }
    .course-block {
      margin-top: 10px;
      font-size: 18px;
      color: #4A524C;
    }
    .course-title {
      font-size: 26px;
      font-weight: 700;
      color: #1B5E20;
      margin-top: 8px;
      font-family: 'Georgia', serif;
    }
    .score {
      margin-top: 16px;
      font-size: 14px;
      color: #6F7480;
    }
    .footer {
      margin-top: auto;
      width: 100%;
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      padding-top: 30px;
      border-top: 1px solid rgba(46, 125, 50, 0.2);
      font-size: 12px;
      color: #6F7480;
    }
    .signature {
      text-align: center;
      width: 240px;
    }
    .signature-line {
      border-bottom: 1px solid #4A524C;
      margin-bottom: 6px;
      height: 30px;
    }
    .seal {
      width: 100px; height: 100px;
      border-radius: 50%;
      border: 3px solid #2E7D32;
      display: flex; align-items: center; justify-content: center;
      flex-direction: column;
      color: #2E7D32;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      opacity: 0.6;
    }
    .seal span:first-child { font-size: 18px; line-height: 1; }
    .cert-no {
      font-family: 'Courier New', monospace;
      letter-spacing: 1px;
    }
    .print-bar {
      position: fixed; top: 12px; right: 12px;
      display: flex; gap: 8px; z-index: 100;
    }
    .print-bar button {
      padding: 8px 14px; border: 0; border-radius: 6px;
      background: #2E7D32; color: white; cursor: pointer;
      font-family: inherit; font-size: 13px;
    }
    @media print {
      body { background: white; padding: 0; }
      .sheet { box-shadow: none; border-radius: 0; max-width: none; width: 100%; }
      .print-bar { display: none; }
    }
  </style>
</head>
<body>
  <div class="print-bar">
    <button onclick="window.print()">📄 Сохранить как PDF / Печать</button>
  </div>

  <div class="sheet">
    <div class="ornament tl"></div>
    <div class="ornament br"></div>

    <div class="inner">
      <div class="logo">DS Consulting</div>
      <div class="crest">DS</div>

      <h1>Сертификат</h1>
      <div class="subtitle">об успешном прохождении</div>

      <p class="body-text">Настоящим подтверждается, что</p>
      <div class="name">{{ $fio }}</div>
      <div class="course-block">
        прошёл(а) обучающий курс
        <div class="course-title">«{{ $courseTitle }}»</div>
      </div>
      @if ($score !== null && $totalQ !== null)
        <div class="score">Итоговый тест сдан: {{ $score }} из {{ $totalQ }} ({{ round(($score / max($totalQ, 1)) * 100) }}%)</div>
      @endif

      <div class="footer">
        <div class="signature">
          <div class="signature-line"></div>
          <div>Руководитель отдела обучения</div>
        </div>
        <div style="text-align:center">
          <div class="cert-no">№ {{ $certNo }}</div>
          <div>{{ $issuedAt }}</div>
        </div>
        <div class="seal">
          <span>DS</span>
          <span>2026</span>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
