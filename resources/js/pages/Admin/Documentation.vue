<template>
  <div class="docwrap">
    <p class="eyebrow">DS Consulting · чеклист</p>
    <h1 class="doc-h1">Что обновилось на платформе</h1>
    <p class="lede">Коротко: что поправили и как проверить каждый пункт. Легенда:
      <span class="tag t-new">новое</span> кнопка/раздел ·
      <span class="tag t-code">код</span> логика ·
      <span class="tag t-data">данные</span> правка на проде.</p>
    <p class="meta">Обновлено 8 июля 2026. Перед проверкой обновите страницу — <b>Ctrl&nbsp;+&nbsp;Shift&nbsp;+&nbsp;R</b>.</p>

    <template v-for="grp in groups" :key="grp.title">
      <h2 class="doc-h2"><span class="dot" :style="{ background: grp.dot }"></span>{{ grp.title }}</h2>
      <div v-for="it in grp.items" :key="it.title" class="item">
        <h3>
          {{ it.title }}
          <span v-for="t in it.tags" :key="t" class="tag" :class="'t-' + t">{{ tagLabel[t] }}</span>
        </h3>
        <div class="what">{{ it.what }}</div>
        <div class="check">
          <div class="lbl">Проверка</div>
          <ol class="steps">
            <li v-for="(s, i) in it.steps" :key="i" v-html="s"></li>
          </ol>
        </div>
      </div>
    </template>

    <p class="foot">По вопросам — в рабочий чат.</p>
  </div>
</template>

<script setup>
const tagLabel = { new: 'новое', code: 'код', data: 'данные' };

const groups = [
  {
    title: 'Новые кнопки и разделы', dot: 'var(--doc-feat)',
    items: [
      {
        title: 'Полный перерасчёт комиссий', tags: ['new'],
        what: 'Кнопка в шапке «Транзакции». Заново считает процент ДС, доход, цепочки и балансы по всем открытым периодам — удобно после импорта или правки тарифов.',
        steps: [
          'Транзакции → сверху жёлтая кнопка <b>«Полный перерасчёт»</b> → подтвердить.',
          'Появится сообщение «Полный перерасчёт запущен…».',
          'Через пару минут открыть «Комиссии» — процент ДС и доход проставлены (не пусто и не 0).',
        ],
      },
      {
        title: 'Пересчёт по новому курсу валют', tags: ['new'],
        what: 'Теперь при смене курса рубли и комиссии пересчитываются сами. Плюс на странице «Валюты» есть кнопка для ручного прогона. Раньше помогало только удаление и повторный завод транзакции.',
        steps: [
          'Справочники (Валюты) → изменить курс → сохранить → появится сообщение о запуске пересчёта.',
          'Кнопка <b>«Пересчитать с новым курсом»</b> в шапке — запускает то же вручную.',
          'Открыть транзакции этой валюты за открытый месяц — рублёвый эквивалент и доход пересчитаны.',
        ],
      },
      {
        title: 'Раздел «Скрытые клиенты»', tags: ['new'],
        what: 'Служебный раздел: клиенты, которые скрыты, но за ними ещё числятся живые контракты. Только для админов.',
        steps: [
          'Админка → <b>Финансы и контроль → Скрытые клиенты</b>.',
          'Видна сводка, фильтры и таблица с пометками «тест / внутр / на проверку». Раздел «почему так» — сворачивается.',
        ],
      },
    ],
  },
  {
    title: 'Изменения логики', dot: 'var(--doc-accent)',
    items: [
      {
        title: 'Импорт Кономи (RG.HT): свойство и процент ДС', tags: ['code', 'data'],
        what: 'Раньше при импорте не подтягивалось свойство, а процент ДС ставился 100%. Теперь у продукта есть матрица тарифов.',
        steps: [
          'Ручной ввод контракта Кономи → появляется выбор «Свойство» (МФ / Апфронт).',
          'После импорта Кономи и «Полного перерасчёта» процент ДС = 0,5% (МФ) или 2% (Апфронт), не 100%.',
        ],
      },
      {
        title: 'Импорт: валюта USD / EUR (Траст)', tags: ['code'],
        what: 'Доллар и евро теперь определяются корректно, суммы с пробелами-разделителями читаются как надо.',
        steps: [
          'Залить тестовый лист Trust (валюта USD) → суммы встают в долларах, а не рублях.',
          'Что уже залилось в рублях — такую партию нужно перезалить.',
        ],
      },
      {
        title: 'Модалка «Изменить процент ДС»: фильтр по Году КВ', tags: ['code'],
        what: 'Показывает ставки только выбранного года выплаты; дублирующие диапазоны «3, 4, 5 год» скрыты, если есть точечная ставка года.',
        steps: [
          'Черновик Medlife, Год КВ = 3 → «Изменить процент ДС» → только варианты года 3 (по датам), без чужих годов.',
        ],
      },
      {
        title: '«Своя комиссия»: НДС', tags: ['code'],
        what: 'Введённая сумма остаётся в поле, куда вводили (с НДС), а не «улетает» в доход без НДС.',
        steps: [
          'Черновик → «Своя комиссия» → ввести сумму до «Рассчитать» → после расчёта сумма осталась в поле, «без НДС» считается от неё.',
        ],
      },
      {
        title: 'Комиссии: продукт, поставщик и цепочка', tags: ['code'],
        what: 'Продукт и поставщик берутся из справочника (без путаницы ГГА / БКС / IPO). Цепочка выводится сверху вниз. Отчёты за май — тоже из справочника.',
        steps: [
          'Комиссии → продукт и поставщик корректны; раскрыть цепочку → старший наставник сверху, прямой партнёр (жирным) снизу.',
          'Отчёты за май → продукт, программа и поставщик из справочника.',
        ],
      },
      {
        title: 'Комиссия за прошлые периоды больше не ноль', tags: ['data'],
        what: 'У 192 860 старых строк рублёвая сумма лежала в другой колонке — заполнили.',
        steps: [
          'Комиссии, фильтр за март–май 2026 → колонка «Комиссия» показывает суммы, а не 0.',
        ],
      },
      {
        title: 'Поиск и метрика дашборда', tags: ['code'],
        what: 'Поиск в «Транзакциях» и «Контрактах» — по фамилии партнёра и номеру договора, а не по всем колонкам. Клиента ищите через отдельное поле.',
        steps: [
          'Транзакции / Контракты → ввести фамилию партнёра → выборка только по партнёру.',
          'Дашборд → карточка «Активных», разница = число активировавшихся за период.',
        ],
      },
    ],
  },
  {
    title: 'Правки данных на проде', dot: 'var(--doc-data)',
    items: [
      {
        title: 'Восстановлены июньские контракты', tags: ['data'],
        what: 'Потеряны при переносе данных. Восстановлены, в том числе у Канаевой и Бойченко.',
        steps: ['Контракты → найти Канаеву или Бойченко → их контракты на месте (продукт, сумма, июньская дата).'],
      },
      {
        title: 'Обновлены квалификации', tags: ['data'],
        what: 'Переоценка по сниженным порогам НГП: часть партнёров поднялась до ФК и Мастер ФК.',
        steps: [
          'Партнёры → Муталов Шамиль → квалификация «Мастер ФК».',
          'Квалификации → уровни повышенных партнёров обновлены.',
        ],
      },
      {
        title: 'Партнёры со статусом-прочерком → «Активен»', tags: ['data'],
        what: 'У нескольких партнёров статус висел пустым — перевели в «Активен» с датой активации 1 июня.',
        steps: ['Статусы партнёров → поиск «Белая» → статус «Активен», не прочерк.'],
      },
      {
        title: 'Продукт контрактов выровнен по программе', tags: ['data'],
        what: 'Часть контрактов сидела на «пустом» продукте (IPO, Hansard и т.п.), а программа была под другим. Продукт выровняли по программе.',
        steps: [
          'Контракт <code>500100_1000000155</code> (был IPO) → продукт теперь «БКС Страхование Жизни».',
          'У таких контрактов процент ДС мог устареть → нажать «Полный перерасчёт».',
        ],
      },
      {
        title: 'Почищены дубли клиентов', tags: ['data'],
        what: 'Задвоенные карточки объединены, контракты собраны на одну запись клиента.',
        steps: ['Клиенты → нет двойных карточек одного человека; у клиента все контракты на одной записи.'],
      },
    ],
  },
];
</script>

<style scoped>
.docwrap{
  --doc-bg:transparent; --doc-surface:#fff; --doc-surface-2:#F2F4EE; --doc-ink:#181D19;
  --doc-muted:#697066; --doc-faint:#9AA096; --doc-border:#E4E7E0; --doc-line:#D3D8CC;
  --doc-accent:#2E7D32; --doc-accent-soft:#E6F1E6; --doc-code:#0A2B10;
  --doc-data:#B45309; --doc-data-soft:#FBF0E1; --doc-feat:#1D5F8A; --doc-feat-soft:#E4EFF6;
  --doc-shadow:0 1px 2px rgba(20,30,20,.04),0 8px 24px rgba(20,30,20,.05);
  --doc-mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  max-width:900px;margin:0 auto;
}
@media (prefers-color-scheme:dark){.docwrap{
  --doc-surface:#181B15;--doc-surface-2:#1F231C;--doc-ink:#E9ECE3;--doc-muted:#9AA18F;--doc-faint:#6C7364;
  --doc-border:#292E24;--doc-line:#39402F;--doc-accent:#6EE87A;--doc-accent-soft:#152E1A;--doc-code:#B7F0BE;
  --doc-data:#E7A44D;--doc-data-soft:#31280F;--doc-feat:#79B7E1;--doc-feat-soft:#122230;
  --doc-shadow:0 1px 2px rgba(0,0,0,.3),0 10px 28px rgba(0,0,0,.34);
}}
.docwrap :deep(*){box-sizing:border-box}
.eyebrow{font-size:12px;letter-spacing:.15em;text-transform:uppercase;color:var(--doc-accent);font-weight:700;margin:0 0 8px}
.doc-h1{font-size:clamp(24px,4vw,34px);line-height:1.1;letter-spacing:-.02em;margin:0 0 10px;font-weight:760;color:var(--doc-ink)}
.lede{color:var(--doc-muted);max-width:66ch;margin:0;font-size:15px;line-height:1.55}
.meta{font-size:12.5px;color:var(--doc-faint);margin-top:12px}
.doc-h2{font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:var(--doc-muted);font-weight:700;margin:34px 0 4px;display:flex;align-items:center;gap:9px}
.doc-h2 .dot{width:8px;height:8px;border-radius:2px;flex:none}
.item{background:var(--doc-surface);border:1px solid var(--doc-border);border-radius:14px;padding:16px 18px;margin-top:12px;box-shadow:var(--doc-shadow)}
.item h3{margin:0 0 3px;font-size:15.5px;letter-spacing:-.01em;color:var(--doc-ink);display:flex;gap:9px;align-items:baseline;flex-wrap:wrap;font-weight:650}
.tag{font-size:10.5px;font-weight:700;letter-spacing:.03em;padding:2px 7px;border-radius:6px;text-transform:uppercase;white-space:nowrap}
.t-code{background:var(--doc-accent-soft);color:var(--doc-accent)}
.t-data{background:var(--doc-data-soft);color:var(--doc-data)}
.t-new{background:var(--doc-feat-soft);color:var(--doc-feat)}
.what{color:var(--doc-muted);font-size:13.5px;margin:2px 0 10px;line-height:1.55}
.check{border-top:1px dashed var(--doc-line);padding-top:10px}
.check .lbl{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--doc-accent);margin-bottom:5px}
ol.steps{margin:0;padding-left:20px;font-size:13.5px;color:var(--doc-ink)}
ol.steps li{margin:3px 0;line-height:1.5}
ol.steps li::marker{color:var(--doc-faint)}
.item :deep(code),.what :deep(code){font-family:var(--doc-mono);font-size:12.5px;background:var(--doc-surface-2);padding:1px 6px;border-radius:5px;color:var(--doc-code);word-break:break-word}
.foot{margin-top:34px;padding-top:16px;border-top:1px solid var(--doc-border);color:var(--doc-faint);font-size:12.5px}
</style>
