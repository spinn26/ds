<template>
  <div class="profile-page">
    <PageHeader title="Профиль" icon="mdi-account-circle-outline">
      <template #subtitle>
        {{ isEmployee ? 'личные данные · безопасность · уведомления' : 'личные данные · документы · реквизиты · безопасность' }}
      </template>
    </PageHeader>

    <!-- Статус заполнения профиля (только для активного ФК, к кому
         относится требование). Заполнено — зелёное «всё ок»; нет —
         жёлтое со списком недостающих полей. -->
    <v-alert
      v-if="auth.user?.profileRequired && auth.user?.profileComplete"
      type="success" variant="tonal" density="compact" class="mb-4"
      icon="mdi-check-circle-outline"
    >
      <span class="font-weight-medium">Профиль заполнен полностью.</span>
      Все обязательные данные на месте — всё в порядке.
    </v-alert>
    <v-alert
      v-else-if="auth.user?.profileRequired && auth.user?.profileComplete === false"
      type="warning" variant="tonal" density="compact" class="mb-4"
      icon="mdi-account-alert-outline"
    >
      <div class="text-body-2">
        <strong>Заполните профиль.</strong>
        Обязательны личные данные.
        <template v-if="profileMissingLabels"> Не хватает: {{ profileMissingLabels }}.</template>
      </div>
    </v-alert>

    <!-- ────────────────────  HERO  ──────────────────── -->
    <v-card class="profile-hero mb-4" elevation="0">
      <div class="profile-hero__avatar-wrap">
        <v-avatar :size="72" color="primary" class="profile-hero__avatar">
          <v-img v-if="profile.user?.avatarUrl" :src="profile.user.avatarUrl" cover />
          <span v-else class="profile-hero__initials">{{ initials || '?' }}</span>
        </v-avatar>
      </div>
      <div class="profile-hero__main">
        <div class="profile-hero__name">
          {{ profile.user?.lastName }} {{ profile.user?.firstName }} {{ profile.user?.patronymic }}
        </div>
        <div class="profile-hero__sub">
          <span>{{ profile.user?.email }}</span>
          <template v-if="!isEmployee && profile.user?.participantCode">
            <span class="profile-hero__dot">·</span>
            <span>ID {{ profile.user.participantCode }}</span>
          </template>
          <template v-if="isEmployee && roleLabel">
            <span class="profile-hero__dot">·</span>
            <span>{{ roleLabel }}</span>
          </template>
        </div>
        <div v-if="heroChips.length" class="profile-hero__chips">
          <v-chip v-for="(c, i) in heroChips" :key="i"
            :color="c.color" :variant="c.variant || 'tonal'" size="small">
            {{ c.text }}
          </v-chip>
        </div>
      </div>
      <div class="profile-hero__actions">
        <v-btn variant="outlined" size="small" prepend-icon="mdi-camera"
          :loading="avatarUploading"
          @click="avatarInput?.click()">
          Сменить фото
        </v-btn>
        <input ref="avatarInput" type="file" accept="image/*" hidden @change="onAvatarPick" />
      </div>
    </v-card>

    <!-- ────────────────  NAV + CONTENT  ──────────────── -->
    <div class="profile-layout">
      <!-- LEFT NAV -->
      <aside class="profile-nav">
        <button v-for="item in navItems" :key="item.value"
          class="profile-nav__item"
          :class="{ 'profile-nav__item--active': tab === item.value }"
          @click="tab = item.value">
          <v-icon size="20" class="profile-nav__icon">{{ item.icon }}</v-icon>
          <span>{{ item.title }}</span>
        </button>
      </aside>

      <!-- CONTENT -->
      <section class="profile-content">

        <!-- ════════════════  ЛИЧНЫЕ ДАННЫЕ  ════════════════ -->
        <div v-show="tab === 'info'">
          <v-card class="ds-card mb-3">
            <div class="ds-card__head">
              <div class="ds-title-l">{{ isEmployee ? 'Информация о сотруднике' : 'Личные данные' }}</div>
            </div>
            <div class="ds-card__body">
              <v-row dense>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.lastName" label="Фамилия"
                    :disabled="!isEmployee"
                    :hint="!isEmployee ? 'Изменение через техподдержку' : null"
                    persistent-hint />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.firstName" label="Имя"
                    :disabled="!isEmployee"
                    :hint="!isEmployee ? 'Изменение через техподдержку' : null"
                    persistent-hint />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.patronymic" label="Отчество"
                    :disabled="!isEmployee"
                    :hint="!isEmployee ? 'Изменение через техподдержку' : null"
                    persistent-hint />
                </v-col>
                <v-col v-if="isEmployee" cols="12" sm="6" md="4">
                  <v-text-field v-model="form.position" label="Должность"
                    prepend-inner-icon="mdi-briefcase-outline" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.birthDate" label="Дата рождения" type="date"
                    prepend-inner-icon="mdi-cake-variant-outline" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-select v-model="form.gender" :items="genderOptions" label="Пол"
                    prepend-inner-icon="mdi-human-male-female" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-autocomplete v-model="form.country" :items="countryOptions" label="Страна"
                    prepend-inner-icon="mdi-flag-outline" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <!-- Подсказки городов из DaData (серверный поиск), не из
                       таблицы `city`. Свободный ввод запрещён (autocomplete) —
                       чтобы в справочник больше не попадал мусор. -->
                  <v-autocomplete v-model="form.city" :items="cityItems" :loading="cityLoading"
                    @update:search="onCitySearch" no-filter clearable
                    item-title="title" item-value="value"
                    label="Город" prepend-inner-icon="mdi-city-variant-outline"
                    no-data-text="Введите минимум 2 символа" @update:model-value="onCityPicked" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.email" label="Email" type="email"
                    prepend-inner-icon="mdi-email-outline" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.phone" label="Телефон"
                    prepend-inner-icon="mdi-phone-outline" />
                </v-col>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="form.telegram" label="Telegram"
                    prepend-inner-icon="mdi-send" placeholder="@username" />
                </v-col>
              </v-row>

              <v-alert v-if="saveMsg" :type="saveMsgType" density="compact" class="mt-3" closable @click:close="saveMsg = ''">
                {{ saveMsg }}
              </v-alert>
            </div>
            <div class="ds-card__actions">
              <v-btn variant="text" @click="loadProfile">Отменить</v-btn>
              <v-btn color="primary" :loading="saving" prepend-icon="mdi-content-save" @click="saveProfile">
                Сохранить
              </v-btn>
            </div>
          </v-card>

          <!-- Документы партнёра (только partner). Бэкенд возвращает
               документы обязательного флоу акцепта (Согласие, Политика,
               Оферта, ПЭП) с per-документной датой акцепта. Показываем все —
               со ссылкой и отметкой «подписано / не подписано». -->
          <v-card v-if="!isEmployee && docsList.length" class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-file-document-multiple-outline</v-icon>
                Документы
              </div>
            </div>
            <div class="ds-card__body">
              <v-list density="comfortable">
                <v-list-item v-for="d in docsList" :key="d.id"
                  :href="d.url || undefined" :target="d.url ? '_blank' : undefined" rel="noopener"
                  :prepend-icon="d.signedAt ? 'mdi-file-check-outline' : 'mdi-file-document-outline'"
                  :title="d.title"
                  :subtitle="d.signedAt ? `подписано ${fmtShortDate(d.signedAt)}` : 'не подписано'">
                  <template v-if="d.url" #append>
                    <v-icon size="16" color="medium-emphasis">mdi-open-in-new</v-icon>
                  </template>
                </v-list-item>
              </v-list>
            </div>
          </v-card>
        </div>

        <!-- ════════════════  ДОКУМЕНТЫ  ════════════════ -->
        <div v-show="tab === 'documents' && !isEmployee">
          <v-card class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-file-document-multiple-outline</v-icon>
                Документы партнёра
              </div>
            </div>
            <div class="ds-card__body">
              <v-row dense>
                <v-col v-for="slot in documentSlots" :key="slot.type" cols="12" md="4">
                  <v-card variant="outlined" class="doc-slot pa-3">
                    <div class="d-flex align-center ga-2 mb-2">
                      <span class="ds-title-s">{{ slot.label }}</span>
                      <v-spacer />
                      <v-icon v-if="isDocUploaded(slot.type)" color="success" size="18">mdi-check-circle</v-icon>
                    </div>
                    <v-file-input
                      v-model="docFiles[slot.type]"
                      accept="image/*,.pdf"
                      density="compact"
                      variant="outlined"
                      :label="isDocUploaded(slot.type) ? 'Заменить файл' : 'Выберите файл'"
                      prepend-icon=""
                      prepend-inner-icon="mdi-paperclip"
                      hide-details
                      class="mb-2" />
                    <v-btn block size="small" color="primary"
                      :disabled="!docFiles[slot.type]"
                      :loading="docUploading[slot.type]"
                      prepend-icon="mdi-upload"
                      @click="uploadDocument(slot.type)">
                      Загрузить
                    </v-btn>
                  </v-card>
                </v-col>
              </v-row>
              <v-alert v-if="docMsg" :type="docMsgType" density="compact" class="mt-3" closable @click:close="docMsg = ''">
                {{ docMsg }}
              </v-alert>
            </div>
          </v-card>
        </div>

        <!-- ════════════════  РЕКВИЗИТЫ  ════════════════ -->
        <div v-show="tab === 'requisites' && !isEmployee">
          <!-- Общее правило для вкладки: только ИП на УСН, удалить нельзя. -->
          <v-alert type="info" variant="tonal" density="compact" class="mb-3" prepend-icon="mdi-information-outline">
            <div class="text-body-2 mb-1">
              Партнёром ДС может быть только <strong>ИП на УСН</strong>. Иное юр.&nbsp;лицо —
              <a href="https://t.me/DS_Helpdesk" target="_blank" rel="noopener" class="text-primary">@DS_Helpdesk</a>.
            </div>
            <div class="text-caption text-medium-emphasis">
              Реквизиты редактируются, но не удаляются. Сброс — только через техподдержку.
            </div>
          </v-alert>

          <!-- Дисклеймер о полноте: верификация не пройдёт, пока не заполнены
               ВСЕ обязательные поля (ИП + банк). 2026-06-03. -->
          <v-alert v-if="!isRequisitesVerified && missingRequisiteFields.length"
            type="warning" variant="tonal" density="compact" class="mb-3"
            icon="mdi-alert-circle-outline">
            <div class="text-body-2">
              <strong>Заполните все реквизиты для верификации.</strong>
              Пока не заполнены все поля ИП и банковские реквизиты, верификация
              <strong>не пройдёт</strong>.
            </div>
            <div class="text-caption mt-1">
              Не хватает: {{ missingRequisiteFields.join(', ') }}.
            </div>
          </v-alert>

          <!-- ИП -->
          <v-card class="ds-card mb-3">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-domain</v-icon>
                Реквизиты ИП
                <v-chip v-if="profile.requisites?.verificationStatus" size="small"
                  :color="verificationColor(profile.requisites.verificationStatus)" variant="tonal">
                  {{ verificationLabel(profile.requisites.verificationStatus) }}
                </v-chip>
              </div>
            </div>
            <div class="ds-card__body">
              <v-alert v-if="profile.requisites?.verificationStatus === 'verified'"
                type="warning" variant="tonal" density="compact" class="mb-3"
                icon="mdi-alert-outline">
                Изменение реквизитов сбросит статус верификации.
              </v-alert>
              <v-alert v-else-if="profile.requisites?.verificationStatus === 'rejected'"
                type="error" variant="tonal" density="compact" class="mb-3"
                icon="mdi-close-octagon-outline">
                Реквизиты отклонены финменеджером. Исправьте данные и сохраните повторно.
              </v-alert>
              <v-alert v-else-if="profile.requisites?.verificationStatus"
                type="warning" variant="tonal" density="compact" class="mb-3"
                icon="mdi-clock-outline">
                <strong>Ожидайте проверки документов.</strong>
                Финменеджер вручную проверяет соответствие УСН.
                До верификации подписание документов и продажа продуктов недоступны.
              </v-alert>
              <!-- Текст и логика как в попапе ввода реквизитов на витрине
                   продуктов (Products.vue): ИНН проверяется через DaData
                   (ЕГРИП/ЕГРЮЛ), остальные данные ИП подтягиваются автоматически. -->
              <p class="text-body-2 mb-3">
                Заполните ИНН — наименование, ОГРНИП и адрес подтянутся из ЕГРИП
                автоматически. После сохранения реквизиты уйдут на
                <strong>ручную проверку</strong> финменеджеру; до верификации
                подписание документов и продажа продуктов недоступны.
              </p>
              <v-row dense>
                <v-col cols="12" sm="6" md="4">
                  <v-text-field v-model="reqForm.inn" label="ИНН ИП" placeholder="12 цифр"
                    :loading="reqInnLookup" @blur="lookupReqInn" @keyup.enter="lookupReqInn" />
                </v-col>
                <v-col cols="12" md="8">
                  <v-text-field v-model="reqForm.individualEntrepreneur" label="Наименование ИП" />
                </v-col>
                <v-col cols="12" v-if="reqInnResult">
                  <v-alert :type="reqInnResult.found ? 'info' : 'warning'" variant="tonal" density="compact">
                    <div class="font-weight-medium">{{ reqInnResult.name || reqInnResult.error || 'Не найдено' }}</div>
                    <div v-if="reqInnResult.fioCheck" class="text-caption">
                      <template v-if="reqInnMatch">✓ ФИО совпадает с профилем.</template>
                      <template v-else>⚠ ФИО в ИП: {{ reqInnResult.fioCheck.actual }} · В профиле: {{ reqInnResult.fioCheck.expected }}.</template>
                    </div>
                    <div v-if="reqInnResult.found" class="text-caption mt-1">
                      Реквизиты будут отправлены на ручную проверку финменеджеру —
                      автоматическое подтверждение режима УСН недоступно.
                    </div>
                  </v-alert>
                </v-col>
                <v-col cols="12" sm="6" md="6">
                  <v-text-field v-model="reqForm.ogrn" label="ОГРН/ОГРНИП" />
                </v-col>
                <v-col cols="12">
                  <v-text-field v-model="reqForm.address" label="Юридический адрес" />
                </v-col>
                <v-col cols="12">
                  <v-checkbox v-model="addressSameAsRegistration"
                    label="Адрес регистрации совпадает с фактическим"
                    density="compact" hide-details />
                </v-col>
                <v-col v-if="!addressSameAsRegistration" cols="12">
                  <v-text-field v-model="reqForm.actualAddress" label="Фактический адрес" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="reqForm.email" label="Email для документов" type="email" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="reqForm.phone" label="Телефон ИП" />
                </v-col>
              </v-row>
              <v-alert v-if="reqMsg" :type="reqMsgType" density="compact" class="mt-3" closable @click:close="reqMsg = ''">
                {{ reqMsg }}
              </v-alert>
            </div>
            <div class="ds-card__actions">
              <v-btn color="primary" :loading="savingReq" prepend-icon="mdi-content-save" @click="saveRequisites">
                Сохранить
              </v-btn>
            </div>
          </v-card>

          <!-- Банк -->
          <v-card class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-bank-outline</v-icon>
                Банковские реквизиты
                <v-chip v-if="profile.bankRequisites?.verificationStatus" size="small"
                  :color="verificationColor(profile.bankRequisites.verificationStatus)" variant="tonal">
                  {{ verificationLabel(profile.bankRequisites.verificationStatus) }}
                </v-chip>
              </div>
            </div>
            <div class="ds-card__body">
              <v-alert v-if="profile.bankRequisites?.verificationStatus === 'verified'"
                type="warning" variant="tonal" density="compact" class="mb-3"
                icon="mdi-alert-outline">
                Изменение банковских реквизитов сбросит статус верификации.
              </v-alert>
              <v-alert v-else-if="profile.bankRequisites?.verificationStatus === 'rejected'"
                type="error" variant="tonal" density="compact" class="mb-3"
                icon="mdi-close-octagon-outline">
                Банковские реквизиты отклонены финменеджером. Исправьте и сохраните повторно.
              </v-alert>
              <v-alert v-else-if="profile.bankRequisites?.verificationStatus"
                type="warning" variant="tonal" density="compact" class="mb-3"
                icon="mdi-clock-outline">
                Банковские реквизиты на ручной проверке. Ожидайте верификации финменеджером.
              </v-alert>
              <v-row dense>
                <v-col cols="12" md="6">
                  <v-text-field v-model="bankForm.bankName" label="Наименование банка" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="bankForm.bankBik" label="БИК" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="bankForm.accountNumber" label="Расчётный счёт" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="bankForm.correspondentAccount" label="Корр. счёт" />
                </v-col>
                <v-col cols="12">
                  <v-text-field v-model="bankForm.beneficiaryName" label="Наименование получателя" />
                </v-col>
              </v-row>
              <v-alert v-if="bankMsg" :type="bankMsgType" density="compact" class="mt-3" closable @click:close="bankMsg = ''">
                {{ bankMsg }}
              </v-alert>
            </div>
            <div class="ds-card__actions">
              <v-btn color="primary" :loading="savingBank" prepend-icon="mdi-content-save" @click="saveBankRequisites">
                Сохранить
              </v-btn>
            </div>
          </v-card>
        </div>

        <!-- ════════════════  БЕЗОПАСНОСТЬ  ════════════════ -->
        <div v-show="tab === 'security'">
          <!-- 2FA -->
          <v-card class="ds-card mb-3">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-shield-key-outline</v-icon>
                Двухфакторная аутентификация
                <v-chip v-if="twoFa.enabled" size="small" color="success" variant="tonal" prepend-icon="mdi-check">
                  Включено
                </v-chip>
              </div>
            </div>
            <div class="ds-card__body">
              <template v-if="twoFa.enabled">
                <v-alert type="success" variant="tonal" density="compact" class="mb-3">
                  2FA включён{{ twoFa.confirmedAt ? ' с ' + fmtShortDate(twoFa.confirmedAt) : '' }}.
                  При входе будем спрашивать код из приложения.
                </v-alert>
                <v-text-field v-model="disablePassword" label="Текущий пароль для отключения"
                  type="password" prepend-inner-icon="mdi-lock-outline" class="mb-2" />
                <v-btn color="error" variant="tonal" :loading="twoFaBusy"
                  prepend-icon="mdi-shield-off-outline" @click="disable2fa">
                  Отключить 2FA
                </v-btn>
              </template>

              <template v-else>
                <div v-if="!twoFaSetup.uri" class="ds-body-m ds-muted mb-3">
                  Защитите аккаунт одноразовыми кодами из Google Authenticator или 1Password.
                  Если потеряете доступ к коду — пишите в техподдержку.
                </div>
                <v-btn v-if="!twoFaSetup.uri" color="primary" :loading="twoFaBusy"
                  prepend-icon="mdi-shield-plus-outline" @click="start2fa">
                  Включить 2FA
                </v-btn>

                <div v-else class="twofa-setup">
                  <ol class="twofa-steps">
                    <li>Установите Google Authenticator / 1Password / Microsoft Authenticator.</li>
                    <li>Отсканируйте QR-код в приложении.</li>
                    <li>Введите 6-значный код из приложения ниже.</li>
                  </ol>
                  <div class="twofa-grid">
                    <div class="twofa-qr">
                      <img :src="qrCodeUrl" alt="QR-код для 2FA" class="qr-img" />
                    </div>
                    <div class="twofa-side">
                      <v-text-field :model-value="twoFaSetup.secret" label="Секрет (если не сканируется)"
                        readonly variant="outlined" density="compact"
                        prepend-inner-icon="mdi-key-variant" hide-details>
                        <template #append-inner>
                          <v-btn icon="mdi-content-copy" size="x-small" variant="text"
                            @click="copyToClipboard(twoFaSetup.secret)" />
                        </template>
                      </v-text-field>
                      <v-text-field v-model="totpConfirm" label="6-значный код"
                        maxlength="6" inputmode="numeric"
                        prepend-inner-icon="mdi-shield-key-outline"
                        class="mt-2" />
                      <v-btn block color="primary" :loading="twoFaBusy"
                        prepend-icon="mdi-check" @click="confirm2fa">
                        Подтвердить и включить
                      </v-btn>
                    </div>
                  </div>
                </div>
              </template>
            </div>
          </v-card>

          <!-- Смена пароля -->
          <v-card class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-key-variant</v-icon>
                Смена пароля
              </div>
            </div>
            <div class="ds-card__body">
              <v-row dense>
                <v-col cols="12" md="4">
                  <v-text-field v-model="pwd.current_password" label="Текущий пароль"
                    type="password" prepend-inner-icon="mdi-lock-outline" />
                </v-col>
                <v-col cols="12" md="4">
                  <v-text-field v-model="pwd.password" label="Новый пароль"
                    type="password" prepend-inner-icon="mdi-lock-plus-outline" />
                </v-col>
                <v-col cols="12" md="4">
                  <v-text-field v-model="pwd.password_confirmation" label="Подтверждение"
                    type="password" prepend-inner-icon="mdi-lock-check-outline" />
                </v-col>
              </v-row>
              <v-alert v-if="pwdMsg" :type="pwdMsgType" density="compact" class="mt-3" closable @click:close="pwdMsg = ''">
                {{ pwdMsg }}
              </v-alert>
            </div>
            <div class="ds-card__actions">
              <v-btn color="primary" :loading="savingPwd" prepend-icon="mdi-key" @click="changePassword">
                Сменить пароль
              </v-btn>
            </div>
          </v-card>
        </div>

        <!-- ════════════════  УВЕДОМЛЕНИЯ  ════════════════ -->
        <div v-show="tab === 'notifications'">
          <v-card class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-bell-outline</v-icon>
                Уведомления
              </div>
            </div>
            <div class="ds-card__body">
              <div class="ds-body-m ds-muted mb-4">
                Настройки каналов уведомлений и звуковых сигналов.
              </div>
              <v-alert type="info" variant="tonal" density="compact" icon="mdi-tools">
                Раздел в разработке. Звук уведомлений сейчас управляется из иконки колокольчика
                в шапке (вверху страницы → меню «Уведомления» → переключатель «Звук»).
              </v-alert>
            </div>
          </v-card>
        </div>

        <!-- ════════════════  TELEGRAM-BOT  ════════════════ -->
        <div v-show="tab === 'telegram'">
          <v-card class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-send</v-icon>
                Telegram-bot
                <v-chip v-if="telegram.linked" size="small" color="success" variant="tonal" prepend-icon="mdi-check">
                  Подключён
                </v-chip>
              </div>
            </div>
            <div class="ds-card__body">
              <template v-if="!telegram.enabled">
                <v-alert type="info" variant="tonal" density="compact" icon="mdi-information-outline">
                  Интеграция с Telegram отключена администратором.
                </v-alert>
              </template>

              <template v-else-if="telegram.linked">
                <v-alert type="success" variant="tonal" density="compact" class="mb-3"
                  icon="mdi-check-circle">
                  Аккаунт привязан к Telegram. Сюда будут приходить уведомления.
                </v-alert>
                <div class="d-flex ga-2 flex-wrap">
                  <v-btn variant="tonal" prepend-icon="mdi-send-check-outline"
                    :loading="telegramBusy" @click="sendTestTelegram">
                    Отправить тест
                  </v-btn>
                  <v-btn variant="tonal" color="error" prepend-icon="mdi-link-off"
                    :loading="telegramBusy" @click="unlinkTelegram">
                    Отвязать
                  </v-btn>
                </div>
              </template>

              <template v-else-if="!tgLink">
                <div class="ds-body-m mb-3">
                  Привязка через бота
                  <a v-if="telegram.bot_username"
                    :href="`https://t.me/${telegram.bot_username}`" target="_blank"
                    class="tg-bot-link">
                    @{{ telegram.bot_username }}
                  </a>:
                  жмёте кнопку → открывается Telegram с уже введённой командой
                  <code class="tg-code">/start</code>. Просто подтвердите — аккаунт привяжется автоматически.
                </div>
                <v-btn color="primary" prepend-icon="mdi-send" :loading="telegramBusy"
                  @click="startTelegramLink">Привязать через бота</v-btn>
              </template>

              <template v-else>
                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                  <div class="font-weight-medium mb-1">Откройте бота в Telegram и нажмите Start.</div>
                  <div class="text-caption">Ссылка действует 15 минут. После старта вернитесь сюда — статус обновится сам.</div>
                </v-alert>
                <div class="d-flex ga-2 flex-wrap mb-2">
                  <v-btn color="primary" prepend-icon="mdi-open-in-new"
                    :href="tgLink" target="_blank" rel="noopener">
                    Открыть в Telegram
                  </v-btn>
                  <v-btn variant="text" prepend-icon="mdi-refresh"
                    :loading="telegramBusy" @click="checkTelegramLink">Проверить статус</v-btn>
                  <v-btn variant="text" color="grey" @click="tgLink = null; tgToken = null;">Отменить</v-btn>
                </div>
                <div v-if="tgPolling" class="ds-body-s ds-muted d-flex align-center ga-2">
                  <v-progress-circular indeterminate size="14" width="2" />
                  Ожидаем подтверждения в Telegram…
                </div>
              </template>
            </div>
          </v-card>
        </div>

        <!-- ════════════════  РЕФЕРАЛЬНЫЕ ССЫЛКИ  ════════════════ -->
        <div v-show="tab === 'referral' && !isEmployee">
          <v-card class="ds-card">
            <div class="ds-card__head">
              <div class="ds-title-l d-flex align-center ga-2">
                <v-icon color="primary">mdi-link-variant</v-icon>
                Реферальная ссылка
              </div>
            </div>
            <div class="ds-card__body">
              <template v-if="canInvite && profile.referral?.referralCode">
                <v-row dense>
                  <v-col cols="12" md="6">
                    <v-text-field :model-value="profile.referral.referralCode" label="Реферальный код"
                      readonly prepend-inner-icon="mdi-tag-outline">
                      <template #append-inner>
                        <v-btn icon="mdi-content-copy" size="x-small" variant="text"
                          @click="copyToClipboard(profile.referral.referralCode)" />
                      </template>
                    </v-text-field>
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-text-field :model-value="profile.referral.referralLink" label="Ссылка для приглашения"
                      readonly prepend-inner-icon="mdi-link">
                      <template #append-inner>
                        <v-btn icon="mdi-content-copy" size="x-small" variant="text"
                          @click="copyToClipboard(profile.referral.referralLink)" />
                      </template>
                    </v-text-field>
                  </v-col>
                </v-row>
                <v-alert v-if="copied" type="success" variant="tonal" density="compact" class="mt-3">
                  Скопировано в буфер обмена
                </v-alert>
              </template>
              <v-alert v-else type="info" variant="tonal" density="compact" icon="mdi-information-outline">
                Реферальные ссылки доступны только для партнёров со статусом «Активен».
              </v-alert>
            </div>
          </v-card>
        </div>

      </section>
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position: fixed; top: 0; left: 0; right: 0; z-index: 2000; height: 3px" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';
import { useSnackbar } from '../composables/useSnackbar';

const route = useRoute();

const { showError, showSuccess } = useSnackbar();

const auth = useAuthStore();
const isEmployee = computed(() => auth.isStaff && !auth.isConsultant);
// Подписи недостающих полей профиля (для жёлтой плашки активного ФК).
const profileMissingLabels = computed(() =>
  (auth.user?.profileMissing || []).map(m => m.label).join(', ')
);
const canInvite = computed(() => profile.value?.referral?.canInvite ?? false);
// Все документы обязательного флоу акцепта (бэкенд уже фильтрует по
// in_acceptance_flow). Показываем со ссылкой и статусом подписания.
const docsList = computed(() =>
  profile.value?.signedDocuments || []
);

const loading = ref(true);
const tab = ref('info');
const profile = ref({});
const avatarInput = ref(null);
const avatarUploading = ref(false);

async function onAvatarPick(e) {
  const file = e.target?.files?.[0];
  if (!file) return;
  avatarUploading.value = true;
  try {
    const fd = new FormData();
    fd.append('avatar', file);
    await api.post('/profile/avatar', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    await loadProfile();
    showSuccess?.('Фото обновлено');
  } catch (err) {
    showError?.(err?.response?.data?.message || 'Не удалось загрузить фото');
  } finally {
    avatarUploading.value = false;
    if (avatarInput.value) avatarInput.value.value = '';
  }
}

const saving = ref(false);
const saveMsg = ref('');
const saveMsgType = ref('success');
const savingPwd = ref(false);
const pwdMsg = ref('');
const pwdMsgType = ref('success');
const savingReq = ref(false);
const reqMsg = ref('');
const reqMsgType = ref('success');
// DaData-проверка ИНН (как в попапе Products.vue).
const reqInnLookup = ref(false);
const reqInnResult = ref(null);
const reqInnMatch = computed(() => reqInnResult.value?.fioCheck?.match ?? null);
const savingBank = ref(false);
const bankMsg = ref('');
const bankMsgType = ref('success');
const copied = ref(false);
const docFiles = ref({});
const docUploading = ref({});
const docMsg = ref('');
const docMsgType = ref('success');
const uploadedDocs = ref([]);
const addressSameAsRegistration = ref(true);

const documentSlots = [
  { label: 'Паспорт (разворот с фото)', type: 'passportPage1' },
  { label: 'Паспорт (регистрация)', type: 'passportPage2' },
];

function isDocUploaded(type) {
  return uploadedDocs.value.some(d => d.type === type);
}

async function loadDocuments() {
  try {
    const { data } = await api.get('/documents');
    uploadedDocs.value = Array.isArray(data?.documents) ? data.documents : (Array.isArray(data) ? data : []);
  } catch {
    uploadedDocs.value = [];
  }
}

async function uploadDocument(type) {
  docUploading.value[type] = true;
  docMsg.value = '';
  try {
    const fd = new FormData();
    fd.append('file', docFiles.value[type]);
    fd.append('type', type);
    await api.post('/documents/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    docMsg.value = 'Документ загружен';
    docMsgType.value = 'success';
    docFiles.value[type] = null;
    await loadDocuments();
  } catch (e) {
    docMsg.value = e.response?.data?.message || 'Ошибка загрузки';
    docMsgType.value = 'error';
  }
  docUploading.value[type] = false;
}

const genderOptions = [
  { title: 'Мужской', value: 'male' },
  { title: 'Женский', value: 'female' },
];

// Страны грузим из справочника (таблица `country`), а не хардкодим — см.
// GET /profile/countries. Минимальный фоллбэк на случай сбоя запроса.
const countryOptions = ref(['Россия', 'Казахстан', 'Беларусь', 'Украина']);
async function loadCountries() {
  try {
    const { data } = await api.get('/profile/countries');
    if (Array.isArray(data) && data.length) countryOptions.value = data;
  } catch { /* оставляем фоллбэк */ }
}
const cityItems = ref([]);
const cityLoading = ref(false);
let cityTimer = null;

function activityColor(id) {
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'error';     // Терминирован
  if (id === 5) return 'error';     // Исключен
  return 'grey';
}

function fmtShortDate(d) {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date.getTime())) return '';
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// Серверный поиск городов через DaData (debounce 300мс, мин. 2 символа).
function onCitySearch(q) {
  q = String(q || '').trim();
  if (cityTimer) clearTimeout(cityTimer);
  if (q.length < 2) return;
  cityTimer = setTimeout(async () => {
    cityLoading.value = true;
    try {
      const { data } = await api.get('/profile/cities', { params: { q } });
      cityItems.value = Array.isArray(data) ? data : [];
    } catch {
      cityItems.value = [];
    }
    cityLoading.value = false;
  }, 300);
}

// При выборе города — если страна ещё не указана, подставим из DaData.
function onCityPicked(value) {
  if (!value) return;
  const picked = cityItems.value.find(c => c.value === value);
  if (picked?.country && !form.value.country) {
    form.value.country = picked.country;
  }
}

// Город из профиля — строка; чтобы autocomplete её показал, кладём как
// единственный начальный item (без запроса к DaData).
function seedCurrentCity(name) {
  cityItems.value = name ? [{ title: name, value: name }] : [];
}

const form = ref({ firstName: '', lastName: '', patronymic: '', position: '', phone: '', telegram: '', gender: '', birthDate: '', email: '', country: '', city: '' });
const pwd = ref({ current_password: '', password: '', password_confirmation: '' });
const reqForm = ref({ individualEntrepreneur: '', inn: '', ogrn: '', address: '', registrationDate: '', email: '', phone: '' });
const bankForm = ref({ bankName: '', bankBik: '', accountNumber: '', correspondentAccount: '', beneficiaryName: '' });

// Обязательные для верификации поля (2026-06-03): без полного заполнения
// верификация не пройдёт. Наименование/ИНН подтягиваются по ИНН, Получатель
// проставляет бэкенд — их в список не включаем.
const requiredRequisiteFields = [
  { key: 'ogrn', label: 'ОГРН/ОГРНИП', form: 'req' },
  { key: 'address', label: 'Юридический адрес', form: 'req' },
  { key: 'email', label: 'Email для документов', form: 'req' },
  { key: 'phone', label: 'Телефон ИП', form: 'req' },
  { key: 'bankName', label: 'Наименование банка', form: 'bank' },
  { key: 'bankBik', label: 'БИК', form: 'bank' },
  { key: 'accountNumber', label: 'Расчётный счёт', form: 'bank' },
  { key: 'correspondentAccount', label: 'Корр. счёт', form: 'bank' },
];
const missingRequisiteFields = computed(() =>
  requiredRequisiteFields
    .filter(f => {
      const src = f.form === 'bank' ? bankForm.value : reqForm.value;
      return !String(src?.[f.key] ?? '').trim();
    })
    .map(f => f.label)
);
const isRequisitesVerified = computed(() =>
  profile.value?.requisites?.verificationStatus === 'verified'
);

const initials = computed(() => {
  const u = profile.value.user;
  return `${u?.firstName?.[0] || ''}${u?.lastName?.[0] || ''}`.toUpperCase();
});

// Лейбл роли для staff — берём первую из comma-separated списка ролей и
// переводим на русский. Список синхронизирован с TicketService::CATEGORIES
// и AdminLayout sidebar.
const ROLE_LABELS = {
  admin: 'Администратор',
  backoffice: 'Бэк-офис',
  support: 'Техподдержка',
  finance: 'Финансовый менеджер',
  head: 'Руководитель',
  calculations: 'Расчёты',
  corrections: 'Корректировки',
  education: 'Куратор обучения',
};
const roleLabel = computed(() => {
  const role = auth.user?.role || '';
  const first = role.split(',').map(r => r.trim()).find(r => ROLE_LABELS[r]);
  return first ? ROLE_LABELS[first] : (isEmployee.value ? 'Сотрудник' : '');
});

// Чипы статуса для hero — для partner показываем activity-status + дата
// окончания периода + активационный дедлайн. Для staff — пусто (роль уже
// в подзаголовке).
const heroChips = computed(() => {
  if (isEmployee.value) {
    const chips = [];
    if (profile.value.user?.dateRegister) {
      chips.push({
        text: `с ${fmtShortDate(profile.value.user.dateRegister)}`,
        color: 'default',
        variant: 'tonal',
      });
    }
    return chips;
  }
  const si = profile.value.statusInfo;
  if (!si) return [];
  const out = [];
  if (si.activityName) {
    out.push({
      text: si.activityName,
      color: activityColor(si.activityId),
      variant: 'flat',
    });
  }
  if (si.yearPeriodEnd) {
    out.push({
      text: `до ${fmtShortDate(si.yearPeriodEnd)}`,
      color: 'default',
      variant: 'tonal',
    });
  }
  if (si.activationDeadline) {
    out.push({
      text: `Активация до ${fmtShortDate(si.activationDeadline)}`,
      color: 'warning',
      variant: 'tonal',
    });
  }
  return out;
});

// Меню разделов профиля. Состав зависит от роли — partner получает
// Документы/Реквизиты/Реферальные сверх базового набора.
const navItems = computed(() => {
  const items = [
    { value: 'info', icon: 'mdi-account-circle-outline',
      title: isEmployee.value ? 'Информация о сотруднике' : 'Личные данные' },
  ];
  if (!isEmployee.value) {
    items.push({ value: 'documents', icon: 'mdi-file-document-outline', title: 'Документы' });
    items.push({ value: 'requisites', icon: 'mdi-credit-card-outline', title: 'Реквизиты' });
  }
  items.push({ value: 'security', icon: 'mdi-shield-lock-outline', title: 'Безопасность' });
  items.push({ value: 'notifications', icon: 'mdi-bell-outline', title: 'Уведомления' });
  items.push({ value: 'telegram', icon: 'mdi-send', title: 'Telegram-bot' });
  if (!isEmployee.value && canInvite.value) {
    items.push({ value: 'referral', icon: 'mdi-link-variant', title: 'Реферальные ссылки' });
  }
  return items;
});

function verificationColor(status) {
  if (status === 'verified') return 'success';
  if (status === 'rejected') return 'error';
  return 'warning';
}

function verificationLabel(status) {
  if (status === 'verified') return 'Подтверждено';
  if (status === 'rejected') return 'Отклонено';
  return 'На проверке';
}

async function loadProfile() {
  loading.value = true;
  try {
    const { data } = await api.get('/profile');
    profile.value = data;
    const u = data.user || {};
    form.value = {
      firstName: u.firstName || '', lastName: u.lastName || '', patronymic: u.patronymic || '',
      position: u.position || '',
      phone: u.phone || '', telegram: u.telegram || '', gender: u.gender || '',
      birthDate: u.birthDate ? u.birthDate.split('T')[0] : '',
      email: u.email || '', country: u.country || '', city: u.city || '',
    };
    seedCurrentCity(form.value.city);
    const r = data.requisites || {};
    reqForm.value = {
      individualEntrepreneur: r.individualEntrepreneur || '', inn: r.inn || '',
      ogrn: r.ogrn || '', address: r.address || '', actualAddress: r.actualAddress || '',
      registrationDate: r.registrationDate || '',
      email: r.email || '', phone: r.phone || '',
    };
    addressSameAsRegistration.value = !r.actualAddress;
    const b = data.bankRequisites || {};
    bankForm.value = {
      bankName: b.bankName || '', bankBik: b.bankBik || '',
      accountNumber: b.accountNumber || '', correspondentAccount: b.correspondentAccount || '',
      beneficiaryName: b.beneficiaryName || '',
    };
  } catch {}
  loading.value = false;
}

async function saveProfile() {
  saving.value = true;
  saveMsg.value = '';
  try {
    const payload = {
      phone: form.value.phone, telegram: form.value.telegram,
      gender: form.value.gender, birthDate: form.value.birthDate,
      email: form.value.email, country: form.value.country, city: form.value.city,
    };
    if (isEmployee.value) {
      payload.firstName = form.value.firstName;
      payload.lastName = form.value.lastName;
      payload.patronymic = form.value.patronymic;
      payload.position = form.value.position;
    }
    await api.put('/profile', payload);
    saveMsg.value = 'Данные сохранены';
    saveMsgType.value = 'success';
    // Обновляем auth.user, чтобы баннер «заполните профиль» пересчитался.
    auth.fetchUser();
  } catch (e) {
    saveMsg.value = e.response?.data?.message || 'Ошибка сохранения';
    saveMsgType.value = 'error';
  }
  saving.value = false;
}

async function changePassword() {
  savingPwd.value = true;
  pwdMsg.value = '';
  try {
    await api.post('/profile/password', pwd.value);
    pwdMsg.value = 'Пароль успешно изменён';
    pwdMsgType.value = 'success';
    pwd.value = { current_password: '', password: '', password_confirmation: '' };
  } catch (e) {
    pwdMsg.value = e.response?.data?.message || 'Ошибка смены пароля';
    pwdMsgType.value = 'error';
  }
  savingPwd.value = false;
}

// Проверка ИНН через DaData (ЕГРИП/ЕГРЮЛ) + автозаполнение полей ИП.
// Тот же эндпоинт, что и попап на витрине продуктов (/requisites/check-inn).
async function lookupReqInn() {
  const clean = String(reqForm.value.inn || '').replace(/\D/g, '');
  if (clean.length !== 10 && clean.length !== 12) return;
  reqInnLookup.value = true;
  try {
    const { data } = await api.post('/requisites/check-inn', { inn: clean });
    reqInnResult.value = data;
    if (data.found) {
      // Данные ИП подтягиваются из ЕГРИП (наименование/ОГРНИП/адрес/дата).
      if (data.name) reqForm.value.individualEntrepreneur = data.name;
      if (data.ogrn) reqForm.value.ogrn = data.ogrn;
      if (data.address) reqForm.value.address = data.address;
      if (data.registrationDate) reqForm.value.registrationDate = data.registrationDate;
    }
  } catch (e) {
    reqInnResult.value = { found: false, error: e.response?.data?.message || 'Не удалось проверить ИНН' };
  }
  reqInnLookup.value = false;
}

async function saveRequisites() {
  savingReq.value = true;
  reqMsg.value = '';
  try {
    await api.put('/profile/requisites', reqForm.value);
    reqMsg.value = 'Реквизиты сохранены';
    reqMsgType.value = 'success';
    loadProfile();
    auth.fetchUser();
  } catch (e) {
    reqMsg.value = e.response?.data?.message || 'Ошибка сохранения';
    reqMsgType.value = 'error';
  }
  savingReq.value = false;
}

async function saveBankRequisites() {
  savingBank.value = true;
  bankMsg.value = '';
  try {
    await api.put('/profile/bank-requisites', bankForm.value);
    bankMsg.value = 'Банковские реквизиты сохранены';
    bankMsgType.value = 'success';
    loadProfile();
    auth.fetchUser();
  } catch (e) {
    bankMsg.value = e.response?.data?.message || 'Ошибка сохранения';
    bankMsgType.value = 'error';
  }
  savingBank.value = false;
}

function copyToClipboard(text) {
  navigator.clipboard.writeText(text);
  copied.value = true;
  setTimeout(() => { copied.value = false; }, 2000);
}

// Deep-link на конкретную вкладку (?tab=requisites из баннеров кабинета).
// Watcher (а не только onMounted) нужен, чтобы переключение срабатывало,
// когда партнёр уже на /profile и кликает ссылку из баннера: путь тот же,
// компонент не перемонтируется — реагируем на смену query.tab.
const validTabs = ['info', 'documents', 'requisites', 'security', 'notifications', 'telegram', 'referral'];
watch(() => route.query.tab, (t) => {
  if (t && validTabs.includes(t)) tab.value = t;
}, { immediate: true });

onMounted(() => {
  loadProfile();
  loadCountries();
  loadDocuments();
  load2faStatus();
  loadTelegram();
});

// === Telegram ===
const telegram = ref({ enabled: false, linked: false, chat_id: null, bot_username: null });
const telegramBusy = ref(false);
const tgLink = ref(null);
const tgToken = ref(null);
const tgPolling = ref(false);
let tgPollTimer = null;

async function loadTelegram() {
  try {
    const { data } = await api.get('/telegram/status');
    telegram.value = data;
  } catch {}
}

async function startTelegramLink() {
  telegramBusy.value = true;
  try {
    const { data } = await api.post('/telegram/start-link');
    tgToken.value = data.token;
    tgLink.value = data.deeplink;
    tgPolling.value = true;
    if (tgPollTimer) clearInterval(tgPollTimer);
    tgPollTimer = setInterval(checkTelegramLink, 3000);
    setTimeout(() => { if (tgPollTimer) { clearInterval(tgPollTimer); tgPollTimer = null; tgPolling.value = false; } }, 15 * 60 * 1000);
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  telegramBusy.value = false;
}

async function checkTelegramLink() {
  if (!tgToken.value) return;
  try {
    const { data } = await api.get('/telegram/check-link', { params: { token: tgToken.value } });
    if (data.linked) {
      stopTgPoll();
      tgLink.value = null;
      tgToken.value = null;
      await loadTelegram();
      showSuccess('Telegram привязан!');
    } else if (data.expired) {
      stopTgPoll();
      tgLink.value = null;
      tgToken.value = null;
      showError('Ссылка просрочена. Сгенерируйте новую.');
    }
  } catch {}
}

function stopTgPoll() {
  if (tgPollTimer) { clearInterval(tgPollTimer); tgPollTimer = null; }
  tgPolling.value = false;
}

async function unlinkTelegram() {
  telegramBusy.value = true;
  try {
    await api.post('/telegram/unlink');
    await loadTelegram();
    showSuccess('Отвязано');
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  telegramBusy.value = false;
}

async function sendTestTelegram() {
  telegramBusy.value = true;
  try {
    const { data } = await api.post('/telegram/test');
    if (data.sent) showSuccess('Тестовое сообщение отправлено');
    else showError('Не удалось отправить — проверьте что бот настроен');
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  telegramBusy.value = false;
}

// === 2FA ===
const twoFa = ref({ enabled: false, confirmedAt: null });
const twoFaSetup = ref({ uri: '', secret: '' });
const totpConfirm = ref('');
const disablePassword = ref('');
const twoFaBusy = ref(false);

const qrCodeUrl = computed(() => twoFaSetup.value.uri
  ? `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(twoFaSetup.value.uri)}`
  : '');

async function load2faStatus() {
  try {
    const { data } = await api.get('/2fa/status');
    twoFa.value = { enabled: data.enabled, confirmedAt: data.confirmed_at };
  } catch {}
}
async function start2fa() {
  twoFaBusy.value = true;
  try {
    const { data } = await api.post('/2fa/setup');
    twoFaSetup.value = { uri: data.otpauth_uri, secret: data.secret };
  } catch (e) { showError?.(e.response?.data?.message || 'Ошибка'); }
  twoFaBusy.value = false;
}
async function confirm2fa() {
  if (!/^\d{6}$/.test(totpConfirm.value)) { showError?.('Введите 6 цифр'); return; }
  twoFaBusy.value = true;
  try {
    await api.post('/2fa/confirm', { code: totpConfirm.value });
    twoFaSetup.value = { uri: '', secret: '' };
    totpConfirm.value = '';
    await load2faStatus();
    showSuccess?.('2FA включён');
  } catch (e) { showError?.(e.response?.data?.message || 'Неверный код'); }
  twoFaBusy.value = false;
}
async function disable2fa() {
  if (!disablePassword.value) { showError?.('Введите текущий пароль'); return; }
  twoFaBusy.value = true;
  try {
    await api.post('/2fa/disable', { password: disablePassword.value });
    disablePassword.value = '';
    await load2faStatus();
    showSuccess?.('2FA отключён');
  } catch (e) { showError?.(e.response?.data?.message || 'Ошибка'); }
  twoFaBusy.value = false;
}
</script>

<style scoped>
/* ──────────────────────  ВЁРСТКА ПРОФИЛЯ  ────────────────────── */
.profile-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* HERO ─ карточка-приветствие сверху */
.profile-hero {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 20px 24px !important;
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08));
  border-radius: var(--ds-radius-lg, 12px) !important;
  background: rgb(var(--v-theme-surface));
}
.profile-hero__avatar-wrap {
  flex-shrink: 0;
}
.profile-hero__avatar {
  box-shadow: 0 0 0 4px var(--ds-primary-soft, rgba(var(--v-theme-primary), 0.10));
}
.profile-hero__initials {
  font: 600 22px var(--ds-font-sans);
  color: rgb(var(--v-theme-on-primary));
  letter-spacing: 0.5px;
}
.profile-hero__main {
  flex: 1;
  min-width: 0;
}
.profile-hero__name {
  font: var(--ds-type-headline-s);
  letter-spacing: -0.01em;
  color: rgb(var(--v-theme-on-surface));
}
.profile-hero__sub {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 4px;
  font: var(--ds-type-body-m);
  color: var(--ds-on-surface-muted, rgba(var(--v-theme-on-surface), 0.6));
}
.profile-hero__dot {
  opacity: 0.5;
}
.profile-hero__chips {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.profile-hero__actions {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

/* ── двухколоночный layout ── */
.profile-layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 16px;
  align-items: start;
}

/* NAV ─ боковое меню разделов */
.profile-nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px;
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08));
  border-radius: var(--ds-radius-lg, 12px);
  background: rgb(var(--v-theme-surface));
  position: sticky;
  top: 80px;
}
.profile-nav__item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: var(--ds-radius-md, 8px);
  background: transparent;
  border: none;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
  font: var(--ds-type-body-m);
  font-weight: 500;
  text-align: left;
  transition: background var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease),
              color var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease);
}
.profile-nav__item:hover {
  background: var(--ds-overlay, rgba(var(--v-theme-on-surface), 0.04));
}
.profile-nav__item--active {
  background: var(--ds-primary-soft, rgba(var(--v-theme-primary), 0.12));
  color: rgb(var(--v-theme-primary));
}
.profile-nav__item--active .profile-nav__icon {
  color: rgb(var(--v-theme-primary));
}
.profile-nav__icon {
  flex-shrink: 0;
  color: var(--ds-on-surface-muted, rgba(var(--v-theme-on-surface), 0.55));
}

/* CONTENT ─ карточки разделов */
.profile-content {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

/* DS-карточка с тонким outline вместо тяжёлой elevation */
.ds-card {
  border-radius: var(--ds-radius-lg, 12px) !important;
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08)) !important;
  background: rgb(var(--v-theme-surface)) !important;
  overflow: hidden;
}
.ds-card__head {
  padding: 16px 20px 12px;
  border-bottom: 1px solid var(--ds-outline-soft, rgba(var(--v-theme-on-surface), 0.04));
}
.ds-card__body {
  padding: 16px 20px 20px;
}
.ds-card__actions {
  padding: 12px 20px 16px;
  border-top: 1px solid var(--ds-outline-soft, rgba(var(--v-theme-on-surface), 0.04));
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

/* ── ds-title-l — общий для всех section-заголовков ── */
.ds-title-l {
  font: var(--ds-type-title-l);
  letter-spacing: -0.01em;
  color: rgb(var(--v-theme-on-surface));
}
.ds-title-s { font: var(--ds-type-title-s); }
.ds-body-m { font: var(--ds-type-body-m); }
.ds-body-s { font: var(--ds-type-body-s); }
.ds-muted { color: var(--ds-on-surface-muted, rgba(var(--v-theme-on-surface), 0.55)); }

/* ── doc-slot ── */
.doc-slot {
  border-radius: var(--ds-radius-md, 8px) !important;
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08)) !important;
  background: var(--ds-surface-container-low, transparent) !important;
}

/* ── 2FA setup ── */
.twofa-steps {
  margin: 0 0 16px;
  padding-left: 20px;
  color: rgb(var(--v-theme-on-surface));
  line-height: 1.7;
}
.twofa-grid {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 18px;
  align-items: flex-start;
}
.twofa-qr {
  padding: 10px;
  background: #fff;
  border-radius: var(--ds-radius-md, 8px);
  border: 1px solid var(--ds-outline-variant, rgba(0, 0, 0, 0.08));
  width: 200px;
  height: 200px;
  display: grid;
  place-items: center;
}
.qr-img {
  width: 180px;
  height: 180px;
  display: block;
}

/* ── telegram-bot ── */
.tg-bot-link {
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
  text-decoration: none;
}
.tg-bot-link:hover { text-decoration: underline; }
.tg-code {
  font-family: var(--ds-font-mono);
  font-size: 12px;
  background: var(--ds-overlay, rgba(var(--v-theme-on-surface), 0.06));
  padding: 1px 5px;
  border-radius: var(--ds-radius-xs, 4px);
}

/* ── РЕСПОНСИВ ── */
@media (max-width: 960px) {
  .profile-layout {
    grid-template-columns: 1fr;
  }
  .profile-nav {
    position: static;
    flex-direction: row;
    flex-wrap: wrap;
    overflow-x: auto;
  }
  .profile-nav__item {
    flex: 1 1 auto;
    min-width: 140px;
  }
  .profile-hero {
    flex-wrap: wrap;
    gap: 14px;
  }
  .twofa-grid {
    grid-template-columns: 1fr;
  }
  .twofa-qr {
    margin: 0 auto;
  }
}
@media (max-width: 600px) {
  .profile-hero {
    padding: 16px !important;
  }
  .profile-hero__actions {
    width: 100%;
  }
  .profile-hero__actions .v-btn {
    width: 100%;
  }
}
</style>
