#!/usr/bin/env bash
#
# Ночной бэкап боевой БД newds. Рабочая копия живёт на сервере в
# /usr/local/bin/newds-db-backup.sh, здесь — версия под контролем версий.
#
# Зачем: до 2026-08-20 автоматических бэкапов не было ВОВСЕ — в /var/backups/
# newds лежали только ручные дампы перед операциями (последний от 13.08). При
# этом прод автодеплоится по push в main и накатывает миграции сам, то есть
# деструктивная миграция уезжала на живую БД без страховки.
#
# Что делает:
#   • pg_dump -Fc (custom, сжатый) → /var/backups/newds/auto/
#   • 1-го числа копия уходит в monthly/
#   • ротация: daily 14 дней, monthly 180 дней
#   • дамп проверяется pg_restore --list: битый архив = ошибка, а не «успех»
#   • лог: /var/log/newds-backup.log
#
# Замер 2026-08-20: 38 секунд, 249 МБ. daily+monthly ≈ 5 ГБ на диске.
#
# Восстановление:
#   sudo -u postgres pg_restore -d newds -c /var/backups/newds/auto/newds-….dump
#
set -uo pipefail

# Уходим из чужого каталога: скрипт бежит под postgres, и если рабочей
# директорией остался /root, find ругается «Failed to restore initial
# working directory».
cd / || exit 1

DB=newds
ROOT=/var/backups/newds/auto
MONTHLY=$ROOT/monthly
LOG=/var/log/newds-backup.log
KEEP_DAILY=14
KEEP_MONTHLY=180
MIN_SIZE=$((50 * 1024 * 1024))   # 50 МБ: дамп меньше — почти наверняка обрезан

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

mkdir -p "$ROOT" "$MONTHLY"
STAMP=$(date +%Y%m%d-%H%M)
FILE="$ROOT/$DB-$STAMP.dump"

log "start → $FILE"

if ! pg_dump -Fc -Z6 "$DB" -f "$FILE" 2>>"$LOG"; then
    log "ОШИБКА: pg_dump упал, файл удалён"
    rm -f "$FILE"
    exit 1
fi

SIZE=$(stat -c%s "$FILE" 2>/dev/null || echo 0)
if [ "$SIZE" -lt "$MIN_SIZE" ]; then
    log "ОШИБКА: дамп подозрительно мал ($SIZE байт), файл удалён"
    rm -f "$FILE"
    exit 1
fi

# Целостность архива: читаем оглавление, не разворачивая данные.
if ! pg_restore --list "$FILE" > /dev/null 2>>"$LOG"; then
    log "ОШИБКА: архив не читается pg_restore, файл удалён"
    rm -f "$FILE"
    exit 1
fi

# Месячный слепок — первым числом.
if [ "$(date +%d)" = "01" ]; then
    cp -p "$FILE" "$MONTHLY/$DB-$(date +%Y%m).dump"
    log "месячная копия сохранена"
fi

find "$ROOT" -maxdepth 1 -name "$DB-*.dump" -mtime +$KEEP_DAILY -delete
find "$MONTHLY" -maxdepth 1 -name "$DB-*.dump" -mtime +$KEEP_MONTHLY -delete

log "готово: $(du -h "$FILE" | cut -f1), всего в daily: $(find "$ROOT" -maxdepth 1 -name "$DB-*.dump" | wc -l)"
