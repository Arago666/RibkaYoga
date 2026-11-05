<script setup lang="ts">
import PageHeading from '@/shared/PageHeading.vue';
import { onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Bell, HardDrive, ShieldCheck, SlidersHorizontal } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import { uiPreferencesKey, readUiPreferences } from '@/shared/uiPreferences';
const page = usePage(),
  key = uiPreferencesKey(page.props.auth);
const values = ref(readUiPreferences(key)),
  notice = ref(''),
  error = ref('');
const notificationSupported = 'Notification' in window && window.isSecureContext;
const permission = ref(notificationSupported ? Notification.permission : 'unavailable');
const estimate = ref<{ usage?: number; quota?: number }>({});
const bytes = (value?: number) =>
  value === undefined ? 'недоступно' : `${(value / 1024 / 1024).toFixed(1)} МБ`;
function save() {
  try {
    localStorage.setItem(key, JSON.stringify(values.value));
    notice.value = 'Настройки сохранены в этом браузере для вашего аккаунта.';
    error.value = '';
  } catch {
    error.value = 'Браузер не разрешил сохранить настройки.';
  }
}
async function notifications(event: Event) {
  const enabled = (event.target as HTMLInputElement).checked;
  if (!enabled) {
    values.value.meditationNotifications = false;
    save();
    return;
  }
  if (!notificationSupported) return;
  permission.value = await Notification.requestPermission();
  values.value.meditationNotifications = permission.value === 'granted';
  save();
  if (permission.value !== 'granted')
    error.value = 'Уведомления заблокированы. Разрешение можно изменить в настройках браузера.';
}
function clearViewPreferences() {
  if (
    !window.confirm(
      'Сбросить настройки вида и запомненные подборки этого аккаунта в этом браузере? Рисунки, черновики и документы сохранятся.',
    )
  )
    return;
  try {
    const auth = page.props.auth as { user?: { id: number } };
    const palettePrefix = `ribka:palette:${auth.user?.id}:`;
    for (const item of Object.keys(localStorage))
      if (item === key || item.startsWith(palettePrefix)) localStorage.removeItem(item);
    values.value = readUiPreferences(key);
    notice.value = 'Настройки вида сброшены. Рисунки и документы сохранены.';
  } catch {
    error.value = 'Не удалось сбросить настройки браузера.';
  }
}
onMounted(async () => {
  try {
    estimate.value = (await navigator.storage?.estimate?.()) || {};
  } catch {
    /* Browser storage estimates are optional. */
  }
});
</script>
<template>
  <Head title="Настройки" />
  <AppShell section="account">
    <PageHeading
      eyebrow="Всё по-вашему"
      title="Настройки"
      description="Отображение, уведомления, конфиденциальность и память."
    >
      <Link class="btn btn-secondary" href="/library">В библиотеку</Link>
    </PageHeading>
    <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div class="settings-grid">
      <section class="card settings-section">
        <h2>
          <span class="section-icon"><SlidersHorizontal aria-hidden="true" /></span>Отображение
        </h2>
        <p class="muted">В библиотеке и практиках используются ваши исходные изображения асан.</p>
        <Link href="/library" class="btn btn-primary">Открыть библиотеку</Link>
        <label class="settings-check"
          ><input v-model="values.rememberFilters" type="checkbox" @change="save" />Запоминать выбранную
          подборку при заполнении ячеек</label
        >
      </section>
      <div class="settings-column">
        <section class="card settings-section">
          <h2>
            <span class="section-icon"><Bell aria-hidden="true" /></span>Уведомления
          </h2>
          <label class="settings-check"
            ><input
              :checked="values.meditationNotifications"
              type="checkbox"
              :disabled="!notificationSupported"
              @change="notifications"
            />Уведомлять о завершении медитации</label
          >
          <p v-if="!notificationSupported" class="muted">
            Этот браузер не предоставляет уведомления на текущем HTTP-адресе. Завершение сессии показывается в
            приложении и сопровождается выбранным звуком.
          </p>
          <p v-else class="muted">
            Разрешение браузера:
            {{
              permission === 'granted'
                ? 'разрешены'
                : permission === 'denied'
                  ? 'заблокированы'
                  : 'ещё не запрошено'
            }}. Напоминания по расписанию не включены.
          </p>
        </section>
        <section class="card settings-section">
          <h2>
            <span class="section-icon"><ShieldCheck aria-hidden="true" /></span>Конфиденциальность
          </h2>
          <p class="muted">
            Документы и изображения доступны в вашем рабочем пространстве. Доступ преподавателям и публикации
            по ссылке настраиваются для каждой практики отдельно.
          </p>
          <Link href="/practices" class="btn btn-secondary">Управлять доступом к практикам</Link
          ><Link href="/account" class="btn btn-ghost">Профиль и пароль</Link>
        </section>
        <section class="card settings-section">
          <h2>
            <span class="section-icon"><HardDrive aria-hidden="true" /></span>Память браузера
          </h2>
          <p class="muted">
            Использовано этим сайтом: {{ bytes(estimate.usage) }}. Доступная квота:
            {{ bytes(estimate.quota) }}. Это оценка браузера, не объём серверных документов.
          </p>
          <button class="btn btn-secondary" @click="clearViewPreferences">Сбросить настройки вида</button>
          <p class="muted">Черновики рисунков, исходники, практики и файлы на сервере не удаляются.</p>
        </section>
      </div>
    </div>
  </AppShell>
</template>
<style scoped>
.settings-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: var(--section-gap);
}
.settings-column {
  display: grid;
  gap: var(--section-gap);
  align-content: start;
}
.settings-section {
  padding: var(--panel-padding);
  display: grid;
  align-content: start;
  gap: 18px;
  min-width: 0;
}
.settings-section h2 {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 19px;
  font-weight: 650;
  letter-spacing: -0.025em;
  margin: 0;
}
.settings-section .btn {
  width: fit-content;
}
.settings-check {
  display: flex;
  align-items: center;
  gap: 10px;
  line-height: 1.5;
}
@media (max-width: 850px) {
  .settings-grid {
    grid-template-columns: 1fr;
  }
}
</style>
