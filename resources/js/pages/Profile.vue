<template>
  <div>
    <h5 class="text-h5 font-weight-bold mb-4">Профиль</h5>

    <v-tabs v-model="tab" color="primary" class="mb-4">
      <v-tab value="info">Информация</v-tab>
      <v-tab value="requisites">Реквизиты</v-tab>
      <v-tab value="referral">Реферальные ссылки</v-tab>
    </v-tabs>

    <v-tabs-window v-model="tab">
      <!-- Tab 1: Info -->
      <v-tabs-window-item value="info">
        <v-card class="pa-4 mb-4">
          <div class="d-flex align-center ga-4 mb-4">
            <v-avatar color="primary" size="64">
              <span class="text-h5 text-white">{{ initials }}</span>
            </v-avatar>
            <div>
              <div class="text-h6">{{ profile.user?.lastName }} {{ profile.user?.firstName }} {{ profile.user?.patronymic }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ profile.user?.email }}</div>
            </div>
          </div>

          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.lastName" label="Фамилия" disabled />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.firstName" label="Имя" disabled />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.patronymic" label="Отчество" disabled />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.phone" label="Телефон" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.telegram" label="Telegram" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="form.gender" label="Пол" :items="['Мужской', 'Женский']" clearable />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.birthDate" label="Дата рождения" type="date" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="saving" @click="saveProfile" class="mt-2">Сохранить</v-btn>
          <v-alert v-if="saveMsg" :type="saveMsgType" density="compact" class="mt-3" closable @click:close="saveMsg = ''">{{ saveMsg }}</v-alert>
        </v-card>

        <!-- Signed Documents -->
        <v-card v-if="profile.signedDocuments?.length" class="pa-4 mb-4">
          <div class="text-subtitle-1 font-weight-bold mb-2">Подписанные документы</div>
          <v-list density="compact">
            <v-list-item v-for="doc in profile.signedDocuments" :key="doc.id" :title="doc.name"
              :subtitle="doc.signedAt" prepend-icon="mdi-file-check" />
          </v-list>
        </v-card>

        <!-- Password Change -->
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-2">Смена пароля</div>
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field v-model="pwd.current_password" label="Текущий пароль" type="password" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="pwd.password" label="Новый пароль" type="password" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="pwd.password_confirmation" label="Подтверждение" type="password" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="savingPwd" @click="changePassword" class="mt-2">Сменить пароль</v-btn>
          <v-alert v-if="pwdMsg" :type="pwdMsgType" density="compact" class="mt-3" closable @click:close="pwdMsg = ''">{{ pwdMsg }}</v-alert>
        </v-card>
      </v-tabs-window-item>

      <!-- Tab 2: Requisites -->
      <v-tabs-window-item value="requisites">
        <!-- IP Requisites -->
        <v-card class="pa-4 mb-4">
          <div class="d-flex align-center ga-2 mb-3">
            <div class="text-subtitle-1 font-weight-bold">Реквизиты ИП</div>
            <v-chip v-if="profile.requisites?.verificationStatus" size="small"
              :color="profile.requisites.verificationStatus === 'verified' ? 'success' : profile.requisites.verificationStatus === 'rejected' ? 'error' : 'warning'">
              {{ profile.requisites.verificationStatus === 'verified' ? 'Подтверждено' : profile.requisites.verificationStatus === 'rejected' ? 'Отклонено' : 'На проверке' }}
            </v-chip>
          </div>
          <v-alert v-if="profile.requisites?.verificationStatus === 'verified'" type="warning" density="compact" class="mb-3">
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
            <v-col cols="12" sm="6">
              <v-text-field v-model="reqForm.email" label="Email для документов" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="reqForm.phone" label="Телефон ИП" />
            </v-col>
          </v-row>
          <v-btn color="primary" :loading="savingReq" @click="saveRequisites" class="mt-2">Сохранить реквизиты</v-btn>
          <v-alert v-if="reqMsg" :type="reqMsgType" density="compact" class="mt-3" closable @click:close="reqMsg = ''">{{ reqMsg }}</v-alert>
        </v-card>

        <!-- Bank Requisites -->
        <v-card class="pa-4">
          <div class="d-flex align-center ga-2 mb-3">
            <div class="text-subtitle-1 font-weight-bold">Банковские реквизиты</div>
            <v-chip v-if="profile.bankRequisites?.verificationStatus" size="small"
              :color="profile.bankRequisites.verificationStatus === 'verified' ? 'success' : profile.bankRequisites.verificationStatus === 'rejected' ? 'error' : 'warning'">
              {{ profile.bankRequisites.verificationStatus === 'verified' ? 'Подтверждено' : profile.bankRequisites.verificationStatus === 'rejected' ? 'Отклонено' : 'На проверке' }}
            </v-chip>
          </div>
          <v-alert v-if="profile.bankRequisites?.verificationStatus === 'verified'" type="warning" density="compact" class="mb-3">
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
          <v-btn color="primary" :loading="savingBank" @click="saveBankRequisites" class="mt-2">Сохранить банковские реквизиты</v-btn>
          <v-alert v-if="bankMsg" :type="bankMsgType" density="compact" class="mt-3" closable @click:close="bankMsg = ''">{{ bankMsg }}</v-alert>
        </v-card>
      </v-tabs-window-item>

      <!-- Tab 3: Referral -->
      <v-tabs-window-item value="referral">
        <v-card class="pa-4">
          <template v-if="profile.referral?.canInvite">
            <div class="text-subtitle-1 font-weight-bold mb-3">Ваша реферальная ссылка</div>
            <v-row dense>
              <v-col cols="12" sm="6">
                <v-text-field :model-value="profile.referral.code" label="Реферальный код" readonly>
                  <template #append-inner>
                    <v-btn icon="mdi-content-copy" size="small" variant="text" @click="copyToClipboard(profile.referral.code)" />
                  </template>
                </v-text-field>
              </v-col>
              <v-col cols="12">
                <v-text-field :model-value="profile.referral.link" label="Ссылка для приглашения" readonly>
                  <template #append-inner>
                    <v-btn icon="mdi-content-copy" size="small" variant="text" @click="copyToClipboard(profile.referral.link)" />
                  </template>
                </v-text-field>
              </v-col>
            </v-row>
            <v-alert v-if="copied" type="success" density="compact" class="mt-2">Скопировано в буфер обмена</v-alert>
          </template>
          <v-alert v-else type="info" variant="tonal">
            Функция приглашений станет доступна после активации вашего аккаунта.
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

const form = ref({ phone: '', telegram: '', gender: '', birthDate: '' });
const pwd = ref({ current_password: '', password: '', password_confirmation: '' });
const reqForm = ref({ individualEntrepreneur: '', inn: '', ogrn: '', address: '', email: '', phone: '' });
const bankForm = ref({ bankName: '', bankBik: '', accountNumber: '', correspondentAccount: '', beneficiaryName: '' });

const initials = computed(() => {
  const u = profile.value.user;
  return `${u?.firstName?.[0] || ''}${u?.lastName?.[0] || ''}`.toUpperCase();
});

async function loadProfile() {
  loading.value = true;
  try {
    const { data } = await api.get('/profile');
    profile.value = data;
    const u = data.user || {};
    form.value = { phone: u.phone || '', telegram: u.telegram || '', gender: u.gender || '', birthDate: u.birthDate ? u.birthDate.split('T')[0] : '', firstName: u.firstName, lastName: u.lastName, patronymic: u.patronymic };
    const r = data.requisites || {};
    reqForm.value = { individualEntrepreneur: r.individualEntrepreneur || '', inn: r.inn || '', ogrn: r.ogrn || '', address: r.address || '', email: r.email || '', phone: r.phone || '' };
    const b = data.bankRequisites || {};
    bankForm.value = { bankName: b.bankName || '', bankBik: b.bankBik || '', accountNumber: b.accountNumber || '', correspondentAccount: b.correspondentAccount || '', beneficiaryName: b.beneficiaryName || '' };
  } catch {}
  loading.value = false;
}

async function saveProfile() {
  saving.value = true;
  saveMsg.value = '';
  try {
    await api.put('/profile', { phone: form.value.phone, telegram: form.value.telegram, gender: form.value.gender, birthDate: form.value.birthDate });
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

onMounted(loadProfile);
</script>
