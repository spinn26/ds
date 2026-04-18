<template>
  <div>
    <BrandHero
      title="Профиль"
      subtitle="Личные данные, реквизиты для выплат и реферальная ссылка"
      icon="mdi-account"
    />

    <v-tabs v-model="tab" color="primary" class="mb-4" grow>
      <v-tab value="info">Информация о партнере</v-tab>
      <v-tab value="requisites">Реквизиты и документы для выплат</v-tab>
      <v-tab value="referral">Реферальные ссылки</v-tab>
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
              <v-text-field v-model="form.lastName" label="Фамилия" disabled prepend-inner-icon="mdi-lock" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.firstName" label="Имя" disabled prepend-inner-icon="mdi-lock" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.patronymic" label="Отчество" disabled prepend-inner-icon="mdi-lock" />
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

        <!-- Signed Documents -->
        <v-card v-if="profile.signedDocuments?.length" class="pa-4 mb-4">
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
    </v-tabs-window>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import BrandHero from '../components/BrandHero.vue';

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
  if (id === 1) return 'success';
  if (id === 4) return 'info';
  if (id === 3) return 'warning';
  if (id === 5) return 'error';
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

const form = ref({ phone: '', telegram: '', gender: '', birthDate: '', email: '', country: '', city: '' });
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
      firstName: u.firstName, lastName: u.lastName, patronymic: u.patronymic,
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
    await api.put('/profile', {
      phone: form.value.phone, telegram: form.value.telegram,
      gender: form.value.gender, birthDate: form.value.birthDate,
      email: form.value.email, country: form.value.country, city: form.value.city,
    });
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
});
</script>
