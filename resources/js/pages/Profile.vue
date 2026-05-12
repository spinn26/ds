<template>
  <div>
    <PageHeader title="Профиль" icon="mdi-account" />

    <!-- Per spec ✅Профиль: для сотрудника финансовые блоки скрыты,
         ФИО редактируемо, есть поле «Должность». -->
    <v-tabs v-model="tab" color="primary" class="mb-4" grow>
      <v-tab value="info">{{ isEmployee ? 'Информация о сотруднике' : 'Информация о партнере' }}</v-tab>
      <v-tab v-if="!isEmployee" value="requisites">Реквизиты и документы для выплат</v-tab>
      <v-tab v-if="!isEmployee && canInvite" value="referral">Реферальные ссылки</v-tab>
      <v-tab value="security">Безопасность</v-tab>
    </v-tabs>

    <v-tabs-window v-model="tab">
      <!-- Section 1: Partner Info -->
      <v-tabs-window-item value="info">
        <v-card class="pa-4 mb-4">
          <div class="d-flex align-center ga-4 mb-4">
            <v-avatar color="primary" size="72">
              <v-img v-if="profile.user?.avatar" :src="profile.user.avatar" />
              <span v-else class="text-h5 text-white">{{ initials }}</span>
            </v-avatar>
            <div>
              <div class="text-h6">{{ profile.user?.lastName }} {{ profile.user?.firstName }} {{ profile.user?.patronymic }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ profile.user?.email }}</div>
              <div v-if="profile.statusInfo" class="d-flex align-center ga-2 mt-1">
                <v-chip size="small" :color="activityColor(profile.statusInfo.activityId)">
                  {{ profile.statusInfo.activityName }}
                </v-chip>
                <span v-if="profile.statusInfo.yearPeriodEnd" class="text-caption text-medium-emphasis">
                  до {{ fmtShortDate(profile.statusInfo.yearPeriodEnd) }}
                </span>
                <span v-if="profile.statusInfo.activationDeadline" class="text-caption text-medium-emphasis">
                  Активация до {{ fmtShortDate(profile.statusInfo.activationDeadline) }}
                </span>
              </div>
            </div>
          </div>

          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.lastName" label="Фамилия"
                :disabled="!isEmployee"
                :prepend-inner-icon="!isEmployee ? 'mdi-lock' : undefined"
                :hint="!isEmployee ? 'Изменение возможно только через техподдержку' : undefined"
                persistent-hint />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.firstName" label="Имя"
                :disabled="!isEmployee"
                :prepend-inner-icon="!isEmployee ? 'mdi-lock' : undefined"
                :hint="!isEmployee ? 'Изменение возможно только через техподдержку' : undefined"
                persistent-hint />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.patronymic" label="Отчество"
                :disabled="!isEmployee"
                :prepend-inner-icon="!isEmployee ? 'mdi-lock' : undefined"
                :hint="!isEmployee ? 'Изменение возможно только через техподдержку' : undefined"
                persistent-hint />
            </v-col>
            <v-col v-if="isEmployee" cols="12" sm="4">
              <v-text-field v-model="form.position" label="Должность"
                prepend-inner-icon="mdi-briefcase" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.birthDate" label="Дата рождения" type="date" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="form.gender" label="Пол" :items="genderOptions" clearable />
            </v-col>
            <v-col cols="12" sm="4">
              <v-autocomplete v-model="form.country" :items="countryOptions" label="Страна" clearable />
            </v-col>
            <v-col cols="12" sm="4">
              <v-combobox v-model="form.city" :items="cityOptions" label="Город" clearable />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.email" label="Email" type="email" prepend-inner-icon="mdi-email" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.phone" label="Телефон" prepend-inner-icon="mdi-phone" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.telegram" label="Telegram" prepend-inner-icon="mdi-send" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="saving" @click="saveProfile" class="mt-2" prepend-icon="mdi-content-save">Сохранить</v-btn>
          <v-alert v-if="saveMsg" :type="saveMsgType" density="compact" class="mt-3" closable @click:close="saveMsg = ''">{{ saveMsg }}</v-alert>
        </v-card>

        <!-- Signed Documents — только для партнёров per spec ✅Профиль §1 Блок 2 -->
        <v-card v-if="!isEmployee && profile.signedDocuments?.length" class="pa-4 mb-4">
          <div class="text-subtitle-1 font-weight-bold mb-2">Подписанные документы</div>
          <v-list density="compact">
            <v-list-item v-for="doc in profile.signedDocuments" :key="doc.id"
              :href="doc.url || undefined" :target="doc.url ? '_blank' : undefined"
              prepend-icon="mdi-file-check">
              <v-list-item-title>{{ doc.name }}</v-list-item-title>
              <v-list-item-subtitle>Подписано: {{ doc.signedAt }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </v-card>

        <!-- Password Change -->
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-2">Смена пароля</div>
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field v-model="pwd.current_password" label="Текущий пароль" type="password" prepend-inner-icon="mdi-lock" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="pwd.password" label="Новый пароль" type="password" prepend-inner-icon="mdi-lock-reset" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="pwd.password_confirmation" label="Подтверждение" type="password" prepend-inner-icon="mdi-lock-check" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="savingPwd" @click="changePassword" class="mt-2" prepend-icon="mdi-key">Сменить пароль</v-btn>
          <v-alert v-if="pwdMsg" :type="pwdMsgType" density="compact" class="mt-3" closable @click:close="pwdMsg = ''">{{ pwdMsg }}</v-alert>
        </v-card>
      </v-tabs-window-item>

      <!-- Section 2: Requisites and documents for payments -->
      <v-tabs-window-item value="requisites">
        <!-- Documents -->
        <v-card class="pa-4 mb-4">
          <div class="d-flex align-center ga-2 mb-3">
            <v-icon color="primary">mdi-file-document-multiple</v-icon>
            <div class="text-subtitle-1 font-weight-bold">Документы партнёра</div>
          </div>
          <v-row dense>
            <v-col v-for="slot in documentSlots" :key="slot.type" cols="12" sm="4">
              <v-card variant="outlined" class="pa-3">
                <div class="d-flex align-center ga-2 mb-2">
                  <span class="text-body-2 font-weight-medium">{{ slot.label }}</span>
                  <v-icon v-if="isDocUploaded(slot.type)" color="success" size="20">mdi-check-circle</v-icon>
                </div>
                <v-file-input
                  v-model="docFiles[slot.type]"
                  accept="image/*,.pdf"
                  density="compact"
                  variant="outlined"
                  :label="isDocUploaded(slot.type) ? 'Загружено' : 'Выберите файл'"
                  prepend-icon=""
                  prepend-inner-icon="mdi-paperclip"
                  hide-details
                />
                <v-btn
                  color="primary"
                  size="small"
                  variant="tonal"
                  class="mt-2"
                  :loading="docUploading[slot.type]"
                  :disabled="!docFiles[slot.type]"
                  prepend-icon="mdi-upload"
                  @click="uploadDocument(slot.type)"
                >
                  Загрузить
                </v-btn>
              </v-card>
            </v-col>
          </v-row>
          <v-alert v-if="docMsg" :type="docMsgType" density="compact" class="mt-3" closable @click:close="docMsg = ''">{{ docMsg }}</v-alert>
        </v-card>

        <!-- IP Requisites -->
        <v-card class="pa-4 mb-4">
          <div class="d-flex align-center ga-2 mb-3">
            <v-icon color="primary">mdi-domain</v-icon>
            <div class="text-subtitle-1 font-weight-bold">Реквизиты ИП</div>
            <v-chip v-if="profile.requisites?.verificationStatus" size="small"
              :color="verificationColor(profile.requisites.verificationStatus)">
              {{ verificationLabel(profile.requisites.verificationStatus) }}
            </v-chip>
          </div>
          <v-alert v-if="profile.requisites?.verificationStatus === 'verified'" type="warning" density="compact" class="mb-3" variant="tonal">
            <v-icon class="mr-1">mdi-alert</v-icon>
            Изменение реквизитов сбросит статус верификации
          </v-alert>
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field v-model="reqForm.individualEntrepreneur" label="Наименование ИП" />
            </v-col>
            <v-col cols="12" sm="3">
              <v-text-field v-model="reqForm.inn" label="ИНН" />
            </v-col>
            <v-col cols="12" sm="3">
              <v-text-field v-model="reqForm.ogrn" label="ОГРН/ОГРНИП" />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="reqForm.address" label="Юридический адрес" />
            </v-col>
            <v-col cols="12">
              <v-checkbox v-model="addressSameAsRegistration" label="Адрес регистрации совпадает с фактическим" density="compact" hide-details />
            </v-col>
            <v-col v-if="!addressSameAsRegistration" cols="12">
              <v-text-field v-model="reqForm.actualAddress" label="Фактический адрес" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="reqForm.email" label="Email для документов" type="email" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="reqForm.phone" label="Телефон ИП" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="savingReq" @click="saveRequisites" class="mt-2" prepend-icon="mdi-content-save">
            Сохранить реквизиты
          </v-btn>
          <v-alert v-if="reqMsg" :type="reqMsgType" density="compact" class="mt-3" closable @click:close="reqMsg = ''">{{ reqMsg }}</v-alert>
        </v-card>

        <!-- Bank Requisites -->
        <v-card class="pa-4">
          <div class="d-flex align-center ga-2 mb-3">
            <v-icon color="primary">mdi-bank</v-icon>
            <div class="text-subtitle-1 font-weight-bold">Банковские реквизиты</div>
            <v-chip v-if="profile.bankRequisites?.verificationStatus" size="small"
              :color="verificationColor(profile.bankRequisites.verificationStatus)">
              {{ verificationLabel(profile.bankRequisites.verificationStatus) }}
            </v-chip>
          </div>
          <v-alert v-if="profile.bankRequisites?.verificationStatus === 'verified'" type="warning" density="compact" class="mb-3" variant="tonal">
            <v-icon class="mr-1">mdi-alert</v-icon>
            Изменение банковских реквизитов сбросит статус верификации
          </v-alert>
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field v-model="bankForm.bankName" label="Наименование банка" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="bankForm.bankBik" label="БИК" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="bankForm.accountNumber" label="Расчётный счёт" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="bankForm.correspondentAccount" label="Корр. счёт" />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="bankForm.beneficiaryName" label="Наименование получателя" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="savingBank" @click="saveBankRequisites" class="mt-2" prepend-icon="mdi-content-save">
            Сохранить банковские реквизиты
          </v-btn>
          <v-alert v-if="bankMsg" :type="bankMsgType" density="compact" class="mt-3" closable @click:close="bankMsg = ''">{{ bankMsg }}</v-alert>
        </v-card>
      </v-tabs-window-item>

      <!-- Section 3: Referral Links -->
      <v-tabs-window-item value="referral">
        <v-card class="pa-4">
          <template v-if="profile.referral?.canInvite">
            <div class="d-flex align-center ga-2 mb-3">
              <v-icon color="primary">mdi-link-variant</v-icon>
              <div class="text-subtitle-1 font-weight-bold">Ваша реферальная ссылка</div>
            </div>
            <v-row dense>
              <v-col cols="12" sm="6">
                <v-text-field :model-value="profile.referral.referralCode" label="Реферальный код" readonly>
                  <template #append-inner>
                    <v-btn icon="mdi-content-copy" size="small" variant="text" @click="copyToClipboard(profile.referral.referralCode)" />
                  </template>
                </v-text-field>
              </v-col>
              <v-col cols="12">
                <v-text-field :model-value="profile.referral.referralLink" label="Ссылка для приглашения" readonly>
                  <template #append-inner>
                    <v-btn icon="mdi-content-copy" size="small" variant="text" @click="copyToClipboard(profile.referral.referralLink)" />
                  </template>
                </v-text-field>
              </v-col>
            </v-row>
            <v-alert v-if="copied" type="success" density="compact" class="mt-2">Скопировано в буфер обмена</v-alert>
          </template>
          <v-alert v-else type="info" variant="tonal">
            <v-icon class="mr-1">mdi-information</v-icon>
            Реферальные ссылки доступны только для партнёров со статусом "Активен".
          </v-alert>
        </v-card>
      </v-tabs-window-item>

      <!-- Section 4: Security (2FA + смена пароля) -->
      <v-tabs-window-item value="security">
        <v-card class="pa-4 mb-4">
          <div class="d-flex align-center ga-2 mb-3">
            <v-icon color="primary">mdi-shield-key</v-icon>
            <div class="text-subtitle-1 font-weight-bold">Двухфакторная аутентификация</div>
          </div>

          <template v-if="twoFa.enabled">
            <v-alert type="success" variant="tonal" density="compact" class="mb-3">
              <v-icon class="mr-1">mdi-check-circle</v-icon>
              2FA включён{{ twoFa.confirmedAt ? ' с ' + fmtDate(twoFa.confirmedAt) : '' }}.
              При входе будем спрашивать код из приложения.
            </v-alert>
            <v-text-field v-model="disablePassword" label="Текущий пароль для отключения"
              type="password" variant="outlined" density="compact" class="mb-2" />
            <v-btn color="error" variant="tonal" prepend-icon="mdi-shield-off"
              :loading="twoFaBusy" @click="disable2fa">Отключить 2FA</v-btn>
          </template>

          <template v-else-if="!twoFaSetup.uri">
            <div class="text-body-2 text-medium-emphasis mb-3">
              Защитите аккаунт одноразовыми кодами из Google Authenticator,
              Authy или 1Password. Без 2FA доступ к аккаунту только по паролю.
            </div>
            <v-btn color="primary" prepend-icon="mdi-shield-plus" :loading="twoFaBusy"
              @click="start2fa">Включить 2FA</v-btn>
          </template>

          <template v-else>
            <div class="text-body-2 mb-3">
              1. Откройте Google Authenticator (или совместимое приложение).<br/>
              2. Отсканируйте QR-код ниже либо введите секрет вручную.<br/>
              3. Введите 6-значный код из приложения для подтверждения.
            </div>
            <div class="d-flex ga-4 align-start flex-wrap">
              <img :src="qrCodeUrl" alt="QR-код 2FA" width="180" height="180" class="qr-img" />
              <div class="flex-grow-1 min-w-0">
                <div class="text-caption text-medium-emphasis mb-1">Секрет (если нет камеры)</div>
                <v-text-field :model-value="twoFaSetup.secret" readonly variant="outlined"
                  density="compact" prepend-inner-icon="mdi-key" class="mb-2"
                  @click="copyToClipboard(twoFaSetup.secret)" />
                <v-text-field v-model="totpConfirm" label="Код из приложения"
                  variant="outlined" density="compact" maxlength="6" inputmode="numeric" />
                <v-btn block color="primary" :loading="twoFaBusy" class="mt-2"
                  @click="confirm2fa">Подтвердить и включить</v-btn>
              </div>
            </div>
          </template>
        </v-card>

        <!-- Telegram-уведомления -->
        <v-card v-if="telegram.enabled" class="pa-4">
          <div class="d-flex align-center ga-2 mb-3">
            <v-icon color="primary">mdi-send</v-icon>
            <div class="text-subtitle-1 font-weight-bold">Telegram-уведомления</div>
          </div>
          <template v-if="telegram.linked">
            <v-alert type="success" variant="tonal" density="compact" class="mb-3">
              <v-icon class="mr-1">mdi-check-circle</v-icon>
              Привязан chat_id <code>{{ telegram.chat_id }}</code>.
              Сюда будут приходить критические уведомления.
            </v-alert>
            <v-btn variant="tonal" prepend-icon="mdi-send-check"
              :loading="telegramBusy" @click="sendTestTelegram" class="me-2">
              Отправить тест
            </v-btn>
            <v-btn variant="tonal" color="error" prepend-icon="mdi-link-off"
              :loading="telegramBusy" @click="unlinkTelegram">
              Отвязать
            </v-btn>
          </template>
          <template v-else>
            <div class="text-body-2 text-medium-emphasis mb-3">
              1. Откройте бота
              <a v-if="telegram.bot_username"
                :href="`https://t.me/${telegram.bot_username}`" target="_blank">
                @{{ telegram.bot_username }}
              </a>
              <span v-else>(имя бота не настроено в .env)</span>,
              нажмите Start.<br/>
              2. Напишите боту <code>@userinfobot</code> чтобы узнать свой chat_id (Number).<br/>
              3. Вставьте chat_id ниже и нажмите «Привязать».
            </div>
            <v-row dense>
              <v-col cols="12" sm="8">
                <v-text-field v-model="telegramChatId" label="Ваш Telegram chat_id"
                  prepend-inner-icon="mdi-pound" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" sm="4">
                <v-btn block color="primary" prepend-icon="mdi-link"
                  :loading="telegramBusy" @click="linkTelegram">Привязать</v-btn>
              </v-col>
            </v-row>
          </template>
        </v-card>
      </v-tabs-window-item>
    </v-tabs-window>

    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position:fixed;top:0;left:0;right:0;z-index:2000" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';
import { useSnackbar } from '../composables/useSnackbar';

const { showError, showSuccess } = useSnackbar();

const auth = useAuthStore();
const isEmployee = computed(() => auth.isStaff && !auth.isConsultant);
const canInvite = computed(() => profile.value?.referral?.canInvite ?? false);

const loading = ref(true);
const tab = ref('info');
const profile = ref({});
const saving = ref(false);
const saveMsg = ref('');
const saveMsgType = ref('success');
const savingPwd = ref(false);
const pwdMsg = ref('');
const pwdMsgType = ref('success');
const savingReq = ref(false);
const reqMsg = ref('');
const reqMsgType = ref('success');
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
  { label: 'Заявление на получение выплат', type: 'applicationForPayment' },
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

const countryOptions = ['Россия', 'Казахстан', 'Беларусь', 'Узбекистан', 'Кыргызстан', 'Таджикистан', 'Армения', 'Грузия', 'Азербайджан', 'Молдова', 'Украина', 'Турция', 'ОАЭ', 'Германия', 'Израиль', 'США', 'Другая'];
const cityOptions = ref([]);

function activityColor(id) {
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'error';     // Терминирован — per spec ✅Статусы партнеров §2 col.2
  if (id === 5) return 'error';     // Исключен
  return 'grey';
}

function fmtShortDate(d) {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date.getTime())) return '';
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function loadCities() {
  try {
    const { data } = await api.get('/profile/cities');
    cityOptions.value = Array.isArray(data) ? data : [];
  } catch {
    cityOptions.value = ['Москва', 'Санкт-Петербург', 'Краснодар', 'Казань', 'Новосибирск', 'Екатеринбург', 'Нижний Новгород', 'Ростов-на-Дону', 'Самара', 'Уфа', 'Красноярск', 'Воронеж', 'Пермь', 'Волгоград'];
  }
}

const form = ref({ firstName: '', lastName: '', patronymic: '', position: '', phone: '', telegram: '', gender: '', birthDate: '', email: '', country: '', city: '' });
const pwd = ref({ current_password: '', password: '', password_confirmation: '' });
const reqForm = ref({ individualEntrepreneur: '', inn: '', ogrn: '', address: '', email: '', phone: '' });
const bankForm = ref({ bankName: '', bankBik: '', accountNumber: '', correspondentAccount: '', beneficiaryName: '' });

const initials = computed(() => {
  const u = profile.value.user;
  return `${u?.firstName?.[0] || ''}${u?.lastName?.[0] || ''}`.toUpperCase();
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
    const r = data.requisites || {};
    reqForm.value = {
      individualEntrepreneur: r.individualEntrepreneur || '', inn: r.inn || '',
      ogrn: r.ogrn || '', address: r.address || '', actualAddress: r.actualAddress || '',
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

async function saveRequisites() {
  savingReq.value = true;
  reqMsg.value = '';
  try {
    await api.put('/profile/requisites', reqForm.value);
    reqMsg.value = 'Реквизиты сохранены';
    reqMsgType.value = 'success';
    loadProfile();
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

onMounted(() => {
  loadProfile();
  loadDocuments();
  loadCities();
  load2faStatus();
  loadTelegram();
});

// === Telegram ===
const telegram = ref({ enabled: false, linked: false, chat_id: null, bot_username: null });
const telegramChatId = ref('');
const telegramBusy = ref(false);

async function loadTelegram() {
  try {
    const { data } = await api.get('/telegram/status');
    telegram.value = data;
  } catch {}
}
async function linkTelegram() {
  if (!telegramChatId.value.trim()) { showError('Введите chat_id'); return; }
  telegramBusy.value = true;
  try {
    const { data } = await api.post('/telegram/link', { chat_id: telegramChatId.value.trim() });
    if (data.test_ok) showSuccess(data.message);
    else showError(data.message);
    telegramChatId.value = '';
    await loadTelegram();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  telegramBusy.value = false;
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
    else showError('Не удалось отправить — проверьте chat_id и что бот настроен');
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  telegramBusy.value = false;
}

// === 2FA ===
const twoFa = ref({ enabled: false, confirmedAt: null });
const twoFaSetup = ref({ uri: '', secret: '' });
const totpConfirm = ref('');
const disablePassword = ref('');
const twoFaBusy = ref(false);

// QR-код через goqr.me (public API, без аутентификации). Альтернатива —
// генерировать локально через qrcode.js, но это +bundle-вес ради
// одной фичи в профиле.
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
.qr-img {
  border-radius: 8px;
  background: white;
  padding: 8px;
}
</style>
