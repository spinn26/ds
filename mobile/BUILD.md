# Сборка APK для Android

Иконки и splash-screen уже сгенерированы и лежат в `android/app/src/main/res/`. Осталось только собрать APK.

Локально на этой машине **нет JDK 17 и Android SDK**, поэтому собрать APK прямо отсюда нельзя. Есть три варианта, в порядке простоты для вас:

## Вариант 1 (рекомендую) — через GitHub Actions

Сборка в облаке, ничего ставить не надо.

1. Закоммитьте и запушьте текущее состояние:
   ```bash
   git add mobile .github/workflows/mobile-android.yml
   git commit -m "feat(mobile): icons + capacitor android"
   git push
   ```
2. В GitHub → **Actions** → слева workflow **«Mobile · Android APK»** → справа **Run workflow** (или просто запустить кнопку, ветка по умолчанию подойдёт).
3. Через 5-7 минут в job-е будет вкладка **Artifacts** с файлом `ds-partner-debug-apk.zip` — внутри `app-debug.apk`.
4. Скачайте, разархивируйте, перенесите APK на телефон (Telegram себе / Google Drive / USB) и установите.

Чтобы автоматически делать ещё и GitHub Release при тегировании:
```bash
git tag mobile-v0.1.0
git push origin mobile-v0.1.0
```
Workflow создаст релиз с прикреплённым APK — удобно раздавать тестировщикам через прямую ссылку.

## Вариант 2 — через Android Studio (локально)

Если хотите потом удобно дебажить и собирать локально.

1. Скачайте **Android Studio**: <https://developer.android.com/studio>. Установщик принесёт с собой JDK 17 + Android SDK + Build Tools.
2. После установки откройте Android Studio → **«Open»** → выберите папку `c:/Users/ENCODE/Desktop/ds/mobile/android`.
3. Дождитесь Gradle Sync (5-10 минут на первый раз — качаются зависимости).
4. **Build → Build Bundle(s) / APK(s) → Build APK(s)** → внизу появится зелёная плашка «Locate» — нажмите, и Explorer откроется на готовом APK по пути:
   ```
   mobile/android/app/build/outputs/apk/debug/app-debug.apk
   ```
5. Перенесите APK на телефон и установите. На телефоне разрешите «установка из неизвестных источников» для приложения, через которое открываете APK (Files / Telegram / etc).

Для быстрого запуска на эмуляторе или подключённом устройстве — нажать в Android Studio **▶ Run «app»** (Shift+F10).

## Вариант 3 — командная строка с установленным JDK

Если уже есть JDK 17 и Android SDK:

```bash
cd mobile
npm install --legacy-peer-deps
npm run build
npx cap sync android
cd android
./gradlew assembleDebug          # Linux/Mac
# или: gradlew.bat assembleDebug  # Windows
```

APK будет здесь:
```
mobile/android/app/build/outputs/apk/debug/app-debug.apk
```

---

## Установка APK на телефон

1. Перенесите файл на устройство (Telegram «избранное» — самый быстрый способ).
2. Откройте APK прямо из мессенджера/Files.
3. Android спросит про разрешение «установка из неизвестных источников» → разрешить для этого приложения.
4. Готово. Иконка «DS Partner» появится на рабочем столе.

При первом запуске:
- Spalsh-screen с буквами «DS» на тёмно-зелёном фоне.
- Login-экран с переключателем «Партнёр / Сотрудник».
- При нажатии Push toggle в Настройках → Android попросит разрешение на уведомления.

## Куда смотреть, если что-то пошло не так

- **Сборка падает на Gradle**: вероятно, неправильная версия JDK. Capacitor 6 требует **JDK 17**. Проверьте: `java -version`.
- **«SDK location not found»**: установите ANDROID_HOME, или откройте проект в Android Studio — он сам найдёт SDK.
- **APK ставится, но падает при запуске**: смотрите логи через `adb logcat` (если подключите телефон по USB и включите USB-debugging).

## После релиза

Для **прод-сборки** (signed Release APK / AAB для Google Play):
1. Создайте keystore: `keytool -genkey -v -keystore release.keystore -alias ds -keyalg RSA -keysize 2048 -validity 10000`.
2. Положите `release.keystore` в `mobile/android/app/`.
3. Добавьте в `mobile/android/app/build.gradle` секцию `signingConfigs { release { … } }` с паролем.
4. Соберите: `./gradlew assembleRelease` или `./gradlew bundleRelease` (для Google Play).
5. Залейте в Play Console → Internal Testing track для первого теста.

Для **iOS** — нужен Mac. На Windows можно настроить только Android.
